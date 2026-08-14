<?php

namespace App\Core\Engineer888\Conversation\Providers;

use App\Core\Engineer888\Conversation\ConversationIntent;
use App\Core\Engineer888\Conversation\ConversationProvider;
use App\Core\Engineer888\Conversation\ConversationReply;
use App\Core\Engineer888\Conversation\ConversationRequest;
use Illuminate\Support\Facades\Http;

/**
 * Conversation over an OpenAI chat completion.
 *
 * Written from the interface and nothing else, in the same way
 * OpenAiReasoningProvider is: it shares no base class with the DeepSeek
 * provider even though the wire formats happen to resemble each other today.
 * A shared base would make one vendor's response shape the definition of
 * "correct", and the next provider that differs would be the one that has to
 * bend.
 *
 * TEXT OUT, NOT JSON. The reasoning provider asks for response_format
 * json_object because a candidate is a document. A conversational turn is
 * prose, and forcing it through JSON produced exactly the stilted register
 * this rebuild exists to remove. The optional structured intent is carried on a
 * trailing sentinel line instead, and is stripped before the human sees it.
 */
final class OpenAiConversationProvider implements ConversationProvider
{
    /** The model may end its reply with this; it never reaches the reader. */
    public const INTENT_PREFIX = '@@INTENT:';

    public function __construct(private readonly array $config = []) {}

    public function name(): string
    {
        return 'openai';
    }

    public function describe(): array
    {
        return [
            'model'    => (string) ($this->config['model'] ?? 'gpt-4o'),
            'endpoint' => (string) ($this->config['endpoint'] ?? 'https://api.openai.com'),
        ];
    }

    public function isAvailable(): bool
    {
        return trim($this->key()) !== '';
    }

    public function unavailableReason(): ?string
    {
        return $this->isAvailable() ? null : 'OPENAI_API_KEY is not configured in this environment';
    }

