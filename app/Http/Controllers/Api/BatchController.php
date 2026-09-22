<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * GET /api/batch?paths[]=workspace/status&paths[]=dashboard/overview…
 *
 * PERF (Owner 2026-09-21/22, "CRM page takes 10 sec", "Needs attention 10 sec"): a view that opens with several
 * independent reads paid one Laravel boot (~0.3 s on the 1-vCPU box) per read, serialised behind each other. This
 * endpoint runs those reads as SUB-REQUESTS inside one process — the SAME routes, the SAME middleware (auth.jwt,
 * traffic.defense, connector.brand), the SAME JSON — so no handler is duplicated and nothing changes shape. Only
 * allow-listed GET paths may ride in a batch; at most 10; each item carries its own status and body so one failure
 * never hides another. The shell's _luBatch coalescer (core.js) falls back to direct fetches when this is unavailable.
 */
class BatchController
{
    /** Paths (without /api/ and without a query string) that may be batched. Extend deliberately, one per view need. */
    public const ALLOW = [
        // Sarah's view (sarah.js): context, briefing, rail
        'workspace/status', 'user/last-chat-workspace', 'dashboard/overview', 'approvals', 'calendar/events', 'social/accounts', 'seo/gsc/status',
        // Clients / CRM engine boot (crm.js)
        'crm/dashboard', 'crm/pipeline/stages', 'crm/leads', 'crm/contacts', 'crm/modules', 'crm/settings', 'crm/tasks', 'crm/appointments',
        // The shell's own per-page pollers (core.js _luFetch) and Needs attention (basic.js): one tick, one request
        'approvals/count', 'engines', 'messages/unread-count', 'notifications/unread-count', 'tasks', 'seo/knowledge', 'catalogue/summary', 'businesses',
    ];

    public const MAX = 10;

    public function get(Request $request)
    {
        $paths = $request->query('paths', []);
        $paths = is_array($paths) ? array_values(array_filter($paths, 'is_string')) : [];
        if ($paths === []) {
            return response()->json(['error' => 'paths[] required'], 422);
        }
        if (count($paths) > self::MAX) {
            return response()->json(['error' => 'at most ' . self::MAX . ' paths per batch'], 422);
        }

        $app = app();
        $router = $app['router'];
        $original = $app['request'];
        $results = [];

        foreach ($paths as $raw) {
            $key = ltrim($raw, '/');
            $clean = strtok($key, '?');
            if (! in_array($clean, self::ALLOW, true) || str_contains($key, '..')) {
                $results[$key] = ['status' => 422, 'body' => ['error' => 'not batchable']];
                continue;
            }

            $sub = Request::create('/api/' . $key, 'GET', [], $request->cookies->all(), [], $request->server->all());
            $sub->headers->replace($request->headers->all());

            $app->instance('request', $sub);
            try {
                $response = $router->dispatch($sub);
                $status = $response->getStatusCode();
                $decoded = json_decode((string) $response->getContent(), true);
                $results[$key] = ['status' => $status, 'body' => $decoded];
            } catch (\Throwable $e) {
                Log::warning('[Batch] item failed', ['path' => $key, 'error' => $e->getMessage()]);
                $results[$key] = ['status' => 500, 'body' => ['error' => 'batch item failed']];
            } finally {
                $app->instance('request', $original);
            }
        }

        return response()->json(['results' => $results]);
    }
}
