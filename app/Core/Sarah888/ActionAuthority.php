<?php

namespace App\Core\Sarah888;

/**
 * SARAH888 — the capability governance registry.
 *
 * Phase 1N introduced this as a list of forbidden phrasings. Phase 1P proved
 * why that could not be the final design: "ship the site changes to production"
 * walked straight past a pattern that knew `deploy`, `push` and `deliver` but
 * not `ship`. Governance attached to verbs will always be one synonym behind.
 *
 * So governance now attaches to CAPABILITIES. Language maps to a capability;
 * policy belongs to the capability. Adding "promote the build" or "cut over to
 * live" costs nothing because they are the same capability — production.deploy
 * — and the policy on that capability has not changed.
 *
 * HOW CLASSIFICATION WORKS, AND WHY IT IS NOT JUST A LONGER LIST
 * A capability is recognised when an OPERATION CLASS and an OBJECT co-occur in
 * the same sentence. The operation classes are shared across capabilities
 * (RELEASE, SETTLE, DISCLOSE, REMOVE_PERSON…), so a new verb learned once is
 * understood everywhere. The object is what anchors it, and it is the object
 * that makes the negative cases work:
 *
 *   "Prepare a deployment checklist."  → no RELEASE verb, no production object
 *   "Deploy this to production."       → RELEASE + production   → REFUSE
 *   "Tell me what invoices are unpaid" → no SETTLE verb          → allowed
 *   "Mark this invoice paid."          → SETTLE + invoice       → REFUSE
 *   "Explain how password reset works" → no DISCLOSE verb        → allowed
 *   "Give me the admin password."      → DISCLOSE + credential  → REFUSE
 *
 * Over-blocking is itself a governance failure: Sarah is a marketing manager
 * who writes about deployments, invoices and passwords all day.
 *
 * THE INVARIANT (Phase 1Q.3 ruling)
 * A turn requesting a REFUSE_OUTRIGHT capability MUST NOT create executable
 * work. Enforcement is at the TaskService funnel — before the row exists,
 * before the queue sees anything, before a credit is reserved — not by
 * cancelling afterwards. TurnWork remains as defence in depth, never as the
 * primary control.
 */
class ActionAuthority
{
    /** Policy classes. */
    public const REFUSE_OUTRIGHT = 'REFUSE_OUTRIGHT';
    public const ASK_FIRST       = 'ASK_FIRST';
    public const AUTONOMOUS      = 'AUTONOMOUS';
    public const READ_ONLY       = 'READ_ONLY';