    public function converse(ConversationRequest $request): ConversationReply
    {
        $model = $this->describe()['model'];
        $started = microtime(true);

        $messages = [['role' => 'system', 'content' => $request->systemText()]];

        foreach ($request->normalisedHistory() as $m) {
            $messages[] = ['role' => $m['role'], 'content' => $m['body']];
        }

        $messages[] = ['role' => 'user', 'content' => $request->turn];

        $body = [
            'model'       => $model,
            'temperature' => (float) ($this->config['temperature'] ?? 0.4),
            'max_tokens'  => (int) ($this->config['max_tokens'] ?? 1200),
            'messages'    => $messages,
        ];

        // ── THE INTENT IS A FIELD, NOT PUNCTUATION (2026-08-14) ───────
        //
        // It used to be a sentinel line the model appended to its prose, and
        // whether Boss got his decisions depended on the model reproducing
        // "@@INTENT: NAME | subject" exactly. Measured in the browser: it
        // dropped the pipe, so the name came through as
        // SHOW_DECISIONSPASTEHEREONEBYONE and the structured branch never ran.
        //
        // Worse, it degraded. The sentinel is stripped before the turn is
        // persisted, so the model's own history shows it answering "what needs
        // my approval" with a bare promise and no intent line — three times
        // over, by the time this was diagnosed. Asked again through the live
        // path it emitted an intent 0 times in 3, while the same persona
        // against unpoisoned history emitted one 4 times in 4. Every failure
        // was teaching the next one.
        //
        // A channel the model can only see itself failing at is not a channel.
        // So the intent moves out of the prose entirely: a schema field, which
        // cannot lose its separator and cannot leak into what Boss reads.
        // splitIntent() below still runs over the reply text, both as the
        // fallback for a provider that returns plain prose and to strip a
        // sentinel if one is emitted anyway.
        if (($this->config['structured'] ?? true)) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name'   => 'engineer888_turn',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['reply', 'intent', 'subject'],
                        'properties' => [
                            'reply' => [
                                'type' => 'string',
                                'description' => 'What Boss reads. Never mention intents, never write out decisions as a numbered list or a table.',
                            ],
                            // DELIBERATELY NOT AN ENUM. The server owns the
                            // vocabulary and resolves against it; a name the
                            // model invents yields no intent rather than an
                            // error, which keeps an unknown claim harmless
                            // instead of making the whole turn fail.
                            'intent' => [
                                'type' => ['string', 'null'],
                                'description' => 'One of: ' . implode(', ', ConversationIntent::ALL)
                                    . '. Null when the turn is a question, an opinion, a status request or ordinary conversation.',
                            ],
                            'subject' => [
                                'type' => ['string', 'null'],
                                'description' => "Boss's own words describing which thing he means. A hint only; the server resolves what it refers to.",
                            ],
                        ],
                    ],
                ],
            ];
        }

        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 60))
                ->withHeaders(['Authorization' => 'Bearer ' . $this->key()])
                ->post(rtrim($this->describe()['endpoint'], '/') . '/v1/chat/completions', $body);
        } catch (\Throwable $e) {
            return ConversationReply::failure($this->name(), $model,
                get_class($e) . ': ' . $e->getMessage(),
                (int) round((microtime(true) - $started) * 1000));
        }

        $latency = (int) round((microtime(true) - $started) * 1000);

        if ($response->failed()) {
            // Status only. Provider error bodies have been observed to echo
            // request content, and this request carries engineering state.
            return ConversationReply::failure($this->name(), $model, 'HTTP ' . $response->status(), $latency);
        }

        $data = $response->json();
        $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

        if ($content === '') {
            return ConversationReply::failure($this->name(), $model, 'provider returned an empty reply', $latency);
        }

        [$text, $intent, $subject] = self::readTurn($content);

        return ConversationReply::success(
            $text, $this->name(), (string) ($data['model'] ?? $model), $latency,
            (array) ($data['usage'] ?? []), $intent, $subject
        );
    }

    /**
     * Read one turn out of whatever the provider returned.
     *
     * TWO SHAPES, ONE ANSWER. A structured reply carries the intent in its own
     * field; a plain-prose reply carries it as a sentinel, if at all. Both end
     * up as [text, intent, subject], and in both cases the intent is resolved
     * against ConversationIntent rather than trusted as written.
     *
     * The sentinel parse runs over the prose EITHER WAY. A structured reply
     * that also appends "@@INTENT: ..." out of habit must not show that line
     * to Boss — a leaked control token in a conversation about execution reads
     * like a command he was not meant to see.
     *
     * @return array{0:string,1:?string,2:?string}
     */
    public static function readTurn(string $content): array
    {
        $decoded = json_decode($content, true);

        // Plain prose: the legacy path, unchanged.
        if (! is_array($decoded) || ! array_key_exists('reply', $decoded)) {
            return self::splitIntent($content);
        }

        [$text, $sentinelIntent, $sentinelSubject] = self::splitIntent((string) $decoded['reply']);

        $intent = null;
        $subject = null;

        if (is_string($decoded['intent'] ?? null) && trim($decoded['intent']) !== '') {
            // Resolved, not trusted. A name the model invents yields nothing.
            [$intent, $fromName] = ConversationIntent::resolve(trim($decoded['intent']));
            $subject = is_string($decoded['subject'] ?? null) && trim($decoded['subject']) !== ''
                ? trim($decoded['subject'])
                : $fromName;
        }

        // A sentinel in the prose is the fallback, never the override.
        if ($intent === null && $sentinelIntent !== null) {
            $intent = $sentinelIntent;
            $subject ??= $sentinelSubject;
        }

        return [$text, $intent, $subject];
    }

    /**
     * Peel the advisory intent sentinel off the end of a reply.
     *
     * @return array{0:string,1:?string,2:?string}
     */
    public static function splitIntent(string $content): array
    {
        $lines = preg_split('/\R/', $content) ?: [];
        $intent = null;
        $subject = null;

        // TOLERANT ON THE WAY IN, ABSOLUTE ON THE WAY OUT.
        //
        // Measured 2026-08-13: asked for "@@INTENT: EXECUTE_REQUEST | ..." the
        // model emitted "@@EXECUTE_REQUEST | ...". A strict parser missed it,
        // so the governed escalation did not happen AND the raw sentinel was
        // printed to Boss. Both halves of that are unacceptable, and the second
        // is the worse one: a leaked control token in a conversation about
        // execution reads like a command he was not supposed to see.
        //
        // So any @@-prefixed line is recognised whatever follows the marker,
        // and EVERY @@-prefixed line is removed from the visible text even when
        // it parses to nothing. A sentinel never reaches the reader.
        //
        // ── AND THE SEPARATOR IS OPTIONAL TOO (2026-08-14) ────────────
        //
        // Measured in the browser: asked "what needs my approval, paste here
        // one by one" the model emitted
        //
        //     @@INTENT: SHOW_DECISIONS paste here one by one
        //
        // with no '|'. This split on the pipe, found none, took the whole tail
        // as the name and stripped its spaces out — SHOW_DECISIONSPASTEHEREONEBYONE,
        // which matches nothing downstream. Boss was told "I'll display them
        // here for you" above an empty space. Over four live runs of that turn
        // the pipe was present twice and absent twice.
        //
        // The name is no longer whatever the model typed. ConversationIntent
        // owns the closed set and resolves against it, longest name first,
        // separator or no separator; the remainder is a hint. A token outside
        // the set yields no intent at all, which is stricter than before, not
        // looser — this used to hand DEPLOY_EVERYTHING downstream as a real
        // intent name and rely on every consumer to not recognise it.
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || ! str_starts_with($trimmed, '@@')) { continue; }

            $raw = trim(substr($trimmed, 2));

            if (str_starts_with(strtoupper($raw), 'INTENT:')) {
                $raw = trim(substr($raw, 7));
            }

            [$name, $hint] = ConversationIntent::resolve($raw);

            if ($intent === null && $name !== null) {
                $intent = $name;
                $subject = $hint;
            }

            unset($lines[$i]);
        }

        return [trim(implode("\n", $lines)), $intent, $subject];
    }

    private function key(): string
    {
        return (string) (env('OPENAI_API_KEY') ?? '');
    }
}
