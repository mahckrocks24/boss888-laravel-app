<?php

namespace App\Http\Controllers\Api;

use App\Core\Notifications\NotificationService;
use App\Core\Notifications\NotificationTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * T3.2 — Public contact-form submission handler.
 *
 * Workspace-agnostic. Resolves the target workspace from the URL subdomain
 * via the `websites` table, writes to `contacts` (with duplicate detection
 * by email per workspace), and dispatches LEAD_CONTACT_FORM (+ optional
 * LEAD_DUPLICATE_FLAGGED) to the workspace owner.
 *
 * Public — no JWT required. Rate-limited at the route level (10/min/IP).
 */
class PublicContactController
{
    /**
     * 2026-05-28 — Host-resolved submission entry point. Same as ::submit
     * but resolves the website from the Origin / Referer / Host request
     * header instead of a {subdomain} URL parameter. Used by templates that
     * can't hardcode their site slug at template-time (any custom-domain
     * tenant whose published HTML uses the dynamic by-host fallback).
     */
    public function submitByHost(Request $request): JsonResponse
    {
        // Try Origin → Referer → Host header in that order
        $candidates = [];
        $origin = $request->header('Origin');
        $referer = $request->header('Referer');
        $host = $request->header('Host');
        foreach ([$origin, $referer] as $url) {
            if (!$url) continue;
            $h = parse_url($url, PHP_URL_HOST);
            if ($h) $candidates[] = strtolower($h);
        }
        if ($host && $host !== 'staging.levelupgrowth.io') $candidates[] = strtolower($host);

        if (!$candidates) {
            return response()->json(['success' => false, 'message' => 'Cannot resolve site (no Origin/Referer/Host header)'], 400);
        }

        // Resolve via custom_domain OR subdomain match
        $website = null;
        foreach ($candidates as $h) {
            $website = DB::table('websites')
                ->where(function ($q) use ($h) {
                    $q->where('custom_domain', $h)->orWhere('subdomain', $h);
                })
                ->where('status', 'published')
                ->first();
            if ($website) break;
        }

        if (!$website) {
            return response()->json([
                'success' => false,
                'message' => 'Site not found for host: ' . implode(' / ', $candidates),
            ], 404);
        }

        // Re-extract slug from subdomain so we can hand control to ::submit
        // (slug is the first label before .levelupgrowth.io)
        $slug = explode('.', $website->subdomain)[0] ?? '';
        return $this->submit($request, $slug);
    }