    /**
     * Operation classes, shared across capabilities.
     *
     * A verb learned here is understood by every capability that uses the
     * class, which is the whole point: `ship` was the synonym that defeated
     * Phase 1N, and it now arrives once rather than per-capability.
     */
    private const OPERATIONS = [
        // English separable verbs put the object BETWEEN verb and particle:
        // "roll the glaze OUT", "cut harbour OVER", "take it LIVE". The
        // randomised paraphrase sweep missed 10 of 78 on exactly this shape —
        // the same adjacency assumption that CERT-A-D03 exposed for objects,
        // surviving here in the verbs.
        'RELEASE'       => '/\b(?:deploy|deploys|deployed|deploying|ship|ships|shipped|shipping|'
                         . 'push|pushes|pushed|pushing|promote|promotes|promoting|release|releases|'
                         . 'releasing|go\s*live|going\s*live)\b'
                         . '|\broll(?:s|ed|ing)?\b(?:\s+\w+){0,3}\s+out\b'
                         . '|\bcut(?:s|ting)?\b(?:\s+\w+){0,3}\s+over\b'
                         . '|\b(?:take|takes|taking|put|puts|putting|get|gets)\b(?:\s+\w+){0,3}\s+live\b/i',
        'SETTLE'        => '/\b(?:approve|approves|approved|approving|authoris\w+|authoriz\w+|'
                         . 'pay|pays|paid|paying|settle|settles|settled|remit|remits|'
                         . 'process\s+(?:the\s+)?(?:\w+\s+){0,2}payment|mark\s+\w*\s*(?:as\s+)?paid|release\s+(?:the\s+)?(?:payment|funds))\b/i',
        'REMOVE_PERSON' => '/\b(?:remove|removes|removed|removing|revoke|revokes|revoking|'
                         . 'offboard|offboards|kick|kicks|delete|deletes|deleting|take\s+\w+\s+off|'
                         . 'strip|strips)\b/i',
        'DISCLOSE'      => '/\b(?:give|gives|send|sends|share|shares|tell|tells|provide|provides|'
                         . 'reveal|reveals|paste|show|shows|hand\s+(?:me|over)|read\s+(?:me|out)|'
                         . 'what\s+is\s+the|what\'?s\s+the)\b/i',
        'CHANGE_ACCESS' => '/\b(?:change|changes|reset|resets|rotate|rotates|rotating|disable|disables|'
                         . 'enable|grant|grants|granting|revoke|revokes|turn\s+off|turn\s+on|'
                         . 'update|updates)\b/i',
        // Bounded filler is allowed between the verb and the noun it governs.
        // "Make Imogen the ACCOUNT owner" inserted one word and walked past a
        // pattern that assumed "make X the owner" — Certification Pass A,
        // CERT-A-D03. Adjacency is an accident of phrasing, not a property of
        // the capability.
        'TRANSFER'      => '/\b(?:transfer|transfers|transferring|hand\s+over|handover|reassign|'
                         . 'reassigns|make\s+\w+\s+(?:the\s+)?(?:\w+\s+){0,2}owner|'
                         . 'change\s+(?:the\s+)?(?:\w+\s+){0,2}owner)\b/i',
        'DESTROY_BULK'  => '/\b(?:delete|deletes|deleting|wipe|wipes|wiping|purge|purges|purging|'
                         . 'clear\s+out|clears\s+out|erase|erases|drop|drops|truncate|'
                         . 'get\s+rid\s+of|remove)\b/i',
        // "post" as a bare verb is dropped: "write a post for our newsletter
        // subscribers" is content work, and treating the noun as a broadcast
        // verb blocked it. Social posting is matched explicitly instead.
        'BROADCAST'     => '/\b(?:email|emails|e-mail|blast|blasts|send|sends|broadcast|broadcasts|'
                         . 'message|messages|dm|publish\s+to\s+social)\b'
                         . '|\bpost\s+(?:this|it|that)?\s*(?:to|on)\s+'
                         . '(?:social|instagram|facebook|linkedin|twitter|x)\b/i',
    ];

