<?php

namespace Tests\Feature\Platform;

use App\Core\Engineer888\Chat\PayloadCanonical;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The payload canonicalization contract.
 *
 * The defect these pin: card validation compared the JSON string PHP produced
 * at issue time against the string the database returned at press time. The
 * column is native MySQL `json`, which returns `{"a": "b"}` where PHP emits
 * `{"a":"b"}` — 77 bytes against 76, diverging at byte 9 (0x20 vs 0x22).
 * Identical data, different bytes, every card refused.
 *
 * So the rule is: never compare serialised forms across a storage boundary.
 * Decode, canonicalise, compare.
 */
class PayloadCanonicalTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_map_has_exactly_one_representation(): void
    {
        // "no files" must not encode as [] one moment and {} the next.
        $this->assertSame('{}', PayloadCanonical::encode([]));
        $this->assertSame('{}', PayloadCanonical::encode(null));
        $this->assertSame('{}', PayloadCanonical::encode(''));
        $this->assertSame('{}', PayloadCanonical::encode('[]'));
        $this->assertSame('{}', PayloadCanonical::encode('{}'));
        $this->assertSame('{}', PayloadCanonical::encode(new \stdClass()));
    }

    public function test_one_file_map(): void
    {
        $this->assertSame('{"a.php":"h1"}', PayloadCanonical::encode(['a.php' => 'h1']));
    }

    public function test_insertion_order_does_not_change_the_bytes(): void
    {
        $a = ['z.php' => 'h3', 'a.php' => 'h1', 'm.php' => 'h2'];
        $b = ['a.php' => 'h1', 'm.php' => 'h2', 'z.php' => 'h3'];

        $this->assertSame(PayloadCanonical::encode($a), PayloadCanonical::encode($b));
        $this->assertTrue(PayloadCanonical::matches($a, $b));
        $this->assertSame('{"a.php":"h1","m.php":"h2","z.php":"h3"}', PayloadCanonical::encode($a));
    }

    public function test_nested_maps_are_ordered_recursively(): void
    {
        $a = ['b' => ['z' => 1, 'a' => 2], 'a' => 3];
        $b = ['a' => 3, 'b' => ['a' => 2, 'z' => 1]];

        $this->assertTrue(PayloadCanonical::matches($a, $b));
    }

    public function test_lists_keep_their_order_because_position_is_meaning(): void
    {
        $this->assertSame('["a","b"]', PayloadCanonical::encode(['a', 'b']));
        $this->assertNotSame(
            PayloadCanonical::encode(['a', 'b']),
            PayloadCanonical::encode(['b', 'a'])
        );
    }

    public function test_a_changed_hash_changes_the_canonical_form(): void
    {
        $this->assertFalse(PayloadCanonical::matches(
            ['a.php' => 'h1'],
            ['a.php' => 'h1-CHANGED']
        ));
    }

    public function test_numeric_looking_keys_are_stable_as_strings(): void
    {
        $a = ['10' => 'x', '2' => 'y'];
        $b = ['2' => 'y', '10' => 'x'];

        $this->assertTrue(PayloadCanonical::matches($a, $b));
        $this->assertSame('{"10":"x","2":"y"}', PayloadCanonical::encode($a));
    }

    public function test_encoding_is_not_double_applied(): void
    {
        $once = PayloadCanonical::encode(['a.php' => 'h1']);

        // Feeding the canonical string back in must be a no-op, not a re-encode.
        $this->assertSame($once, PayloadCanonical::encode($once));
        $this->assertStringNotContainsString('\\"', $once);
    }

    /**
     * The regression itself: a real MySQL json round trip.
     */
    public function test_a_database_round_trip_preserves_the_canonical_form(): void
    {
        $map = ['b.php' => 'h2', 'a.php' => 'h1'];

        $conversationId = DB::table('e888_conversations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'owner_user_id' => 1,
            'title' => 'fixture', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('e888_action_cards')->insert([
            'uuid' => (string) Str::uuid(), 'conversation_id' => $conversationId,
            'owner_user_id' => 1, 'action_type' => 'approve_candidate',
            'file_hashes_json' => PayloadCanonical::encode($map),
            'issued_to_user_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $stored = DB::table('e888_action_cards')->orderByDesc('id')->value('file_hashes_json');

        // MySQL reformats it — the raw bytes differ from what PHP wrote.
        $this->assertNotSame(json_encode($map), (string) $stored,
            'if these ever match, MySQL stopped normalising and this test is no longer proving anything');

        // The canonical form survives regardless.
        $this->assertTrue(PayloadCanonical::matches($map, $stored),
            'issue-time and stored representations must canonicalise identically');
        $this->assertSame(PayloadCanonical::encode($map), PayloadCanonical::encode($stored));
        $this->assertSame(PayloadCanonical::hash($map), PayloadCanonical::hash($stored));
    }

    public function test_an_empty_map_survives_the_round_trip_as_an_empty_map(): void
    {
        $conversationId = DB::table('e888_conversations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'owner_user_id' => 1,
            'title' => 'fixture', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('e888_action_cards')->insert([
            'uuid' => (string) Str::uuid(), 'conversation_id' => $conversationId,
            'owner_user_id' => 1, 'action_type' => 'approve_candidate',
            'file_hashes_json' => PayloadCanonical::encode([]),
            'issued_to_user_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $stored = DB::table('e888_action_cards')->orderByDesc('id')->value('file_hashes_json');

        $this->assertTrue(PayloadCanonical::matches([], $stored));
        $this->assertSame('{}', PayloadCanonical::encode($stored));
    }
}
