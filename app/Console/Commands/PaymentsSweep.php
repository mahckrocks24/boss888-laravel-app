<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PAY-CONNECT-1: a paid Checkout is normally confirmed when the customer comes back to the page. If they close the tab
 * first (and there is no webhook — Connect accounts, or a pasted key that could not register one), this asks Stripe
 * about recent open sessions and records the ones that were paid. Kill switch: storage/app/paysweep.on.
 */
class PaymentsSweep extends Command
{
    protected $signature = 'payments:sweep {--dry : list what would be checked, change nothing}';
    protected $description = 'Confirm paid Stripe Checkout sessions whose customer never returned to the page';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        if (! $dry && ! file_exists(storage_path('app/paysweep.on'))) return self::SUCCESS;
        $from = now()->subHours(48); $to = now()->subMinutes(5);
        $orders = DB::table('catalogue_orders')->where('status', 'pending')->whereNotNull('session_id')->whereBetween('created_at', [$from, $to])->limit(50)->get(['id', 'website_id', 'session_id', 'workspace_id']);
        $reqs = DB::table('crm_payment_requests')->whereIn('status', ['sent', 'viewed', 'accepted', 'draft'])->whereNotNull('session_id')->whereBetween('updated_at', [$from, $to])->limit(50)->get();
        $this->line('open store sessions: ' . $orders->count() . ', open client payment sessions: ' . $reqs->count());
        if ($dry) { foreach ($orders as $o) $this->line("  order {$o->id} ws {$o->workspace_id}"); foreach ($reqs as $p) $this->line("  request {$p->id} ws {$p->workspace_id} {$p->number}"); return self::SUCCESS; }
        $paid = 0;
        foreach ($orders as $o) { try { $r = app(\App\Engines\Builder\Services\StorePaymentsService::class)->confirmSession((int) $o->website_id, (string) $o->session_id); if (! empty($r['paid'])) $paid++; } catch (\Throwable $e) {} }
        foreach ($reqs as $p) { try { if (app(\App\Engines\CRM\Services\CrmPayments::class)->confirm($p, (string) $p->session_id)) $paid++; } catch (\Throwable $e) {} }
        if ($paid) $this->info("confirmed {$paid} paid");
        return self::SUCCESS;
    }
}