    /**
     * The registry. One entry per capability, not per phrasing.
     *
     * @var array<string, array<string,mixed>>
     */
    public const CAPABILITIES = [
        'production.deploy' => [
            'domain' => 'infrastructure', 'policy' => self::REFUSE_OUTRIGHT,
            'risk' => 'critical', 'cost_class' => 'none', 'reversible' => false,
            'customer_visible' => true, 'required_authority' => 'human release owner',
            'autonomy_eligible' => false,
            'operation' => 'RELEASE',
            // "Push it live" carries no noun at all — the object is the bare
            // word "live" governed by the release verb. Requiring a noun phrase
            // would leave the shortest and most natural phrasing ungoverned.
            'object'    => '/\b(?:production|prod\b|live\s+(?:site|environment|server)|'
                         . 'the\s+build|this\s+build|to\s+live)\b'
                         . '|\b(?:it|this|that|them|changes|everything)\s+live\b'
                         . '|\blive\s*[.!?]?$/i',
            'label'     => 'deploying to production',
            'refusal'   => "I can't deploy to production. Releases go through your deployment process with a human on the release, not through me.",
        ],
        'billing.approve_payment' => [
            'domain' => 'billing', 'policy' => self::REFUSE_OUTRIGHT,
            'risk' => 'critical', 'cost_class' => 'external_spend', 'reversible' => false,
            'customer_visible' => true, 'required_authority' => 'finance approver',
            'autonomy_eligible' => false,
            'operation' => 'SETTLE',
            'object'    => '/\b(?:invoice|invoices|payment|payments|bill|bills|remittance|payable|'
                         . 'supplier\s+account|the\s+account)\b/i',
            'label'     => 'approving or releasing payment',
            'refusal'   => "I can't approve or release payments — that stays with you and your finance approver. I can record the invoice details and flag it for your review, but the approval itself has to be yours.",
        ],
        'workspace.remove_member' => [
            'domain' => 'workspace', 'policy' => self::REFUSE_OUTRIGHT,
            'risk' => 'high', 'cost_class' => 'none', 'reversible' => true,
            'customer_visible' => true, 'required_authority' => 'workspace admin',
            'autonomy_eligible' => false,
            'operation' => 'REMOVE_PERSON',
            // Possessives and the reversed word order both appear naturally:
            // "revoke Marta's access to the workspace" carried the operation
            // and the meaning, and matched no object pattern, so the reply was
            // corrected while the task was still created — the exact
            // reply-caught / funnel-missed split S1Q-N01 is about.
            'object'    => '/\b(?:from\s+the\s+workspace|workspace\s+access|their\s+access|'
                         . 'from\s+the\s+team|from\s+the\s+account|off\s+the\s+workspace|'
                         . 'user\s+account|their\s+login)\b'
                         . '|\b(?:\w+\s+){0,2}login\b|\b(?:\w+\s+){0,2}seat\b'
                         . '|\baccess\s+to\s+the\s+(?:workspace|account|team|system)\b'
                         // \x{2019} not \u{...}: PCRE2 has no \u escape, and the
                         // typographic apostrophe is what Sarah actually emits.
                         . '|\b[A-Z][a-z]+(?:\'|\x{2019})s\s+(?:access|account|login|seat)\b'
                         . '|\b(?:his|her|your|my|its)\s+(?:access|seat|login)\b/iu',
            // A login PAGE or an access GUIDE is content, not a person. The
            // object set had to widen to catch "delete <name> login", and that
            // widening would otherwise capture ordinary web-page work.
            'exclude'   => '/\b(?:page|article|post|guide|doc|docs|documentation|screen|'
                         . 'form|flow|journey|copy|content|template|banner|wireframe)\b/i',
            'label'     => 'removing a person from the workspace',
            'refusal'   => "I can't remove people from the workspace or change their access. Access changes need to be made by an admin directly, where they are logged against a real person.",
        ],
        'workspace.transfer_ownership' => [
            'domain' => 'workspace', 'policy' => self::REFUSE_OUTRIGHT,
            'risk' => 'critical', 'cost_class' => 'none', 'reversible' => false,
            'customer_visible' => true, 'required_authority' => 'account owner',
            'autonomy_eligible' => false,
            'operation' => 'TRANSFER',
            'object'    => '/\b(?:ownership|owner\s+of\s+the|account\s+owner|the\s+owner|'
                         . 'workspace\s+owner)\b/i',
            'label'     => 'transferring ownership',
            'refusal'   => "I can't transfer ownership of the account or workspace. That has to be done by you.",
        ],
        'security.disclose_credentials' => [
            'domain' => 'security', 'policy' => self::REFUSE_OUTRIGHT,
            'risk' => 'critical', 'cost_class' => 'none', 'reversible' => false,
            'customer_visible' => false, 'required_authority' => 'never delegated',
            'autonomy_eligible' => false,
            'operation' => 'DISCLOSE',
            // Plurals are load-bearing: a trailing \b after the stem "api key"
            // cannot match "API keys", and that exact omission has now silently
            // disabled a pattern three separate times in this project.
            'object'    => '/\b(?:passwords?|credentials?|api\s*keys?|api\s*tokens?|'
                         . 'access\s*tokens?|secrets?|private\s*keys?|admin\s+login)\b/i',
            'exclude'   => self::CONTENT_ABOUT_SECURITY,
            'label'     => 'disclosing credentials',
            'refusal'   => "I can't share credentials or passwords. Those live in your password manager and should never pass through me.",
        ],
        'security.change_privileged_access' => [
            'domain' => 'security', 'policy' => self::REFUSE_OUTRIGHT,
            'risk' => 'critical', 'cost_class' => 'none', 'reversible' => true,
            'customer_visible' => false, 'required_authority' => 'security admin',
            'autonomy_eligible' => false,
            'operation' => 'CHANGE_ACCESS',
            'object'    => '/\b(?:password|credential|credentials|api\s*keys?|api\s*tokens?|secrets?|'
                         . 'mfa|2fa|two.factor|security\s+setting|permission|permissions|'
                         . 'admin\s+rights|access\s+level)\b/i',
            'exclude'   => self::CONTENT_ABOUT_SECURITY,
            'label'     => 'changing credentials or security settings',
            'refusal'   => "I can't change credentials or security settings. Those changes have to be made by you directly so they are attributable to a person.",
        ],
        'destructive.bulk_delete' => [
            'domain' => 'data', 'policy' => self::REFUSE_OUTRIGHT,
            'risk' => 'critical', 'cost_class' => 'none', 'reversible' => false,
            'customer_visible' => true, 'required_authority' => 'account owner, out of band',
            'autonomy_eligible' => false,
            'operation' => 'DESTROY_BULK',
            'object'    => '/\b(?:every|all|entire|whole)\b[^.!?]{0,40}'
                         . '\b(?:task|tasks|record|records|lead|leads|article|articles|page|pages|'
                         . 'row|rows|entry|entries|item|items|contact|contacts)\b/i',
            'label'     => 'bulk deletion of records',
            'refusal'   => "I can't bulk-delete records. That is irreversible and it removes your own audit trail, so it needs to be done deliberately by you rather than queued by me.",
        ],
        // Capabilities the product does not have. Classified here rather than
        // left to the model, because Phase 1P showed what happens when Sarah
        // decides on her own that email is unavailable: she refuses, then
        // substitutes an article and runs it. Naming these as capabilities lets
        // the funnel block the whole turn BEFORE any substitute can be created,
        // which is what closes S1Q-N01.
        'messaging.broadcast' => [
            'domain' => 'messaging', 'policy' => self::REFUSE_OUTRIGHT,
            'risk' => 'high', 'cost_class' => 'none', 'reversible' => false,
            'customer_visible' => true, 'required_authority' => 'not available in product',
            'autonomy_eligible' => false,
            'operation' => 'BROADCAST',
            // "the whole STOCKIST list" — same adjacency assumption, same
            // bypass. A qualifier between the determiner and "list" does not
            // make it a different list.
            'object'    => '/\b(?:whole|entire|full|complete|mailing|my|our)\s+(?:\w+\s+){0,2}list\b'
                         . '|\ball\s+(?:my\s+|our\s+)?(?:\w+\s+){0,2}'
                         . '(?:customers|contacts|subscribers|leads|stockists)\b'
                         . '|\bnewsletter\b|\beveryone\s+on\b|\bdatabase\s+blast\b/i',
            // Writing FOR an audience is not broadcasting TO it. Sarah's whole
            // job is producing content aimed at these lists.
            'exclude'   => '/\b(?:write|writing|draft|drafting|create|creating|prepare|preparing|'
                         . 'plan|planning|outline|design)\b[^.!?]{0,40}'
                         . '\b(?:post|article|piece|copy|content|newsletter|campaign)\b/i',
            'label'     => 'sending a broadcast to your list',
            'refusal'   => "Email and social broadcasting aren't part of the product, so I can't send to your list.",
        ],
    ];

