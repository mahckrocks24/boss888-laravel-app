<?php

namespace App\Engines\Builder\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * BookingService — public booking form submission + list.
 * Uses the DB facade only (no Eloquent models required).
 */
class BookingService
{
    /**
     * Store a submission from a published site's booking form.
     * Validates minimally — no auth.
     *
     * @return array{ok: bool, id?: int, error?: string}
     */
    public function store(int $websiteId, array $data): array
    {
        if ($websiteId <= 0) {
            return ['ok' => false, 'error' => 'invalid website'];
        }
        $website = DB::table('websites')->where('id', $websiteId)->whereNull('deleted_at')->first();
        if (!$website) {
            return ['ok' => false, 'error' => 'website not found'];
        }

        $name = trim((string)($data['name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        if ($name === '' && $email === '' && $phone === '') {
            return ['ok' => false, 'error' => 'name, email, or phone is required'];
        }

        $date = null;
        if (!empty($data['preferred_date'])) {
            $ts = strtotime((string)$data['preferred_date']);
            if ($ts !== false) $date = date('Y-m-d', $ts);
        }

        $meta = [
            'party_size'      => $data['party_size']      ?? null,
            'class_type'      => $data['class_type']      ?? null,
            'doctor'          => $data['doctor']          ?? null,
            'practice_area'   => $data['practice_area']   ?? null,
            'property_interest' => $data['property_interest'] ?? null,
            'stylist'         => $data['stylist']         ?? null,
            'company'         => $data['company']         ?? null,
            'role'            => $data['role']            ?? null,
            'event_type'      => $data['event_type']      ?? null,
            'guests'          => $data['guests']          ?? null,
            'brief'           => $data['brief']           ?? null,
        ];
        $meta = array_filter($meta, fn($v) => $v !== null && $v !== '');

        try {
            $id = DB::table('booking_submissions')->insertGetId([
                'website_id'     => $websiteId,
                'name'           => $name !== '' ? mb_substr($name, 0, 150) : null,
                'email'          => $email !== '' ? mb_substr($email, 0, 255) : null,
                'phone'          => $phone !== '' ? mb_substr($phone, 0, 50) : null,
                'service'        => !empty($data['service']) ? mb_substr((string)$data['service'], 0, 150) : null,
                'preferred_date' => $date,
                'preferred_time' => !empty($data['preferred_time']) ? mb_substr((string)$data['preferred_time'], 0, 50) : null,
                'notes'          => !empty($data['notes']) ? (string)$data['notes'] : null,
                'status'         => 'new',
                'meta_json'      => $meta ? json_encode($meta) : null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            Log::info('[Booking] submission stored', [
                'id' => $id, 'website_id' => $websiteId,
                'name' => $name, 'email' => $email,
            ]);

            // Send notification emails — wrapped in try/catch so email failure
            // never breaks the booking submission. (Customer already got "thanks"
            // UI-side; notification is best-effort.)
            try {
                $this->sendBookingEmails($website, [
                    'name'            => $name,
                    'email'           => $email,
                    'phone'           => $phone,
                    'service'         => $data['service']         ?? null,
                    'preferred_date'  => $date,
                    'preferred_time'  => $data['preferred_time']  ?? null,
                    'message'         => $data['notes']           ?? ($data['message'] ?? null),
                    'submitted_at'    => now()->toDateTimeString(),
                ]);
            } catch (\Throwable $mailErr) {
                Log::warning('[Booking] email dispatch failed: ' . $mailErr->getMessage(), ['booking_id' => $id]);
            }

            return ['ok' => true, 'id' => $id];
        } catch (\Throwable $e) {
            Log::warning('[Booking] store failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'submission failed'];
        }
    }

    /**
     * List submissions for a website.
     */
    public function list(int $websiteId, int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $total = DB::table('booking_submissions')->where('website_id', $websiteId)->count();
        $rows = DB::table('booking_submissions')
            ->where('website_id', $websiteId)
            ->orderByDesc('created_at')
            ->limit($perPage)->offset($offset)
            ->get();

        return [
            'submissions' => $rows,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
        ];
    }

    /**
     * Send booking notification + customer confirmation emails.
     * TODO: if MAIL_MAILER is not configured in .env, emails are logged-only.
     */
    private function sendBookingEmails(object $website, array $booking): void
    {
        $vars = [];
        if (!empty($website->template_variables)) {
            $decoded = json_decode($website->template_variables, true);
            if (is_array($decoded)) $vars = $decoded;
        }
        $businessName  = $vars['business_name']  ?? ($website->name ?? 'Your Website');
        $contactEmail  = $vars['contact_email']  ?? null;
        $mailerOk      = !empty(config('mail.default')) && config('mail.default') !== 'array';

        $name    = $booking['name']            ?: 'Anonymous visitor';
        $email   = $booking['email']           ?: '';
        $phone   = $booking['phone']           ?: '';
        $service = $booking['service']         ?: '';
        $date    = $booking['preferred_date']  ?: '';
        $time    = $booking['preferred_time']  ?: '';
        $msg     = $booking['message']         ?: '';
        $stamp   = $booking['submitted_at']    ?: now()->toDateTimeString();

        // ── 1) Notification email to business owner ──────────────────
        if (!empty($contactEmail) && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $ownerLines = [
                "You have a new booking request from your website.",
                "",
                "Name: {$name}",
                "Email: {$email}",
                "Phone: {$phone}",
            ];
            if ($service !== '') $ownerLines[] = "Service: {$service}";
            $ownerLines[] = "Date: {$date}";
            $ownerLines[] = "Time: {$time}";
            if ($msg !== '')     $ownerLines[] = "Message: {$msg}";
            $ownerLines[] = "";
            $ownerLines[] = "Submitted: {$stamp}";
            $ownerLines[] = "Website: {$businessName}";
            $ownerLines[] = "";
            $ownerLines[] = "Reply directly to this email to respond to the customer.";
            $ownerBody = implode("\n", $ownerLines);
            $ownerSubject = "New Booking Request — {$businessName}";

            if ($mailerOk) {
                try {
                    Mail::raw($ownerBody, function ($m) use ($contactEmail, $ownerSubject, $email, $name) {
                        $m->to($contactEmail)->subject($ownerSubject);
                        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $m->replyTo($email, $name !== '' ? $name : null);
                        }
                    });
                } catch (\Throwable $e) {
                    Log::warning('[Booking] owner email failed: ' . $e->getMessage());
                }
            } else {
                Log::info('[Booking] OWNER EMAIL (mailer not configured — logged only)', [
                    'to' => $contactEmail, 'subject' => $ownerSubject, 'body' => $ownerBody,
                ]);
            }
        } else {
            Log::info('[Booking] no contact_email on website; skipping owner notification', [
                'website_id' => $website->id ?? null,
            ]);
        }

        // ── 2) Confirmation email to customer ────────────────────────
        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $custLines = [
                "Hi " . ($name ?: 'there') . ",",
                "",
                "Thank you for your booking request. We have received your inquiry and will be in touch shortly to confirm your appointment.",
                "",
                "Your request details:",
            ];
            if ($service !== '') $custLines[] = "Service: {$service}";
            $custLines[] = "Preferred Date: {$date}";
            $custLines[] = "Preferred Time: {$time}";
            $custLines[] = "";
            $custLines[] = $businessName;
            if (!empty($contactEmail)) $custLines[] = $contactEmail;
            $custBody = implode("\n", $custLines);
            $custSubject = "Your booking request has been received — {$businessName}";

            if ($mailerOk) {
                try {
                    Mail::raw($custBody, function ($m) use ($email, $name, $custSubject, $contactEmail, $businessName) {
                        $m->to($email, $name !== '' ? $name : null)->subject($custSubject);
                        if (!empty($contactEmail) && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                            $m->replyTo($contactEmail, $businessName);
                        }
                    });
                } catch (\Throwable $e) {
                    Log::warning('[Booking] customer email failed: ' . $e->getMessage());
                }
            } else {
                Log::info('[Booking] CUSTOMER EMAIL (mailer not configured — logged only)', [
                    'to' => $email, 'subject' => $custSubject, 'body' => $custBody,
                ]);
            }
        }
    }
}