<?php

namespace App\Core\Engineer888\Chat;

use App\Core\Engineer888\Access\Engineer888Access;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * The one owner Engineer888 chat admits.
 *
 * WHY A CLASS AND NOT A LITERAL. `owner_user_id = 1` appears in every query in
 * this module. Written out by hand it would appear correctly in nine places and
 * incorrectly — or not at all — in the tenth, and the tenth is the one that
 * leaks. Every read and write goes through scope(), so a query that forgets the
 * owner does not compile into anything useful.
 *
 * THE VALUE IS NOT DEFINED HERE. It is Engineer888Access::CANONICAL_USER_ID,
 * because that class is the sole access authority and a second copy of the
 * number would be a second thing to update. If the product policy ever widens,
 * it widens in one place.
 *
 * THIS IS NOT AUTHORISATION. Scoping a query to the owner is not the same as
 * deciding whether the caller may run it. RequireEngineer888Access answers that,
 * on every route, before any of this is reached. This class assumes the caller
 * has already been admitted and makes sure the data they see is theirs.
 */
final class ChatOwner
{
    /** The only valid owner in V1. */
    public const USER_ID = Engineer888Access::CANONICAL_USER_ID;

    /**
     * The owner id for this request, or nothing at all.
     *
     * Returns null rather than throwing so callers answer with the module's
     * uniform 404 instead of a distinguishable error. A caller that is not the
     * canonical account must not be able to tell the difference between "you
     * may not" and "there is nothing here".
     */
    public static function resolve(?Request $request): ?int
    {
        $user = $request?->user();

        if ($user === null) {
            return null;
        }

        // Re-derived from the resolved user, never from a header, a body field,
        // a route parameter or an attribute a previous middleware set.
        if ((int) ($user->id ?? 0) !== self::USER_ID) {
            return null;
        }

        // The email is checked here too. Engineer888Access already checked it,
        // and that is the point: an id alone is a single point of failure, and
        // this module refuses to inherit that assumption from anybody.
        if (! hash_equals(
            Engineer888Access::CANONICAL_EMAIL,
            strtolower(trim((string) ($user->email ?? '')))
        )) {
            return null;
        }

        return self::USER_ID;
    }

    /** Every query in this module passes through here. */
    public static function scope(Builder $query): Builder
    {
        return $query->where('owner_user_id', self::USER_ID);
    }

    /** Is this the canonical owner? */
    public static function is(?Request $request): bool
    {
        return self::resolve($request) !== null;
    }
}
