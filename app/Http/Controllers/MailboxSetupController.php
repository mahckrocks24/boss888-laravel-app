<?php

namespace App\Http\Controllers;

use App\Connectors\Infrastructure\BusinessEmail\SecretString;
use App\Engines\Infrastructure\Email\Onboarding\MailboxSetupService;
use App\Engines\Infrastructure\Email\Onboarding\MailboxSetupToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * INFRA888 · E7.3 — the customer-facing setup page and relay.
 *
 * This is the only unauthenticated surface in Business Email, and the only
 * place a mailbox password is ever received. Both facts shape it:
 *
 *   · every refusal returns the SAME customer-safe message, because a stranger
 *     holding a guessed token must not learn whether it was wrong, expired,
 *     already used or belonged to a deleted mailbox
 *   · the password is wrapped in SecretString the moment it is read from the
 *     request and is never assigned to anything that could be serialised
 *   · nothing here names the provider, in the page, the JSON, or an error
 */
// No base Controller class exists in this codebase — controllers here are plain
// classes. Extending a non-existent base is a known trap in this repo.
class MailboxSetupController
{
    /**
     * One message for every failure. Distinguishing them would turn this page
     * into an oracle for anyone probing tokens.
     */
    private const REFUSAL = 'This setup link is no longer valid. Ask your administrator to send a new one.';

    public function __construct(private readonly MailboxSetupService $setup)
    {
    }

    /** The page. Renders a form, or the single refusal. */
    public function show(Request $request, string $token)
    {
        if (! $this->withinRateLimit($request, 'view')) {
            return response()->view('business-email-setup', [
                'ok'      => false,
                'message' => 'Too many attempts. Please wait a few minutes and try again.',
                'token'   => '',
                'address' => '',
            ], 429);
        }

        $record = $this->setup->resolve($token);
        $mailbox = $record !== null ? $this->setup->mailboxFor($record) : null;

        if ($mailbox === null) {
            return response()->view('business-email-setup', [
                'ok'      => false,
                'message' => self::REFUSAL,
                'token'   => '',
                'address' => '',
            ], 404);
        }

        return response()->view('business-email-setup', [
            'ok'      => true,
            'message' => '',
            // Echoed back so the form can post it. It is already in the URL the
            // customer followed, so this reveals nothing new.
            'token'   => $token,
            'address' => $this->addressFor($mailbox),
        ]);
    }

    /**
     * Receive the chosen password and relay it once.
     *
     * THE PASSWORD'S ENTIRE LIFE IS THIS METHOD. It is read from the request,
     * wrapped, handed to the service, and gone. It is never assigned to a
     * model, an array that gets persisted, a queued job, or a log line.
     */
    public function store(Request $request, string $token)
    {
        if (! $this->withinRateLimit($request, 'submit')) {
            return response()->json([
                'success' => false,
                'message' => 'Too many attempts. Please wait a few minutes and try again.',
            ], 429);
        }

        $record = $this->setup->resolve($token);
        $mailbox = $record !== null ? $this->setup->mailboxFor($record) : null;

        if ($mailbox === null) {
            return response()->json(['success' => false, 'message' => self::REFUSAL], 404);
        }

        // Validated by rule, so the raw value never has to be inspected here.
        // `password` is excluded from Laravel's own exception flashing, and the
        // whole request is marked so nothing downstream captures the body.
        $request->validate([
            'password' => ['required', 'string', 'min:12', 'max:200', 'confirmed'],
        ]);

        $password = SecretString::fromInput((string) $request->input('password'));

        // Belt and braces: drop the plaintext from the request object before
        // anything else can read it back out.
        $request->request->remove('password');
        $request->request->remove('password_confirmation');

        $outcome = $this->setup->relay($record, $mailbox, $password);

        unset($password);

        if (! $outcome['ok']) {
            return response()->json([
                'success' => false,
                'message' => $outcome['reason'] === 'inconclusive'
                    // Honest: we genuinely do not know, and we will not guess.
                    ? 'We could not confirm the change. Please wait a moment and try again — '
                        . 'if it keeps happening, contact LevelUp Growth support.'
                    : 'We could not set the password just now. Please try again in a few minutes.',
            ], 503);
        }

        // "READY" IS NOT THE SAME AS "SIGN IN NOW."
        //
        // Measured live on 2026-08-07: the mail server rejected a correct new
        // password for roughly twenty seconds after the API accepted it, then
        // authenticated cleanly. Telling a customer they can sign in
        // immediately would send them straight into a failed login and a
        // support ticket — the fabricated-success failure this platform exists
        // to avoid, in miniature.
        return response()->json([
            'success' => true,
            'active'  => $outcome['state'] === 'active',
            'message' => 'Your password is set. It can take up to a minute before you can sign in — '
                . 'if the first attempt is refused, wait a moment and try again.',
        ]);
    }

    /**
     * Rate limited per IP and per token, so neither a scattergun nor a
     * concentrated attempt on one link is cheap.
     */
    private function withinRateLimit(Request $request, string $action): bool
    {
        foreach ([
            'mbx-setup-' . $action . '-ip:' . $request->ip()     => 30,
            'mbx-setup-' . $action . '-tok:' . substr(hash('sha256', (string) $request->route('token')), 0, 32) => 10,
        ] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return false;
            }

            RateLimiter::hit($key, 900);
        }

        return true;
    }

    /** The mailbox address, for the page. Never a provider reference. */
    /**
     * The mailbox address, resolved WITHOUT touching a scoped relation.
     *
     * $mailbox->domain is a BelongsTo carrying the workspace global scope. The
     * customer following a setup link is unauthenticated and has no workspace
     * context, so touching that relation threw "WorkspaceContext is not set"
     * and returned a 500 for every VALID token - the setup page has never
     * worked. mailboxFor() wraps its own query correctly, but a lazy relation
     * fires wherever it is first touched, which was out here.
     *
     * A direct query on the domains table carries no model scope, so there is
     * no context to be missing. The token already established the tenancy.
     */
    private function addressFor($mailbox): string
    {
        $domain = \Illuminate\Support\Facades\DB::table('email_domains')
            ->where('id', $mailbox->email_domain_id)
            ->value('domain');

        return trim((string) $mailbox->local_part) . '@' . trim((string) $domain);
    }
}