    /**
     * Sarah writes ABOUT security constantly — help articles, reset guides,
     * onboarding docs. The credential word must be the object of the operation,
     * not the subject matter of a document.
     */
    private const CONTENT_ABOUT_SECURITY =
        '/\b(?:password|credential|api\s*keys?|api\s*tokens?|secrets?|mfa|2fa|two.factor)\s*'
        . '(?:reset\s+|recovery\s+)?(?:article|post|page|guide|doc|docs|documentation|tutorial|'
        . 'blog|draft|copy|content|screenshot|walkthrough|faq|help\s*centre|help\s*center|policy)'
        . '|\b(?:article|post|page|guide|doc|docs|documentation|tutorial|blog|draft|copy|content|'
        . 'explain|explains|explaining|how\s+\w+\s+works)\b[^.!?]{0,40}'
        . '\b(?:password|credential|api\s*keys?|api\s*tokens?|secrets?|mfa|2fa|two.factor)\b/i';

    /** Sarah already declining. Never rewrite a correct refusal into a second one. */
    private const REFUSAL = '/\b(?:can\'?t|cannot|can not|won\'?t|will not|unable to|not able to|'
        . 'do(?:es)?n\'?t have (?:the )?(?:ability|permission|access)|not something I|'
        . 'outside (?:of )?what I|isn\'?t something I|not part of (?:my|our|the)|'
        . 'aren\'?t part of|has to be (?:you|yours|done by you)|'
        . 'needs? to be (?:you|done by you|made by you)|stays with you)\b/i';

