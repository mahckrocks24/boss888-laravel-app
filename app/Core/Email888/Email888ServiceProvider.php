<?php

namespace App\Core\Email888;

use App\Core\Email888\Listeners\RecordOutboundMail;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * EMAIL888 — outbound email observability and policy.
 *
 * Registered as its own provider rather than folded into AppServiceProvider so
 * that parallel workstreams editing that file never collide with this one.
 */
class Email888ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DeliveryLedger::class);
        $this->app->singleton(EmailDispatcher::class);
    }

    public function boot(): void
    {
        Event::listen(MessageSending::class, [RecordOutboundMail::class, 'sending']);
        Event::listen(MessageSent::class, [RecordOutboundMail::class, 'sent']);

        // Email888 keeps its commands beside the code they operate on rather
        // than in app/Console/Commands, so auto-discovery does not find them.
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Core\Email888\Console\ReconcileDeliveries::class,
                \App\Core\Email888\Console\ProbeDelivery::class,
                \App\Core\Email888\Console\PruneWebhookReceipts::class,
            ]);
        }

        // Scheduled from this provider rather than bootstrap/app.php: that
        // file is edited by every parallel workstream, and a merge conflict
        // there would take the whole scheduler down, not just this entry.
        $this->app->booted(function () {
            $this->app->make(\Illuminate\Console\Scheduling\Schedule::class)
                ->command('email888:reconcile')
                ->name('email888:reconcile')
                ->everyTenMinutes()
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();

            // EM-6. Webhook receipts are written by anyone who can reach the
            // public endpoint, so the table needs a bound that is not "nobody
            // has attacked us yet". Daily and off-peak; it deletes nothing that
            // is the newest of its kind.
            $this->app->make(\Illuminate\Console\Scheduling\Schedule::class)
                ->command('email888:prune-receipts')
                ->name('email888:prune-receipts')
                ->dailyAt('03:40')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();
        });
    }
}
