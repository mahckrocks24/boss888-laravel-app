<?php

namespace App\Core\Agents;

/**
 * Owner rule 2026-09-18 — the customer hears from ONE person: Sarah.
 *
 * Specialists (James, Priya, Elena, Marcus …) still do the work, but every proactive message that reaches the
 * customer — a delegation report, a link-insert notice, a timed follow-up, a push — is spoken by Sarah, in her
 * thread, under her name. The specialist is named inside the message ("James finished the job I passed along"),
 * never as the sender. Direct replies in a thread the customer opened with a specialist are not proactive and are
 * untouched. The companion app shows Sarah's thread only, so this is also what makes specialist work visible there.
 */
final class SarahVoice
{
    public const SLUG = 'sarah';
    public const NAME = 'Sarah';

    /**
     * Re-voice a proactive message from $fromSlug as Sarah.
     *
     * @return array{slug:string, sender:string, content:string, metadata:array<string,mixed>, relayed:bool}
     */
    public static function relay(string $fromSlug, string $fromName, string $content, array $metadata = []): array
    {
        $fromSlug = strtolower(trim($fromSlug));
        if ($fromSlug === self::SLUG || $fromSlug === '') {
            return ['slug' => self::SLUG, 'sender' => self::NAME, 'content' => $content, 'metadata' => $metadata, 'relayed' => false];
        }
        $name = trim($fromName) !== '' ? trim($fromName) : ucfirst($fromSlug);

        $metadata['relayed_from']   = $fromSlug;
        $metadata['relayed_sender'] = $name;
        $metadata['voiced_by']      = self::SLUG;

        return ['slug' => self::SLUG, 'sender' => self::NAME, 'content' => self::rephrase($name, $content), 'metadata' => $metadata, 'relayed' => true];
    }

    /** The specialist's own words become Sarah's account of them. */
    public static function rephrase(string $name, string $content): string
    {
        $c = ltrim($content);

        // "Sarah passed me 2 jobs — here's what's done:" → "James finished the 2 jobs I passed along — here's what's done:"
        $done = '/^Sarah passed me (\d+|a|one) (job|jobs)\s*[—-]+\s*here\'s what\'s done:/iu';
        if (preg_match($done, $c, $m)) {
            $n = strtolower($m[1]);
            $count = ($n === 'a' || $n === 'one' || $n === '1') ? 'the job' : 'the ' . $n . ' jobs';
            return preg_replace($done, $name . ' finished ' . $count . " I passed along — here's what's done:", $c, 1);
        }

        // "Sarah passed me 1 job to look at. Checked it — nothing needed changing, so I left things as they are."
        $checked = '/^Sarah passed me (\d+|a|one) (job|jobs) to look at\.\s*Checked (it|them)\s*[—-]+\s*nothing needed changing, so I left things as they are\.?/iu';
        if (preg_match($checked, $c, $m)) {
            $n = strtolower($m[1]);
            $one = ($n === 'a' || $n === 'one' || $n === '1');
            $count = $one ? 'the job' : 'the ' . $n . ' jobs';
            return preg_replace($checked, $name . ' checked ' . $count . ' I passed along — nothing needed changing, so ' . ($one ? 'it stays as it is.' : 'they stay as they are.'), $c, 1);
        }

        // Anything else is reported under the specialist's name, in Sarah's thread.
        return $name . " reports:\n\n" . $c;
    }
}