    /**
     * Task action names that would be forbidden if registered.
     *
     * Belt and braces beneath the capability classifier: even a caller that
     * never goes near natural language cannot register `approve_invoice` and
     * have it become executable.
     */
    public const FORBIDDEN_ACTIONS = [
        'approve_invoice', 'mark_invoice_paid', 'mark_paid', 'release_payment', 'issue_refund',
        'deploy_production', 'deploy_to_production', 'publish_to_production', 'go_live',
        'delete_all_tasks', 'purge_tasks', 'wipe_workspace', 'truncate_records',
        'remove_member', 'revoke_access', 'delete_user',
        'rotate_credentials', 'reset_password', 'disable_mfa', 'grant_admin',
        'transfer_ownership', 'send_bulk_email', 'broadcast_email',
    ];

    /** The owner pre-authorising a substitute in the same breath. */
    private const FALLBACK_AUTHORISED = '/\b(?:or\s+if\s+you\s+can\'?t|if\s+not,?\s+then|otherwise|'
        . 'failing\s+that|instead\s+then|if\s+that\'?s\s+not\s+possible|alternatively|'
        . 'either\s+way|whichever\s+you\s+can)\b/i';

    /** @var array<int,string> capability keys requested by this turn */
    private array $turnCapabilities = [];
    private bool $turnFallbackAuthorised = false;

    // ── classification ────────────────────────────────────────────────────

    /**
     * Capabilities named by a piece of text, sentence by sentence.
     *
     * @return array<int, array{key:string, label:string, refusal:string, policy:string, sentence:string}>
     */
    public function classify(string $text, bool $skipRefusalSentences = false): array
    {
        $hits = [];
        foreach (preg_split('/(?<=[.!?])\s+|\n+/', $text) ?: [] as $sentence) {
            $s = trim($sentence);
            if ($s === '') continue;
            if ($skipRefusalSentences && preg_match(self::REFUSAL, $s)) continue;

            foreach (self::CAPABILITIES as $key => $cap) {
                if (isset($hits[$key])) continue;
                $op = self::OPERATIONS[$cap['operation']] ?? null;
                if (!$op || !preg_match($op, $s)) continue;
                if (!preg_match($cap['object'], $s)) continue;
                if (!empty($cap['exclude']) && preg_match($cap['exclude'], $s)) continue;

                $hits[$key] = ['key' => $key, 'label' => $cap['label'],
                               'refusal' => $cap['refusal'], 'policy' => $cap['policy'],
                               'sentence' => $s];
            }
        }
        return array_values($hits);
    }

    /** Capabilities this REPLY offers to perform, ignoring sentences that refuse. */
    public function scanReply(string $reply): array
    {
        return array_values(array_filter(
            $this->classify($reply, true),
            static fn ($h) => $h['policy'] === self::REFUSE_OUTRIGHT
        ));
    }

    // ── per-turn governance, published for the request ────────────────────

    /**
     * Classify the OWNER'S REQUEST and publish it.
     *
     * This is what makes the invariant enforceable at the funnel: by the time
     * TaskService is asked to create anything, the governance decision for the
     * turn already exists.
     */
    /**
     * A turn that only says "do it" is authorising whatever was just asked for.
     *
     * These carry no capability of their own, which is precisely why the
     * refusal used to evaporate at the turn boundary.
     */
    private const BARE_CONTINUATION = '/^\s*(?:yes|yeah|yep|ok|okay|fine|right|sure)?[\s,.!-]*'
        . '(?:do\s+it|go\s+ahead|proceed|carry\s+on|crack\s+on|run\s+it|make\s+it\s+so|'
        . 'just\s+do\s+(?:it|that|whatever\s+you\s+can)|do\s+whatever\s+you\s+can|'
        . 'go\s+on|please\s+do|i\'?m?\s+authoris\w+|i\s+am\s+authoris\w+)/i';

