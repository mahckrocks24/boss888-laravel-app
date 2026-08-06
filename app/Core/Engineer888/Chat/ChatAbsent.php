<?php

namespace App\Core\Engineer888\Chat;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * One answer for everything the caller may not have.
 *
 * A record that does not exist, a record owned by somebody else, a guessed UUID,
 * a revoked grant and a machine credential all produce this and nothing else.
 * Any variation between them — a different status, a different body, even a
 * measurably different response time on a cheap path — is an oracle that tells
 * an attacker which of their guesses was closer.
 *
 * Thrown rather than returned so a caller cannot forget to return it. A `return
 * $this->absent()` that is written as a bare statement continues into the code
 * below it; a throw does not.
 */
final class ChatAbsent
{
    /**
     * @throws HttpException always
     */
    public static function throw(string $internalReason = ''): never
    {
        // The reason is for the log, never for the caller.
        if ($internalReason !== '') {
            \Illuminate\Support\Facades\Log::info('engineer888.chat.absent', [
                'reason' => $internalReason,
            ]);
        }

        throw new HttpException(
            (int) config('engineer888_access.deny_status', 404),
            'Not Found'
        );
    }
}
