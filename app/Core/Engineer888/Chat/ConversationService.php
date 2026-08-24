<?php

namespace App\Core\Engineer888\Chat;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The canonical administrator's conversation with Engineer888.
 *
 * V1 HAS EXACTLY ONE. Not a list, not a thread per task — one continuous
 * conversation with the engineering department, the same on web and on mobile.
 * A list would imply a chooser, a chooser implies a conversation belonging to
 * somebody else, and there is nobody else.
 *
 * Every method takes the Request and re-derives the owner from it. None of them
 * accept an owner id as an argument: a parameter can be passed the wrong value,
 * and the only value that can be passed here is the one the request proves.
 */
final class ConversationService
{
    /**
     * The owner's conversation, created on first use.
     *
     * Idempotent under concurrency: web and mobile can both arrive first. The
     * insert is guarded by a transaction and a re-read, so two simultaneous
     * bootstraps cannot produce two conversations.
     */
    public function forOwner(?Request $request): object
    {
        $owner = ChatOwner::resolve($request);

        if ($owner === null) {
            ChatAbsent::throw('conversation requested by a non-canonical identity');
        }

        $existing = $this->firstForOwner();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () {
            // Re-read inside the transaction. Another surface may have created
            // it between the check above and this line.
            $again = $this->firstForOwner();

            if ($again !== null) {
                return $again;
            }

            $uuid = (string) Str::uuid();

            DB::table('e888_conversations')->insert([
                'uuid' => $uuid,
                'owner_user_id' => ChatOwner::USER_ID,
                'title' => 'Engineer888',
                'last_message_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('e888_conversations')->where('uuid', $uuid)->first();
        });
    }

    /**
     * Resolve a conversation the caller named by UUID.
     *
     * Owner-scoped in the query itself rather than fetched-then-checked. A row
     * belonging to somebody else is never loaded into memory at all, so there is
     * no window in which a later branch could return it by mistake.
     */
    public function byUuid(?Request $request, string $uuid): object
    {
        $owner = ChatOwner::resolve($request);

        if ($owner === null) {
            ChatAbsent::throw('conversation lookup by a non-canonical identity');
        }

        // A malformed UUID is answered exactly like a valid one that does not
        // exist. Validating the shape first and erroring differently would
        // separate "not a UUID" from "not yours".
        $row = ChatOwner::scope(
            DB::table('e888_conversations')->where('uuid', $uuid)
        )->first();

        if ($row === null) {
            ChatAbsent::throw('conversation uuid not found for owner');
        }

        return $row;
    }

    /** Mark everything the owner has now seen. */
    public function markRead(?Request $request, string $uuid): void
    {
        $conversation = $this->byUuid($request, $uuid);

        DB::transaction(function () use ($conversation) {
            ChatOwner::scope(
                DB::table('e888_messages')->where('conversation_id', $conversation->id)
            )->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

            ChatOwner::scope(
                DB::table('e888_conversations')->where('id', $conversation->id)
            )->update(['owner_last_read_at' => now(), 'updated_at' => now()]);
        });
    }

    /** Unread count for the owner, and nobody else's. */
    public function unreadCount(?Request $request): int
    {
        if (! ChatOwner::is($request)) {
            return 0;
        }

        return (int) ChatOwner::scope(DB::table('e888_messages'))
            ->where('role', '!=', 'user')
            ->whereNull('read_at')
            ->count();
    }

    private function firstForOwner(): ?object
    {
        return ChatOwner::scope(DB::table('e888_conversations'))
            ->orderBy('id')
            ->first();
    }
}
