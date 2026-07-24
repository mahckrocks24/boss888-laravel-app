<?php

namespace App\Http\Controllers;

use App\Core\Strategy\AutomationCalendarService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Read-only endpoints for the Automation page sidebar surface + per-engine
 * convenience calendars that read from automation_events.
 *
 * Distinct from CalendarController (user's personal events). Never crosses
 * the calendar_events / automation_events boundary.
 */
class AutomationCalendarController extends Controller
{
    public function __construct(private readonly AutomationCalendarService $svc) {}

    /**
     * GET /api/automation/calendar
     * Main Automation Calendar — aggregated agent-timeline view.
     *
     * Query params:
     *   from, to     ISO dates (default: current month)
     *   engine       optional filter (write, social, marketing, sarah, etc.)
     *   category     optional filter (plan_task, scheduled_email, scheduled_post, sarah_cron)
     *   status       optional filter (scheduled, running, completed, failed)
     */
    public function main(Request $r)
    {
        $wsId = (int) $r->attributes->get('workspace_id');
        $filters = array_filter([
            'engine'   => $r->query('engine'),
            'category' => $r->query('category'),
            'status'   => $r->query('status'),
        ]);
        return response()->json([
            'success' => true,
            'data'    => $this->svc->mainCalendar(
                $wsId,
                $r->query('from'),
                $r->query('to'),
                $filters
            ),
        ]);
    }

    /**
     * GET /api/email-marketing/calendar
     * Per-engine convenience view — marketing only (campaigns, drip sends).
     */
    public function emailMarketing(Request $r)
    {
        $wsId = (int) $r->attributes->get('workspace_id');
        return response()->json([
            'success' => true,
            'data'    => $this->svc->perEngineCalendar(
                $wsId,
                'marketing',
                $r->query('from'),
                $r->query('to')
            ),
        ]);
    }
}