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
                    'source'    => 'website_form',
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
                'source'        => 'website_form',
                'status'        => 'new',
                'metadata_json' => json_encode([
                    'first_message' => $validated['message'],
                    'subdomain'     => $subdomain,
                    'submitted_at'  => now()->toIso8601String(),
                ]),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
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
                    'source'       => 'website_form',
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
