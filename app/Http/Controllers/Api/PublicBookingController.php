<?php

namespace App\Http\Controllers\Api;

use App\Core\Notifications\NotificationService;
use App\Core\Notifications\NotificationTypes;
use App\Engines\Calendar\Services\CalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LEAD-1 (2026-08-29, EV-0870) — public booking / reservation / quote-request form endpoint.
 *
 * 12 website templates shipped booking forms whose submit handler was
 * `onsubmit="event.preventDefault();alert('Your table is reserved! We will email confirmation
 * shortly.')"` — a fabricated success shown to the customer's visitors: no lead, no booking, no
 * email, nothing for the owner to act on. This endpoint gives those forms (and any template form
 * that carries `data-lu-form="booking"`) a real destination:
 *
 *   visitor submits → CRM lead (website_form, de-duped by email, bound to the website) →
 *   calendar_events row (category booking_pending, reference Lead) → booking_submissions row →
 *   owner notification → truthful reply ("request received — the business will confirm").
 *
 * The website is resolved from Origin / Referer / Host, exactly as PublicContactController does.
 * No auth (visitors), throttled per IP, honeypot-protected.
 */
class PublicBookingController
{
    public function submitByHost(Request $request): JsonResponse
    {
        $candidates = [];
        foreach ([$request->header('Origin'), $request->header('Referer')] as $url) {
            if (! $url) continue;
            $h = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($h !== '') $candidates[] = preg_replace('/^www\./', '', $h);
        }
        $host = strtolower((string) $request->header('Host'));
        if ($host !== '' && ! in_array($host, ['staging.levelupgrowth.io', 'levelupgrowth.io'], true)) {
            $candidates[] = preg_replace('/^www\./', '', $host);
        }
        $candidates = array_values(array_unique(array_filter($candidates)));
        if (! $candidates) {
            return response()->json(['success' => false, 'message' => 'We could not tell which website this came from.'], 400);
        }

        $website = null;
        foreach ($candidates as $h) {
            $website = DB::table('websites')->whereNull('deleted_at')
                ->where(function ($q) use ($h) {
                    $q->where('custom_domain', $h)->orWhere('custom_domain', 'www.' . $h)
                      ->orWhere('subdomain', $h)->orWhere('subdomain', explode('.', $h)[0]);
                })
                ->where('status', 'published')
                ->first();
            if ($website) break;
        }
        if (! $website) {
            return response()->json(['success' => false, 'message' => 'This website is not accepting requests right now.'], 404);
        }

        if (trim((string) $request->input('hp', '')) !== '') {
            // Honeypot filled by a bot — pretend success, store nothing.
            return response()->json(['success' => true, 'message' => 'Request received.']);
        }

        $data = $request->validate([
            'name'           => 'nullable|string|max:255',
            'email'          => 'nullable|email|max:255',
            'phone'          => 'nullable|string|max:50',
            'preferred_date' => 'nullable|string|max:32',
            'preferred_time' => 'nullable|string|max:32',
            'service'        => 'nullable|string|max:160',
            'party_size'     => 'nullable|string|max:32',
            'notes'          => 'nullable|string|max:2000',
            'form'           => 'nullable|string|max:80',
            'extra'          => 'nullable|array',
        ]);
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phone = trim((string) ($data['phone'] ?? ''));
        $name  = trim((string) ($data['name'] ?? ''));
        if ($email === '' && $phone === '') {
            return response()->json(['success' => false, 'message' => 'Please leave an email address or phone number so we can confirm.'], 422);
        }
        if ($name === '') $name = $email !== '' ? explode('@', $email)[0] : 'Website visitor';

        $wsId = (int) $website->workspace_id;
        $kind = str_contains(strtolower((string) ($data['form'] ?? '')), 'quote') ? 'quote' : 'booking';

        // ── Lead (de-duped by email within the workspace) ───────────────────────────────────
        $lead = $email !== ''
            ? DB::table('leads')->where('workspace_id', $wsId)->where('email', $email)->whereNull('deleted_at')->first()
            : null;
        $meta = [
            'source'         => 'website_form',
            'form'           => $data['form'] ?? 'booking',
            'website_id'     => (int) $website->id,
            'preferred_date' => $data['preferred_date'] ?? null,
            'preferred_time' => $data['preferred_time'] ?? null,
            'service'        => $data['service'] ?? null,
            'party_size'     => $data['party_size'] ?? null,
            'notes'          => $data['notes'] ?? null,
            'extra'          => $data['extra'] ?? null,
            'submitted_at'   => now()->toIso8601String(),
        ];
        if ($lead) {
            DB::table('leads')->where('id', $lead->id)->update([
                'phone'         => $phone !== '' ? $phone : $lead->phone,
                'website_id'    => $lead->website_id ?: (int) $website->id,
                'metadata_json' => json_encode(array_merge((array) json_decode((string) $lead->metadata_json, true), ['last_booking_request' => $meta])),
                'updated_at'    => now(),
            ]);
            $leadId = (int) $lead->id;
        } else {
            $leadId = (int) DB::table('leads')->insertGetId([
                'workspace_id'  => $wsId,
                'website_id'    => (int) $website->id,
                'name'          => $name,
                'email'         => $email !== '' ? $email : null,
                'phone'         => $phone !== '' ? $phone : null,
                'source'        => 'website_form',
                'status'        => 'new',
                'score'         => 0,
                'deal_value'    => 0,
                'metadata_json' => json_encode($meta),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // ── Calendar: the request as a pending booking the owner confirms or declines ───────
        $startsAt = $this->parseWhen($data['preferred_date'] ?? null, $data['preferred_time'] ?? null, $wsId);
        $eventId = null;
        try {
            $desc = "From the website " . $kind . " form on " . ($website->custom_domain ?: $website->subdomain) . "\n"
                  . ($email !== '' ? "Email: {$email}\n" : '') . ($phone !== '' ? "Phone: {$phone}\n" : '')
                  . (! empty($data['service']) ? "Service: {$data['service']}\n" : '')
                  . (! empty($data['party_size']) ? "Party size: {$data['party_size']}\n" : '')
                  . (! empty($data['notes']) ? "Notes: {$data['notes']}\n" : '')
                  . "Status: PENDING — awaiting owner confirmation";
            $eventId = app(CalendarService::class)->createEvent($wsId, [
                'title'          => ($kind === 'quote' ? 'Quote request — ' : 'Booking request — ') . $name,
                'description'    => $desc,
                'category'       => 'booking_pending',
                'engine'         => 'website_form',
                'reference_id'   => $leadId,
                'reference_type' => 'Lead',
                'color'          => '#A855F7',
                'starts_at'      => $startsAt,
                'ends_at'        => $startsAt->copy()->addMinutes(30),
                'all_day'        => empty($data['preferred_time']),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[PublicBooking] calendar event failed; lead persisted', ['workspace_id' => $wsId, 'lead_id' => $leadId, 'error' => $e->getMessage()]);
        }

        try {
            DB::table('booking_submissions')->insert([
                'website_id'     => (int) $website->id,
                'name'           => $name,
                'email'          => $email !== '' ? $email : null,
                'phone'          => $phone !== '' ? $phone : null,
                'service'        => $data['service'] ?? null,
                'preferred_date' => $data['preferred_date'] ?? null,
                'preferred_time' => $data['preferred_time'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'status'         => 'new', // enum('new','confirmed','cancelled')
                'meta_json'      => json_encode(['lead_id' => $leadId, 'event_id' => $eventId, 'form' => $data['form'] ?? 'booking', 'party_size' => $data['party_size'] ?? null, 'extra' => $data['extra'] ?? null, 'ip' => $request->ip()]),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[PublicBooking] booking_submissions insert failed', ['error' => $e->getMessage()]);
        }

        // ── Owner notification ───────────────────────────────────────────────────────────────
        $ownerId = (int) (DB::table('workspace_users')->where('workspace_id', $wsId)->where('role', 'owner')->value('user_id') ?: 0);
        if ($ownerId > 0) {
            try {
                app(NotificationService::class)->dispatch(
                    type: NotificationTypes::LEAD_CONTACT_FORM,
                    userId: $ownerId,
                    title: $kind === 'quote' ? 'New quote request' : 'New booking request',
                    workspaceId: $wsId,
                    body: "{$name}" . ($email !== '' ? " ({$email})" : '') . " asked for a " . $kind
                        . ($startsAt && ! empty($data['preferred_date']) ? ' on ' . $startsAt->format('D j M') . (! empty($data['preferred_time']) ? ' at ' . $startsAt->format('H:i') : '') : '')
                        . ' via your website — confirm it in Calendar › Bookings.',
                    data: ['lead_id' => $leadId, 'event_id' => $eventId, 'website_id' => (int) $website->id, 'source' => 'website_form'],
                    actionUrl: '/app/calendar',
                    severity: 'success'
                );
            } catch (\Throwable $e) {
                Log::warning('[PublicBooking] notification failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Request received — ' . ($website->name ?: 'the team') . ' will confirm with you'
                . ($email !== '' ? ' by email' : ' by phone') . '.',
            'lead_id' => $leadId,
            'event_id' => $eventId,
        ]);
    }

    private function parseWhen(?string $date, ?string $time, int $wsId): \Carbon\Carbon
    {
        $tz = (string) (DB::table('workspaces')->where('id', $wsId)->value('timezone') ?: 'UTC');
        try {
            $d = $date ? \Carbon\Carbon::parse($date, $tz) : now($tz)->addDay();
            if ($time && preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) $d->setTime((int) $m[1], (int) $m[2]);
            elseif ($time && preg_match('/^(\d{1,2})\s*(am|pm)$/i', trim($time), $m)) $d->setTime(((int) $m[1] % 12) + (strtolower($m[2]) === 'pm' ? 12 : 0), 0);
            elseif (! $time) $d->setTime(9, 0);
            return $d->setTimezone('UTC');
        } catch (\Throwable) {
            return now()->addDay()->setTime(9, 0);
        }
    }
}
