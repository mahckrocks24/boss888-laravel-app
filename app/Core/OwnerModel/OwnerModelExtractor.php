<?php

namespace App\Core\OwnerModel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RFC-0023 P1. The stated lane: what the owner's own words say about identity, vision, goals, preferences, interests
 * and wants. Rules first (an "always / never / from now on" is kept at once as a confirmed, stated fact); then one
 * small constrained model call (T1) that may only quote the owner. Durable inferred facts are proposed, not confirmed.
 * Runs after the turn (OwnerModelJob), never on the chat critical path.
 */
final class OwnerModelExtractor
{
    private const DURABLE = '/\b(always|never|from now on|going forward|in future|don\'?t ever|stop (doing|sending|asking|using)|i prefer|i\'?d (rather|prefer)|we prefer|my goal|our goal|i want to (reach|hit|get to|grow|double|open|launch)|we want to (reach|hit|get to|grow|double|open|launch)|our vision|my vision|i am|i\'?m a|we are a|we\'?re a|our (customers|clients|guests) are|i (hate|love|like|dislike) (when|it when|the))\b/iu';
    private const SKIP = '/^\s*(yes|yep|yeah|ok|okay|no|nope|sure|thanks|thank you|go|go ahead|do it|approve|approved|skip|\d[\d,\s&and]*)\s*[.!]*\s*$/iu';

    public function __construct(private OwnerModelService $model) {}

    /** Extract and store; returns the facts written this turn (for the echo line). */
    public function run(int $wsId, ?int $bizId, int $messageId, string $text, string $previousSarahLine = ''): array
    {
        $t = trim($text);
        if ($t === '' || mb_strlen($t) < 12 || preg_match(self::SKIP, $t) || mb_strlen($t) > 2500) return [];
        $durable = (bool) preg_match(self::DURABLE, $t);
        $cands = $this->modelCandidates($wsId, $t, $previousSarahLine);
        if (! $cands && ! $durable) return [];
        if (! $cands && $durable) {
            // the model was unavailable: keep the owner's own sentence as a stated preference, verbatim
            $cands = [['group' => 'preferences', 'key' => 'standing_' . substr(md5($t), 0, 8), 'value' => mb_substr($t, 0, 300), 'durable' => true, 'quote' => mb_substr($t, 0, 120)]];
        }
        $written = [];
        foreach (array_slice($cands, 0, 6) as $c) {
            $g = (string) ($c['group'] ?? ''); $k = (string) ($c['key'] ?? ''); $v = trim((string) ($c['value'] ?? '')); $q = trim((string) ($c['quote'] ?? ''));
            if ($v === '' || $k === '' || ! in_array($g, OwnerModelService::GROUPS, true) || $g === 'behaviour' || $g === 'relationship') continue;
            // the quote must really be in the owner's words - the model may not invent
            if ($q !== '' && mb_stripos($t, mb_substr($q, 0, 24)) === false) continue;
            $isDurable = ! empty($c['durable']) || $durable;
            $stated = $q !== '' || preg_match(self::DURABLE, $v) || $isDurable;
            $r = $this->model->upsert($wsId, $bizId, $g, $k, $v, $stated ? 'stated' : 'inferred', $stated ? 0.9 : 0.6, 'agent_message:' . $messageId, $stated && $isDurable ? 'confirmed' : 'proposed', $isDurable ? null : 180);
            if (! empty($r['changed'])) $written[] = ['id' => $r['id'], 'group' => $g, 'key' => $k, 'value' => $v, 'status' => $r['status']];
        }
        $this->model->event($wsId, 'extract', ['message' => $messageId, 'written' => count($written), 'durable' => $durable]);
        return $written;
    }

    private function modelCandidates(int $wsId, string $text, string $previous): array
    {
        try {
            $runtime = app(\App\Connectors\RuntimeClient::class);
            if (! $runtime->isConfigured()) return [];
            $sys = "You read ONE message a small-business owner wrote to their marketing manager and extract only durable facts about the OWNER or THEIR BUSINESS that the manager should remember: who they are, their vision, goals (with numbers and dates when given), standing preferences (tone, channels, cadence, visuals, language, approval habits, money), interests, and open wants (things they asked for). "
                . "Rules: quote the owner's words; never invent; skip greetings, one-off task requests, questions, and anything about today's draft only. A fact is durable when it will still be true next month. "
                . "Output JSON: {\"facts\":[{\"group\":\"identity|goals|preferences|interests|wants\",\"key\":\"short_snake_case\",\"value\":\"one plain sentence in the third person\",\"durable\":true|false,\"quote\":\"the owner's exact words, up to 120 chars\"}]} with at most 5 facts, or {\"facts\":[]}.";
            $user = ($previous !== '' ? "MANAGER'S PREVIOUS LINE: " . mb_substr($previous, 0, 400) . "\n" : '') . "OWNER'S MESSAGE: " . mb_substr($text, 0, 1500);
            $r = $runtime->chatJson($sys, $user, ['task' => 'owner_model_extract', 'workspace_id' => (string) $wsId], 500);
            $facts = ($r['success'] ?? false) ? ($r['parsed']['facts'] ?? null) : null;
            return is_array($facts) ? array_values(array_filter($facts, 'is_array')) : [];
        } catch (\Throwable $e) {
            Log::info('[OWNER-MODEL] extractor model unavailable', ['ws' => $wsId, 'e' => $e->getMessage()]);
            return [];
        }
    }

    /** Sarah's one-line echo for facts just kept, posted after her reply. */
    public static function echoLine(array $written): ?string
    {
        $kept = array_values(array_filter($written, fn ($w) => $w['status'] === 'confirmed'));
        $guess = array_values(array_filter($written, fn ($w) => $w['status'] === 'proposed'));
        $parts = [];
        if ($kept) $parts[] = "I'll keep that: " . implode('; ', array_map(fn ($w) => lcfirst(rtrim(OwnerModelService::secondPerson($w['value']), '.')), array_slice($kept, 0, 3))) . '.';
        if ($guess) $parts[] = "I'm also noting, tell me if I'm wrong: " . implode('; ', array_map(fn ($w) => lcfirst(rtrim(OwnerModelService::secondPerson($w['value']), '.')), array_slice($guess, 0, 2))) . '.';
        if (! $parts) return null;
        return implode(' ', $parts) . " Say **no** if any of that is off, and **what do you remember about me** any time.";
    }
}
