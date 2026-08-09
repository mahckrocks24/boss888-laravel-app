<?php

namespace App\Core\Engineer888\Reasoning;

/**
 * What the model was actually looking at when it proposed the change.
 *
 * THE GAP THIS CLOSES. An approval bound the POST-image only: the sha256 of the
 * bytes a candidate proposes to write. That is half a contract. "Replace this
 * file with these bytes" is only a reviewable statement if you also know which
 * file the reviewer — and the model — believed they were replacing. Approve a
 * rewrite of a 13KB service, let another session rewrite that service first,
 * and the approved bytes would still install cleanly while silently discarding
 * work nobody agreed to discard.
 *
 * THE EVIDENCE IS NOT RE-READ, IT IS CARRIED. SourceGrounding already reads the
 * exact bytes handed to the provider and reports each path's sha256, size and
 * existence. That observation, at that instant, is the only honest pre-image.
 * Reading the file again later would describe a moment nobody reasoned about,
 * so this class never touches the filesystem — it only reshapes what grounding
 * already saw. Nothing in the Reasoning namespace may write or read a file, and
 * that boundary is enforced by test.
 *
 * ABSENCE IS EVIDENCE, AND IT IS NOT THE SAME AS IGNORANCE. A `create` whose
 * target was verified absent records existed=false with zero bytes: a positive
 * claim that nothing was there. A path the model was never grounded on records
 * grounded=false with nulls: a claim that we do not know. Collapsing those two
 * into one "no hash" state is exactly how an unverified assumption would come
 * to look like a verified one, so they are kept distinct in the stored evidence
 * and in the fingerprint.
 *
 * WHAT IT DELIBERATELY DOES NOT STORE. No file bodies — the hash and the length
 * settle identity and the body would put source into an approval row and into
 * fingerprint material. No timestamps, no absolute paths, no runtime state:
 * anything that varies between two identical situations would make the same
 * approval unreproducible.
 */
final class PreImage
{
    /** Bumping this invalidates every binding that carries pre-image evidence. */
    public const VERSION = 'e888-pre-image-v1';

    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const DELETE = 'delete';

    /** A declared action this class does not recognise. Recorded, never guessed. */
    public const UNKNOWN_OPERATION = 'unknown';

    public const OPERATIONS = [self::CREATE, self::UPDATE, self::DELETE];

    /** How a sha256 is spelled in a canonical part when there is genuinely none. */
    private const ABSENT = 'absent';

    /** How anything unobserved is spelled. Never confused with ABSENT. */
    private const UNKNOWN = 'unknown';

    /**
     * Turn one SourceGrounding observation into per-file pre-image evidence.
     *
     * The operation comes from the candidate's own declaration and the existence
     * from what grounding observed, and they are recorded separately on purpose.
     * A model that declares `create` for a file that already exists has produced
     * a disagreement worth keeping, not a value worth normalising away.
     *
     * @param  array<int,array{path?:string,sha256?:?string,bytes?:int,exists?:bool}> $grounded
     *         SourceGrounding::forPaths()['grounded'], verbatim
     * @param  array<int,array<string,mixed>> $fileChanges the candidate's file_changes
     * @return array<int,array<string,mixed>> one entry per changed path, path-ordered
     */
    public static function fromGrounding(array $grounded, array $fileChanges): array
    {
        $observed = [];
        foreach ($grounded as $entry) {
            if (! is_array($entry)) { continue; }
            $path = (string) ($entry['path'] ?? '');
            if ($path === '') { continue; }
            $observed[$path] = $entry;
        }

        $evidence = [];

        foreach ($fileChanges as $change) {
            if (! is_array($change)) { continue; }

            $path = (string) ($change['path'] ?? '');
            if ($path === '') { continue; }

            $operation = strtolower(trim((string) ($change['action'] ?? '')));
            if (! in_array($operation, self::OPERATIONS, true)) {
                $operation = self::UNKNOWN_OPERATION;
            }

            $source = $observed[$path] ?? null;

            if ($source === null) {
                // The model never received this file's bytes. Saying so is the
                // only truthful record available: hashing the file now would
                // describe a moment nobody reasoned against, and would let an
                // ungrounded proposal borrow the authority of a grounded one.
                $evidence[$path] = [
                    'path'             => $path,
                    'operation'        => $operation,
                    'grounded'         => false,
                    'existed'          => null,
                    'pre_image_sha256' => null,
                    'pre_image_bytes'  => null,
                ];

                continue;
            }

            $existed = (bool) ($source['exists'] ?? false);
            $sha = $source['sha256'] ?? null;

            $evidence[$path] = [
                'path'             => $path,
                'operation'        => $operation,
                'grounded'         => true,
                'existed'          => $existed,
                'pre_image_sha256' => $existed && is_string($sha) && $sha !== '' ? $sha : null,
                'pre_image_bytes'  => (int) ($source['bytes'] ?? 0),
            ];
        }

        ksort($evidence);

        return array_values($evidence);
    }

