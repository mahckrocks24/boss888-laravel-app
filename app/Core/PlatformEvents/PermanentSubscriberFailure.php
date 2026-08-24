<?php

namespace App\Core\PlatformEvents;

use RuntimeException;

/**
 * A subscriber failure that retrying cannot fix.
 *
 * The worker classifies database-level failures (integrity violations, invalid
 * data) as permanent on its own, but only the SUBSCRIBER knows whether its own
 * logic can ever succeed for a given event. "There is no audit mapping for this
 * event type" will not become true because we tried again five more times.
 *
 * Throwing this instead of a plain exception means the delivery is marked
 * permanently_failed immediately, with its error preserved for inspection,
 * rather than consuming its whole retry budget first.
 */
class PermanentSubscriberFailure extends RuntimeException
{
}