    public function submit(Request $request, string $subdomain): JsonResponse
    {
        // ─── 1. Resolve website + workspace from subdomain ────────────
        // websites.subdomain stores the full hostname (e.g. "chef-red.levelupgrowth.io")
        $website = DB::table('websites')
            ->where('subdomain', $subdomain . '.levelupgrowth.io')
            ->where('status', 'published')
            ->first();

        if (! $website) {
            return response()->json([
                'success' => false,
                'message' => 'Site not found',
            ], 404);
        }

        // ─── 2. Validate input ────────────────────────────────────────
        // KABAYAN888 JOBS-1 — theme forms send `name` and a `source` tag (newsletter, job_post, spot_submission…).
        if (!$request->filled('firstname') && $request->filled('name')) { $request->merge(['firstname' => mb_substr(trim((string) $request->input('name')), 0, 100)]); }
        $__source = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $request->input('source', ''))); $__source = $__source !== '' ? mb_substr($__source, 0, 40) : 'website_form';
        $__websiteId = (int) ($website->id ?? 0) ?: null; // KABAYAN888 JOBS-1 — the site row loaded above, never the request
        $validated = $request->validate([
            'firstname' => 'required|string|max:100',
            'email'     => 'required|email|max:255',
            'phone'     => 'nullable|string|max:50',
            'message'   => 'required|string|max:2000',
        ]);

        $wsId = (int) $website->workspace_id;

        // ─── 3. Duplicate detection ───────────────────────────────────
        $existing = DB::table('contacts')
            ->where('workspace_id', $wsId)
            ->where('email', $validated['email'])
            ->whereNull('deleted_at')
            ->first();

        $isDuplicate = ! is_null($existing);

        // ─── 4. CRM write ─────────────────────────────────────────────
        if ($isDuplicate) {
            // Touch existing contact + log a polymorphic activity row
            DB::table('contacts')
                ->where('id', $existing->id)
                ->update(['updated_at' => now()]);
            $contactId = (int) $existing->id;

            // Log touchpoint via activities (polymorphic to App\Models\Contact)
            DB::table('activities')->insert([
                'workspace_id'     => $wsId,
                'activitable_type' => 'App\\Models\\Contact',
                'activitable_id'   => $contactId,
                'type'             => 'form_submission',
                'subject'          => 'Contact form re-submission',
                'description'      => $validated['message'],
                'metadata_json'    => json_encode([
                    'source'    => $__source,
                    'subdomain' => $subdomain,
                    'phone'     => $validated['phone'] ?? null,
                ]),
                'completed'        => 1,
                'completed_at'     => now(),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        } else {
            // Create new contact. `name` is NOT NULL — populate from firstname.
            $contactId = (int) DB::table('contacts')->insertGetId([
                'workspace_id'  => $wsId,
                'name'          => $validated['firstname'],
                'first_name'    => $validated['firstname'],
                'email'         => $validated['email'],
                'phone'         => $validated['phone'] ?? null,
                'source'        => $__source, // KABAYAN888 JOBS-1
                'status'        => 'new',
                'metadata_json' => json_encode([
                    'first_message' => $validated['message'],
                    'subdomain'     => $subdomain,
                    'submitted_at'  => now()->toIso8601String(),
                ]),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            // 2026-05-27 — Auto-mirror new contact → lead so the submission
            // surfaces in the CRM "Leads" view immediately. The CRM UI reads
            // from the `leads` table; without this row, contact-form
            // submissions were invisible despite the contact existing.
            // Dedupe by (workspace_id, email) so we never double-create.
            try {
                $existingLead = DB::table('leads')
                    ->where('workspace_id', $wsId)
                    ->where('email', $validated['email'])
                    ->whereNull('deleted_at')
                    ->first();
                if (!$existingLead) {
                    DB::table('leads')->insert([
                        'workspace_id'  => $wsId,
                        'name'          => $validated['firstname'],
                        'email'         => $validated['email'],
                        'phone'         => $validated['phone'] ?? null,
                        'website_id'    => $__websiteId, // KABAYAN888 JOBS-1
                        'source'        => $__source,    // KABAYAN888 JOBS-1
                        'status'        => 'new',
                        'score'         => 0,
                        'deal_value'    => 0,
                        'metadata_json' => json_encode([
                            'first_message' => $validated['message'],
                            'subdomain'     => $subdomain,
                            'contact_id'    => $contactId,
                            'submitted_at'  => now()->toIso8601String(),
                        ]),
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                } else {
                    // Touch existing lead so it floats back up in the UI.
                    DB::table('leads')->where('id', $existingLead->id)->update([
                        'updated_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('[PublicContact] lead mirror failed', [
                    'contact_id'   => $contactId,
                    'workspace_id' => $wsId,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        // ─── 5. Owner discovery ───────────────────────────────────────
        $owner = DB::table('workspace_users')
            ->where('workspace_id', $wsId)
            ->where('role', 'owner')
            ->first();
        $ownerUserId = $owner ? (int) $owner->user_id : 1; // fallback to platform admin

        // ─── 6. Notification dispatch ─────────────────────────────────
        $notifSvc = app(NotificationService::class);
        try {
            $notifSvc->dispatch(
                type: NotificationTypes::LEAD_CONTACT_FORM,
                userId: $ownerUserId,
                title: 'New contact form submission',
                workspaceId: $wsId,
                body: "{$validated['firstname']} ({$validated['email']}) submitted a contact form"
                    . ($isDuplicate ? ' — existing contact updated' : ''),
                data: [
                    'contact_id'   => $contactId,
                    'firstname'    => $validated['firstname'],
                    'email'        => $validated['email'],
                    'phone'        => $validated['phone'] ?? null,
                    'message'      => $validated['message'],
                    'source'       => $__source, // KABAYAN888 JOBS-1
                    'subdomain'    => $subdomain,
                    'is_duplicate' => $isDuplicate,
                ],
                actionUrl: '/crm/contacts/' . $contactId,
                severity: $isDuplicate ? 'warning' : 'success'
            );

            if ($isDuplicate) {
                $notifSvc->dispatch(
                    type: NotificationTypes::LEAD_DUPLICATE_FLAGGED,
                    userId: $ownerUserId,
                    title: 'Duplicate lead detected',
                    workspaceId: $wsId,
                    body: "{$validated['email']} already exists in your CRM (contact #{$existing->id}). Touchpoint logged.",
                    data: ['contact_id' => (int) $existing->id, 'email' => $validated['email']],
                    actionUrl: '/crm/contacts/' . (int) $existing->id,
                    severity: 'warning'
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Contact form notification failed', [
                'workspace_id' => $wsId,
                'contact_id'   => $contactId,
                'error'        => $e->getMessage(),
            ]);
            // Don't fail the form submission just because notification dispatch hiccupped.
        }

        // ─── 7. Automation trigger (PATCH 7, 2026-05-08) ──────────────
        // Fires any active automation whose trigger_type='form_submitted'.
        // Wrapped in try/catch so a misconfigured automation never breaks
        // the public form submission.
        try {
            app(\App\Engines\Marketing\Services\MarketingService::class)->triggerAutomation(
                $wsId,
                'form_submitted',
                [
                    'contact_id'   => $contactId,
                    'email'        => $validated['email'],
                    'firstname'    => $validated['firstname'],
                    'phone'        => $validated['phone'] ?? null,
                    'message'      => $validated['message'],
                    'subdomain'    => $subdomain,
                    'is_duplicate' => $isDuplicate,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Contact form automation trigger failed', [
                'workspace_id' => $wsId,
                'contact_id'   => $contactId,
                'error'        => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success'      => true,
            'message'      => 'Thank you! We will get back to you soon.',
            'is_duplicate' => $isDuplicate,
        ]);
    }
}
