<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Email-sequence runner.
 *
 * - Iterates active sequences and finds active enrollments whose next step
 *   is due (now() >= last_sent_at + step.delay_hours, or now() >= enrolled_at
 *   + delay_hours for the first step).
 * - Sends the step's email via the existing Postmark mailer + the
 *   `emails.notification` blade template (same template the in-app
 *   notification mailer uses, so we get one consistent layout).
 * - On success: marks last_sent_at, advances current_step_order, marks
 *   completed when past the last step.
 * - On failure: logs and leaves the enrollment alone — next cron tick retries.
 *
 * Cron: registered in bootstrap/app.php as ->everyFifteenMinutes().
 */
class RunSequences extends Command
{
    protected $signature   = 'lu:sequences:run {--dry : List due sends without firing emails}';
    protected $description = 'Process due sequence_steps for active enrollments and send emails.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $sent = 0; $skipped = 0; $errored = 0; $completed = 0;

        $sequences = DB::table('sequences')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->get();

        if ($sequences->isEmpty()) {
            $this->info('No active sequences. Nothing to do.');
            return self::SUCCESS;
        }

        foreach ($sequences as $seq) {
            $steps = DB::table('sequence_steps')
                ->where('sequence_id', $seq->id)
                ->orderBy('step_order')
                ->get();

            if ($steps->isEmpty()) continue;

            $stepsByOrder = $steps->keyBy('step_order');
            $maxOrder     = (int) $steps->max('step_order');

            $enrollments = DB::table('sequence_enrollments')
                ->where('sequence_id', $seq->id)
                ->where('status', 'active')
                ->get();

            foreach ($enrollments as $en) {
                $stepOrder = (int) $en->current_step_order;

                // Already past the final step: complete + skip.
                if ($stepOrder > $maxOrder) {
                    DB::table('sequence_enrollments')->where('id', $en->id)->update([
                        'status'       => 'completed',
                        'completed_at' => now(),
                        'updated_at'   => now(),
                    ]);
                    $completed++;
                    continue;
                }

                $step = $stepsByOrder->get($stepOrder);
                if (! $step) { $skipped++; continue; }

                // Due if delay since last_sent_at (or enrolled_at for step 1) elapsed.
                $referenceAt = $en->last_sent_at ?: $en->enrolled_at;
                $dueAt       = strtotime($referenceAt) + ((int) $step->delay_hours) * 3600;
                if (time() < $dueAt) { $skipped++; continue; }

                $contact = DB::table('contacts')->where('id', $en->contact_id)->first();
                if (! $contact || empty($contact->email)) { $skipped++; continue; }

                if ($dry) {
                    $this->line("DUE: enrollment={$en->id} contact={$contact->email} seq={$seq->id} step={$stepOrder}");
                    continue;
                }

                try {
                    $this->sendStep($contact, $step, $seq);

                    $newOrder  = $stepOrder + 1;
                    $isDone    = $newOrder > $maxOrder;
                    DB::table('sequence_enrollments')->where('id', $en->id)->update([
                        'current_step_order' => $newOrder,
                        'last_sent_at'       => now(),
                        'status'             => $isDone ? 'completed' : 'active',
                        'completed_at'       => $isDone ? now() : null,
                        'updated_at'         => now(),
                    ]);
                    $sent++;
                    if ($isDone) $completed++;
                } catch (\Throwable $e) {
                    $errored++;
                    Log::error('RunSequences send failed', [
                        'enrollment_id' => $en->id,
                        'sequence_id'   => $seq->id,
                        'step_order'    => $stepOrder,
                        'contact_id'    => $en->contact_id,
                        'error'         => $e->getMessage(),
                    ]);
                    // Do NOT advance — next cron tick retries.
                }
            }
        }

        $this->info("sent={$sent} skipped={$skipped} errored={$errored} completed={$completed}");
        return self::SUCCESS;
    }

    private function sendStep(object $contact, object $step, object $sequence): void
    {
        $subject = (string) ($step->email_subject ?? "Update from {$sequence->name}");
        $bodyHtml = (string) ($step->email_body_html ?? '');

        // Use the existing emails.notification template — consistent layout.
        Mail::send(
            'emails.notification',
            [
                'notification' => (object) [
                    'title'      => $subject,
                    'body'       => $bodyHtml,
                    'action_url' => null,
                ],
                'user' => (object) [
                    'email' => $contact->email,
                    'name'  => $contact->name ?? $contact->first_name ?? null,
                ],
            ],
            function ($m) use ($contact, $subject) {
                $m->to($contact->email, $contact->name ?? null)
                  ->subject($subject)
                  ->from(
                      config('mail.from.address', env('MAIL_FROM_ADDRESS', 'hello@levelupgrowth.io')),
                      config('mail.from.name', env('MAIL_FROM_NAME', 'LevelUp Growth'))
                  );
            }
        );
    }
}
