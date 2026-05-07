<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Queued job that emails a notification to its target user.
 *
 * - Single attempt ($tries = 1) — notifications are non-critical;
 *   we don't want a Postmark hiccup to retry-storm the queue.
 * - Records emailed_at on success so we don't double-send.
 * - On failure: log + swallow. The in-app notification still exists.
 */
class SendNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $notificationId) {}

    public function handle(): void
    {
        $n = Notification::find($this->notificationId);
        if (! $n) return;

        $user = User::find($n->user_id);
        if (! $user || ! $user->email) return;

        if ($n->emailed_at) return; // idempotency

        try {
            Mail::send(
                'emails.notification',
                ['notification' => $n, 'user' => $user],
                function ($m) use ($user, $n) {
                    $m->to($user->email)
                      ->subject($n->title)
                      ->from(
                          config('mail.from.address', 'hello@levelupgrowth.io'),
                          config('mail.from.name', 'LevelUp Growth')
                      );
                }
            );
            $n->update(['emailed_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('SendNotificationEmail failed', [
                'notification_id' => $this->notificationId,
                'error'           => $e->getMessage(),
            ]);
            // do NOT rethrow — non-critical
        }
    }
}
