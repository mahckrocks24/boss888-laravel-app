<?php

namespace App\Core\Email888\States;

/**
 * EMAIL888 — provider-neutral delivery state.
 *
 * Product code must never branch on a Postmark event name. It branches on
 * these. Adding a second provider means teaching one mapper, not auditing
 * every caller.
 */
enum DeliveryState: string
{
    /** Handed to the mailer; the provider has not answered yet. */
    case QUEUED = 'queued';

    /** The provider took responsibility and issued an id. NOT delivery. */
    case ACCEPTED = 'accepted';

    /** The receiving server accepted it. This is the only success. */
    case DELIVERED = 'delivered';

    /** Temporary refusal upstream; the provider is still trying. */
    case DEFERRED = 'deferred';

    /** Permanently refused by the recipient's server. */
    case BOUNCED = 'bounced';

    /** The provider refused to send: recipient on a suppression list. */
    case SUPPRESSED = 'suppressed';

    /** We could not hand it over at all, or it died without a terminal event. */
    case FAILED = 'failed';

    /**
     * A terminal state is one no further provider event can improve. The reaper
     * and the "did it arrive?" question both key off this.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::DELIVERED, self::BOUNCED, self::SUPPRESSED, self::FAILED => true,
            self::QUEUED, self::ACCEPTED, self::DEFERRED => false,
        };
    }

    public function isSuccess(): bool
    {
        return $this === self::DELIVERED;
    }

    /**
     * States never move backwards. A late-arriving 'accepted' webhook must not
     * un-deliver a message that is already proven delivered.
     */
    public function rank(): int
    {
        return match ($this) {
            self::QUEUED     => 0,
            self::ACCEPTED   => 1,
            self::DEFERRED   => 2,
            self::DELIVERED  => 3,
            self::BOUNCED    => 3,
            self::SUPPRESSED => 3,
            self::FAILED     => 3,
        };
    }
}