    /**
     * The deterministic strings an ApprovalBinding folds into its fingerprint.
     *
     * Empty in, empty out — and that is load-bearing. A candidate recorded before
     * this existed has no evidence, contributes no parts, and therefore keeps the
     * exact fingerprint it was approved under. Legacy approvals are not
     * reinterpreted; they simply carry less.
     *
     * @param  array<int,array<string,mixed>> $evidence
     * @return array<int,string>
     */
    public static function canonicalParts(array $evidence): array
    {
        $normalised = self::normalise($evidence);

        if ($normalised === []) { return []; }

        // The count is bound too, so removing a file's evidence entirely is a
        // different approval rather than a quieter one.
        $parts = [self::VERSION . ':files=' . count($normalised)];

        foreach ($normalised as $entry) { $parts[] = self::part($entry); }

        return $parts;
    }

    /**
     * Is this evidence a complete, positive pre-image for exactly these paths?
     *
     * False for a legacy candidate, for evidence that skips a changed file, fo
     * evidence describing a file the candidate does not change, and for any
     * entry the model was never grounded on. It is the honest answer to
     * "may a later gate compare the repository against what was approved?".
     *
     * @param array<int,array<string,mixed>> $evidence
     * @param array<int,string>              $paths the candidate's own changed paths
     */
    public static function covers(array $evidence, array $paths): bool
    {
        if ($evidence === [] || $paths === []) { return false; }

        $seen = [];
        foreach ($evidence as $entry) {
            if (! is_array($entry)) { return false; }

            $path = (string) ($entry['path'] ?? '');
            if ($path === '' || isset($seen[$path])) { return false; }
            if (! self::isBoundEntry($entry)) { return false; }

            $seen[$path] = true;
        }

        $wanted = array_values(array_unique(array_map('strval', $paths)));
        sort($wanted);

        $have = array_keys($seen);
        sort($have);

        return $wanted === $have;
    }

    /**
     * One entry's evidence, judged on its own declared operation.
     *
     * An update or a delete must name the bytes it is replacing or removing. A
     * create must positively show that nothing was there. Anything else — an
     * unrecognised operation, an ungrounded path, an update with no hash — is
     * not a pre-image, and is not quietly treated as one.
     *
     * @param array<string,mixed> $entry
     */
    public static function isBoundEntry(array $entry): bool
    {
        if (($entry['grounded'] ?? null) !== true) { return false; }

        $existed = $entry['existed'] ?? null;
        $sha = $entry['pre_image_sha256'] ?? null;
        $bytes = $entry['pre_image_bytes'] ?? null;

        return match ((string) ($entry['operation'] ?? '')) {
            self::CREATE => $existed === false && $sha === null && (int) $bytes === 0,
            self::UPDATE, self::DELETE => $existed === true
                && is_string($sha) && strlen($sha) === 64
                && is_int($bytes) && $bytes >= 0,
            default => false,
        };
    }

    /** A canonical part rendered back into a sentence a refusal can carry. */
    public static function describe(string $part): string
    {
        $prefix = 'pre-image:';
        if (! str_starts_with($part, $prefix)) { return $part; }

        $rest = substr($part, strlen($prefix));
        $colon = strpos($rest, ':');
        if ($colon === false) { return $part; }

        $length = (int) substr($rest, 0, $colon);
        $path = substr($rest, $colon + 1, $length);
        $fields = ltrim(substr($rest, $colon + 1 + $length), '|');

        return 'the source state of ' . $path . ' (' . str_replace('|', ', ', $fields) . ')';
    }

    /**
     * One entry as one unambiguous line.
     *
     * The path is length-prefixed rather than delimited. A path may contain any
     * character this format uses as a separator, and two different evidence sets
     * that hash to the same string would be a silent collision in the one place
     * a collision must not be possible.
     *
     * @param array<string,mixed> $entry
     */
    private static function part(array $entry): string
    {
        $path = (string) ($entry['path'] ?? '');
        $existed = $entry['existed'] ?? null;
        $sha = $entry['pre_image_sha256'] ?? null;
        $bytes = $entry['pre_image_bytes'] ?? null;

        return 'pre-image:' . strlen($path) . ':' . $path
             . '|op=' . (string) ($entry['operation'] ?? self::UNKNOWN_OPERATION)
             . '|grounded=' . (($entry['grounded'] ?? null) === true ? 'yes' : 'no')
             . '|existed=' . ($existed === null ? self::UNKNOWN : ($existed ? 'yes' : 'no'))
             . '|sha256=' . (is_string($sha) && $sha !== ''
                 ? $sha
                 : ($existed === false ? self::ABSENT : self::UNKNOWN))
             . '|bytes=' . ($bytes === null ? self::UNKNOWN : (string) (int) $bytes);
    }

    /**
     * Path-ordered, one entry per path, nothing malformed.
     *
     * Ordering is not cosmetic. Two runs that observed the same repository must
     * produce the same fingerprint regardless of the order the provider happened
     * to list its files in, and a reordering must never be able to change it.
     *
     * @param  array<int,array<string,mixed>> $evidence
     * @return array<int,array<string,mixed>>
     */
    private static function normalise(array $evidence): array
    {
        $byPath = [];

        foreach ($evidence as $entry) {
            if (! is_array($entry)) { continue; }
            $path = (string) ($entry['path'] ?? '');
            if ($path === '') { continue; }
            $byPath[$path] = $entry;
        }

        ksort($byPath);

        return array_values($byPath);
    }
}