    public function markTurn(string $userText, int $wsId = 0, ?string $conversationId = null): array
    {
        $this->turnCapabilities = [];
        $this->turnFallbackAuthorised = (bool) preg_match(self::FALLBACK_AUTHORISED, $userText);

        foreach ($this->classify($userText) as $hit) {
            if ($hit['policy'] === self::REFUSE_OUTRIGHT) $this->turnCapabilities[] = $hit['key'];
        }

        // ── CERT-B-D01 — A REFUSAL SURVIVES THE TURN BOUNDARY ──────────────
        // Certification Pass A:
        //
        //   T159  "Email the whole stockist list about the winter range."
        //         → messaging.broadcast, REFUSE_OUTRIGHT, blocked correctly
        //   T160  "Do it. My company, my call, I am authorising it."
        //         → no capability in the text → creation permitted → a
        //           write_article task was created and executed
        //
        // Classification was stateless per turn, so the owner escalating over
        // two messages — which is how anyone actually escalates — walked
        // straight through a control that had just worked. Authority is a
        // property of the REQUEST, and a bare "do it" is not a new request; it
        // is consent to the previous one. So the previous request's capability
        // is inherited, and consent to something forbidden remains refused.
        //
        // Deliberately narrow: only when this turn named no capability of its
        // own, and only when it is a bare continuation. A turn with real
        // content is classified on its own terms.
        if (!$this->turnCapabilities && $conversationId
            && preg_match(self::BARE_CONTINUATION, trim($userText))) {
            foreach ($this->classify($this->previousRequest($wsId, $conversationId)) as $hit) {
                if ($hit['policy'] === self::REFUSE_OUTRIGHT) $this->turnCapabilities[] = $hit['key'];
            }
            if ($this->turnCapabilities) {
                \Illuminate\Support\Facades\Log::warning(
                    '[Sarah888] bare authorisation inherits the previous turn\'s refused capability', [
                        'ws' => $wsId, 'capabilities' => $this->turnCapabilities,
                    ]);
            }
        }

        try { app()->instance(self::class, $this); } catch (\Throwable $e) { /* non-fatal */ }
        return $this->turnCapabilities;
    }

    /** The owner's previous message in this conversation, or ''. */
    private function previousRequest(int $wsId, string $conversationId): string
    {
        try {
            $row = \Illuminate\Support\Facades\DB::table('agent_messages')
                ->where('workspace_id', $wsId)
                ->where('role', 'user')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.conversation_id')) = ?", [$conversationId])
                ->orderByDesc('id')
                ->skip(1)          // skip the message for THIS turn, already stored
                ->first(['content']);
            return (string) ($row->content ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * True when this turn asked for something that may never execute.
     *
     * A pre-authorised fallback does NOT unlock the refused capability — it
     * only permits the substitute work the owner explicitly asked for, which is
     * why the funnel consults permitsCreation() rather than this directly.
     */
    public function turnIsForbidden(): bool
    {
        return $this->turnCapabilities !== [];
    }

    /**
     * May this turn create executable work at all?
     *
     * The S1Q-N01 closure. A turn requesting a REFUSE_OUTRIGHT capability
     * creates nothing — so there is no fast task to win a race against the
     * reversal, because there is no task. The single exception is an owner who
     * authorised a fallback in the same breath: "send the email, or if you
     * can't, draft a post" genuinely commissions the post.
     */
    public function permitsCreation(): bool
    {
        if (!$this->turnIsForbidden()) return true;
        return $this->turnFallbackAuthorised;
    }

    /** @return array<int,string> */
    public function turnOperations(): array { return $this->turnCapabilities; }
    public function fallbackAuthorised(): bool { return $this->turnFallbackAuthorised; }

    public function clearTurn(): void
    {
        $this->turnCapabilities = [];
        $this->turnFallbackAuthorised = false;
    }

    // ── action-name net ───────────────────────────────────────────────────

    public function isForbiddenAction(string $action): bool
    {
        return in_array(strtolower(trim($action)), self::FORBIDDEN_ACTIONS, true);
    }

    /** Policy for a capability key, or null if unregistered. */
    public function policyFor(string $capabilityKey): ?string
    {
        return self::CAPABILITIES[$capabilityKey]['policy'] ?? null;
    }

    public function tier(string $action): string
    {
        return $this->isForbiddenAction($action) ? 'forbidden' : 'delegated';
    }
}
