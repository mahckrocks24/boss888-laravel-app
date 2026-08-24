<?php

namespace App\Core\Engineer888\Chat;

/**
 * One canonical form for a payload hash map.
 *
 * WHY THIS EXISTS. Card validation compared the JSON string PHP produced at
 * issue time against the JSON string the database returned at press time, and
 * refused every card. The data was identical; the BYTES were not. The column is
 * native MySQL `json`, which normalises on storage and returns `{"a": "b"}`
 * — with a space after the colon — where PHP emits `{"a":"b"}`. 76 bytes
 * against 77, diverging at byte 9: 0x22 versus 0x20.
 *
 * Comparing serialised forms across a storage boundary compares the storage
 * engine's formatting preferences as much as the payload. So nothing compares
 * serialised forms any more: both sides are decoded to data, canonicalised
 * here, and compared as one deterministic string.
 *
 * THE CONTRACT
 *   1. The same logical payload always produces identical bytes.
 *   2. Ordering is deterministic — keys sorted, recursively.
 *   3. An empty map has exactly one representation: "{}".
 *   4. Lists and maps never interchange: a list stays an array.
 *   5. Keys are compared as strings, so numeric-looking keys are stable.
 *   6. Encoding flags are fixed here and nowhere else.
 *   7. Issue, persist, validate and audit all call this, so none can drift.
 *   8. Database casting cannot alter the canonical form, because the canonical
 *      form is computed from decoded data rather than from stored text.
 *   9. The browser never supplies or computes it.
 */
final class PayloadCanonical
{
    /** Fixed everywhere. Slashes and unicode unescaped so the form is stable across drivers. */
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * The canonical string for a payload map.
     *
     * Accepts what any of the layers might hold: an array, a JSON string from
     * the database, null, or an object from a driver that hydrates JSON.
     */
    public static function encode(mixed $value): string
    {
        return (string) json_encode(self::normalise(self::decode($value)), self::FLAGS);
    }

    /** A short stable digest, for comparison and for audit. */
    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    /** Do these two represent the same payload, whatever form each arrived in? */
    public static function matches(mixed $a, mixed $b): bool
    {
        return hash_equals(self::encode($a), self::encode($b));
    }

    /** Whatever the layer handed us, get back to data. */
    private static function decode(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        if (is_object($value)) {
            return (array) $value;
        }

        return is_array($value) ? $value : [];
    }

    /**
     * Deterministic ordering, recursively.
     *
     * A list keeps its order — position is meaning in a list. A map is sorted by
     * key as a string, so two maps built in different insertion orders collapse
     * to the same bytes. An empty array is emitted as an object, because these
     * payloads are maps and "no files" must not encode as "[]" one moment and
     * "{}" the next depending on how it was built.
     */
    private static function normalise(array $value): array|object
    {
        if ($value === []) {
            return new \stdClass();
        }

        if (array_is_list($value)) {
            return array_map(
                fn ($v) => is_array($v) ? self::normalise($v) : $v,
                $value
            );
        }

        $keys = array_map('strval', array_keys($value));
        $out = array_combine($keys, array_values($value));
        ksort($out, SORT_STRING);

        foreach ($out as $k => $v) {
            if (is_array($v)) {
                $out[$k] = self::normalise($v);
            }
        }

        return (object) $out;
    }
}
