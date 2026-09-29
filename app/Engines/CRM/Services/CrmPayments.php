<?php

namespace App\Engines\CRM\Services;

use App\Core\Email888\TenantEmail;
use App\Models\Activity;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * CRM-PACKS-4a: quotes, deposit requests and invoices from Clients.
 *
 * The owner writes the lines on the client's record; the client gets a white-label email from the business with a
 * link to a page under the business's own website address, where they accept the quote or pay. Payment is Stripe
 * Checkout on the business's own connected account (workspace_payment_accounts) — the money never touches the
 * platform. Paid or accepted moves the client forward in their business's stages, lands on the timeline, and Sarah
 * tells the owner. Without a connected Stripe account the request still goes out; the client confirms by reply.
 */
class CrmPayments
{
    private const ZERO_DECIMAL = ['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'HUF', 'TWD', 'UGX', 'XAF', 'XOF'];
    private const LABEL = ['quote' => 'Quote', 'deposit' => 'Deposit request', 'invoice' => 'Invoice'];
    private const PREFIX = ['quote' => 'Q', 'deposit' => 'D', 'invoice' => 'INV'];

    public function account(int $ws): ?object
    {
        $a = DB::table('workspace_payment_accounts')->where('workspace_id', $ws)->first();
        return $a && in_array($a->status, ['active', 'no_webhook'], true) ? $a : null;
    }

    public function money(string $cur, float $v): string
    {
        $sym = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED ', 'PHP' => '₱', 'CAD' => 'C$', 'AUD' => 'A$', 'SGD' => 'S$', 'INR' => '₹', 'NZD' => 'NZ$', 'ZAR' => 'R'][strtoupper($cur)] ?? (strtoupper($cur) . ' ');
        return $sym . number_format($v, in_array(strtoupper($cur), self::ZERO_DECIMAL, true) ? 0 : 2);
    }

    /** The address the client sees: the business's own website (verified custom domain, else its site), else the app. */
    private function base(int $ws, ?int $bizId): string
    {
        $id = TenantEmail::identity($ws, $bizId);
        return rtrim((string) ($id['website'] ?: config('app.url')), '/');
    }

    public function link(object $p): string
    {
        return $this->base((int) $p->workspace_id, $p->business_id ? (int) $p->business_id : null) . '/api/public/pay/' . $p->token;
    }

    /** Create (and optionally send) a request. $items = [{description, qty, unit}] */
    public function create(int $ws, int $leadId, array $data, ?int $userId): array
    {
        $lead = DB::table('leads')->where('workspace_id', $ws)->where('id', $leadId)->whereNull('deleted_at')->first();
        if (! $lead) return ['success' => false, 'error' => 'Client not found.'];
        $kind = in_array(($data['kind'] ?? ''), ['quote', 'deposit', 'invoice'], true) ? $data['kind'] : 'invoice';
        $items = [];
        foreach ((array) ($data['items'] ?? []) as $it) {
            $desc = mb_substr(trim((string) ($it['description'] ?? '')), 0, 200);
            $qty = max(0, (float) ($it['qty'] ?? 1)); $unit = max(0, round((float) ($it['unit'] ?? 0), 2));
            if ($desc !== '' && $qty > 0) $items[] = ['description' => $desc, 'qty' => $qty, 'unit' => $unit];
        }
        if (! $items) return ['success' => false, 'error' => 'Add at least one line with a description and an amount.'];
        $total = round(array_sum(array_map(fn ($i) => $i['qty'] * $i['unit'], $items)), 2);
        if ($total <= 0) return ['success' => false, 'error' => 'The total must be more than zero.'];
        $acct = $this->account($ws);
        $cur = strtoupper((string) ($data['currency'] ?? '') ?: ($acct->currency ?? 'USD'));
        $n = (int) DB::table('crm_payment_requests')->where('workspace_id', $ws)->where('kind', $kind)->count() + 1;
        $id = DB::table('crm_payment_requests')->insertGetId([
            'workspace_id' => $ws, 'business_id' => $lead->business_id, 'lead_id' => $lead->id, 'kind' => $kind,
            'number' => self::PREFIX[$kind] . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'title' => mb_substr(trim((string) ($data['title'] ?? '')) ?: (self::LABEL[$kind] . ' for ' . $lead->name), 0, 190),
            'items_json' => json_encode($items, JSON_UNESCAPED_UNICODE), 'currency' => $cur, 'total' => $total,
            'due_date' => ! empty($data['due_date']) ? substr((string) $data['due_date'], 0, 10) : null, 'note' => ! empty($data['note']) ? mb_substr((string) $data['note'], 0, 2000) : null,
            'status' => 'draft', 'token' => Str::random(40), 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $out = ['success' => true, 'id' => $id];
        if (! empty($data['send'])) $out += $this->send($ws, $id, $userId);
        return $out;
    }

    public function send(int $ws, int $id, ?int $userId): array
    {
        $p = DB::table('crm_payment_requests')->where('workspace_id', $ws)->where('id', $id)->first();
        if (! $p) return ['sent' => false, 'error' => 'Not found.'];
        $lead = DB::table('leads')->where('id', $p->lead_id)->first();
        $to = ClientIdentity::emailKey($lead->email ?? null);
        if (! $to) return ['sent' => false, 'error' => 'This client has no email address. Add one, or share the link another way.', 'link' => $this->link($p)];
        $tid = TenantEmail::identity($ws, $p->business_id ? (int) $p->business_id : null);
        $label = self::LABEL[$p->kind];
        $canPay = $p->kind !== 'quote' && $this->account($ws) !== null;
        $first = trim(explode(' ', (string) $lead->name)[0]) ?: 'there';
        $lines = '';
        foreach (json_decode((string) $p->items_json, true) as $it) {
            $lines .= '<tr><td style="padding:10px 0;border-bottom:1px solid #E5E7EB;color:#111827">' . e($it['description']) . ($it['qty'] != 1 ? ' <span style="color:#6B7280">× ' . e((string) (float) $it['qty']) . '</span>' : '') . '</td>'
                . '<td style="padding:10px 0;border-bottom:1px solid #E5E7EB;text-align:right;color:#111827;white-space:nowrap">' . e($this->money($p->currency, $it['qty'] * $it['unit'])) . '</td></tr>';
        }
        $body = '<p style="margin:0 0 14px">Hi ' . e($first) . ',</p>'
            . '<p style="margin:0 0 18px">' . e($p->kind === 'quote' ? 'Here is your quote. You can accept it online in one click.' : ($p->kind === 'deposit' ? 'Here is the deposit to secure your booking.' : 'Here is your invoice.') . ($canPay ? ' You can pay securely online.' : '')) . '</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font:15px/1.5 Arial,Helvetica,sans-serif;margin:0 0 6px">' . $lines
            . '<tr><td style="padding:12px 0 0;font-weight:700;color:#111827">Total</td><td style="padding:12px 0 0;text-align:right;font-weight:700;color:#111827">' . e($this->money($p->currency, (float) $p->total)) . '</td></tr></table>'
            . ($p->due_date ? '<p style="margin:14px 0 0;color:#6B7280;font-size:13px">' . ($p->kind === 'quote' ? 'Valid until ' : 'Due by ') . e(date('j M Y', strtotime($p->due_date))) . '</p>' : '')
            . ($p->note ? '<p style="margin:14px 0 0">' . nl2br(e($p->note)) . '</p>' : '')
            . (! $canPay && $p->kind !== 'quote' ? '<p style="margin:14px 0 0">Reply to this email and we will arrange payment with you.</p>' : '');
        $cta = ['url' => $this->link($p), 'label' => $p->kind === 'quote' ? 'View and accept' : ($canPay ? 'Pay ' . $this->money($p->currency, (float) $p->total) : 'View ' . strtolower($label))];
        try {
            $html = TenantEmail::layout($tid, $label . ' ' . $p->number, $body, $cta, null, $label . ' ' . $p->number . ' · ' . $this->money($p->currency, (float) $p->total),
                ['eyebrow' => $label, 'hero' => false, 'signoff' => 'The ' . $tid['name'] . ' team', 'reason' => 'You are receiving this because you asked ' . $tid['name'] . ' for this.']);
            $plain = "Hi {$first},\n\n{$label} {$p->number}: " . $this->money($p->currency, (float) $p->total) . "\n" . $this->link($p) . "\n\n" . $tid['name'];
            Mail::html($html, function ($m) use ($plain, $ws, $p, $tid, $to, $lead, $label) {
                $m->text($plain);
                $h = $m->getSymfonyMessage()->getHeaders();
                $h->addTextHeader(\App\Core\Email888\OutboundPolicy::HDR_PURPOSE, 'tenant_transactional');
                $h->addTextHeader(\App\Core\Email888\OutboundPolicy::HDR_WORKSPACE, (string) $ws);
                if ($p->business_id) $h->addTextHeader(\App\Core\Email888\OutboundPolicy::HDR_BUSINESS, (string) $p->business_id);
                $m->from($tid['address'], $tid['name'])->to($to, $lead->name ?: null)->subject($label . ' ' . $p->number . ' from ' . $tid['name']);
                if (! empty($tid['reply_to'])) $m->replyTo($tid['reply_to'], $tid['name']);
            });
        } catch (\Throwable $e) {
            try { app(\App\Core\Email888\DeliveryLedger::class)->markLastRecordedFailed($e); } catch (\Throwable $x) {}
            return ['sent' => false, 'error' => 'The email could not be sent.', 'link' => $this->link($p)];
        }
        DB::table('crm_payment_requests')->where('id', $p->id)->update(['status' => $p->status === 'draft' ? 'sent' : $p->status, 'sent_at' => now(), 'updated_at' => now()]);
        Activity::create(['workspace_id' => $ws, 'activitable_type' => 'Lead', 'activitable_id' => (int) $p->lead_id, 'type' => 'email', 'completed' => 1, 'performed_by' => $userId,
            'subject' => 'Sent ' . strtolower($label) . ' ' . $p->number . ': ' . $this->money($p->currency, (float) $p->total), 'metadata_json' => ['payment_request_id' => (int) $p->id]]);
        DB::table('leads')->where('id', $p->lead_id)->update(['last_contacted_at' => now()]);
        // a quote sent moves a new client to the pack's quote stage (never backwards)
        if ($p->kind === 'quote') $this->advance($ws, (int) $p->lead_id, ['quote_sent', 'proposal', 'offer'], 'qualified');
        return ['sent' => true, 'link' => $this->link($p)];
    }

    /** Move the client to the first of $keys their pack has, only forward. */
    private function advance(int $ws, int $leadId, array $keys, string $fallbackStatus): void
    {
        $lead = DB::table('leads')->where('id', $leadId)->first();
        if (! $lead) return;
        $pack = CrmPacks::forBusiness($lead->business_id ? (int) $lead->business_id : null);
        $stage = null;
        foreach ($keys as $k) if (($s = collect($pack['stages'])->firstWhere('key', $k))) { $stage = $s; break; }
        if (! $stage) $stage = collect($pack['stages'])->firstWhere('status', $fallbackStatus);
        if (! $stage) return;
        $rank = ['lost' => -1, 'new' => 0, 'contacted' => 1, 'qualified' => 2, 'converted' => 3];
        $curIdx = collect($pack['stages'])->search(fn ($s) => $s['key'] === CrmPacks::stageOf($pack, $lead->stage, $lead->status));
        $newIdx = collect($pack['stages'])->search(fn ($s) => $s['key'] === $stage['key']);
        if (($rank[$stage['status']] ?? 0) < ($rank[$lead->status] ?? 0) || ($newIdx !== false && $curIdx !== false && $newIdx <= $curIdx && $lead->status !== 'lost')) return;
        app(CrmService::class)->updateLead($leadId, ['status' => $stage['status']], null, $ws);
        DB::table('leads')->where('id', $leadId)->update(['stage' => $stage['key'], 'updated_at' => now()]);
    }

    // ── the client's side ────────────────────────────────────────────────────
    public function byToken(string $token): ?object
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) return null;
        return DB::table('crm_payment_requests')->where('token', $token)->first();
    }

    public function viewed(object $p): void
    {
        if (! $p->viewed_at) DB::table('crm_payment_requests')->where('id', $p->id)->update(['viewed_at' => now(), 'status' => in_array($p->status, ['sent', 'draft'], true) ? 'viewed' : $p->status]);
    }

    public function accept(object $p): void
    {
        if ($p->kind !== 'quote' || in_array($p->status, ['accepted', 'paid', 'cancelled'], true)) return;
        DB::table('crm_payment_requests')->where('id', $p->id)->update(['status' => 'accepted', 'accepted_at' => now(), 'updated_at' => now()]);
        $this->recordOutcome($p, 'accepted', (float) $p->total);
    }

    /** Stripe Checkout on the business's own account for the request's total. */
    public function checkout(object $p): array
    {
        if ($p->status === 'paid') return ['success' => false, 'message' => 'This has already been paid. Thank you!'];
        if ($p->status === 'cancelled') return ['success' => false, 'message' => 'This request was withdrawn.'];
        $a = $this->account((int) $p->workspace_id);
        if (! $a) return ['success' => false, 'message' => 'Online payment is not available for this request. Please reply to the email to arrange payment.'];
        $cur = strtolower($p->currency);
        $amount = in_array(strtoupper($cur), self::ZERO_DECIMAL, true) ? (int) round((float) $p->total) : (int) round((float) $p->total * 100);
        $back = $this->link($p);
        try {
            $s = (new \Stripe\StripeClient(Crypt::decryptString($a->secret_key)))->checkout->sessions->create([
                'mode' => 'payment',
                'line_items' => [['quantity' => 1, 'price_data' => ['currency' => $cur, 'unit_amount' => $amount, 'product_data' => ['name' => mb_substr(self::LABEL[$p->kind] . ' ' . $p->number . ' · ' . $p->title, 0, 120)]]]],
                'success_url' => $back . '/done?session={CHECKOUT_SESSION_ID}', 'cancel_url' => $back,
                'customer_email' => ClientIdentity::emailKey(DB::table('leads')->where('id', $p->lead_id)->value('email')) ?: null,
                'metadata' => ['workspace_id' => (string) $p->workspace_id, 'payment_request_id' => (string) $p->id, 'source' => 'levelupgrowth_crm'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[CRM-PAY] checkout failed', ['request' => $p->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'The payment page could not be opened just now. Please try again in a moment.'];
        }
        DB::table('crm_payment_requests')->where('id', $p->id)->update(['session_id' => $s->id, 'updated_at' => now()]);
        return ['success' => true, 'url' => $s->url];
    }

    /** Webhook or return: the Checkout session was paid. */
    public function markPaidBySession(int $ws, string $sessionId, float $amountPaid = 0): ?array
    {
        $p = DB::table('crm_payment_requests')->where('workspace_id', $ws)->where('session_id', $sessionId)->first();
        if (! $p) return null;
        if ($p->status === 'paid') return ['ok' => true, 'reason' => 'already'];
        $paid = $amountPaid > 0 ? $amountPaid : (float) $p->total;
        DB::table('crm_payment_requests')->where('id', $p->id)->update(['status' => 'paid', 'paid_at' => now(), 'paid_amount' => $paid, 'updated_at' => now()]);
        $this->recordOutcome($p, 'paid', $paid);
        return ['ok' => true, 'reason' => 'paid'];
    }

    /** Ask Stripe (the return path when no webhook arrived). */
    public function confirm(object $p, string $sessionId): bool
    {
        if ($p->status === 'paid') return true;
        if ($p->session_id !== $sessionId || ! ($a = $this->account((int) $p->workspace_id))) return false;
        try { $s = (new \Stripe\StripeClient(Crypt::decryptString($a->secret_key)))->checkout->sessions->retrieve($sessionId, []); } catch (\Throwable $e) { return false; }
        if (($s->payment_status ?? '') !== 'paid') return false;
        $zero = in_array(strtoupper($p->currency), self::ZERO_DECIMAL, true);
        $this->markPaidBySession((int) $p->workspace_id, $sessionId, $s->amount_total !== null ? ($zero ? (float) $s->amount_total : $s->amount_total / 100) : 0);
        return true;
    }

    private function recordOutcome(object $p, string $what, float $amount): void
    {
        $ws = (int) $p->workspace_id; $label = self::LABEL[$p->kind];
        $lead = DB::table('leads')->where('id', $p->lead_id)->first();
        $money = $this->money($p->currency, $amount);
        Activity::create(['workspace_id' => $ws, 'activitable_type' => 'Lead', 'activitable_id' => (int) $p->lead_id, 'type' => 'payment', 'completed' => 1,
            'subject' => ($what === 'paid' ? 'Paid ' . $money . ' · ' . strtolower($label) . ' ' . $p->number : 'Accepted quote ' . $p->number . ' (' . $money . ')'),
            'metadata_json' => ['payment_request_id' => (int) $p->id, 'amount' => $amount, 'currency' => $p->currency, 'what' => $what]]);
        if ($what === 'paid') DB::table('leads')->where('id', $p->lead_id)->update(['deal_value' => DB::raw('COALESCE(deal_value,0) + ' . (float) $amount), 'updated_at' => now()]);
        if ($what === 'accepted') DB::table('leads')->where('id', $p->lead_id)->update(['deal_value' => DB::raw('GREATEST(COALESCE(deal_value,0), ' . (float) $p->total . ')')]);
        $keys = $what === 'accepted' ? ['won', 'closed', 'confirmed', 'enrolled', 'booked']
            : ($p->kind === 'deposit' ? ['deposit', 'won', 'booked', 'confirmed', 'enrolled'] : ['paid', 'won', 'closed', 'completed', 'visited', 'enrolled', 'regular']);
        $this->advance($ws, (int) $p->lead_id, $keys, 'converted');
        try {
            app(SarahClients::class)->say($ws, 'crm_payment', 'Tell the owner in one short, warm sentence what the client just did. No greeting.',
                ['client' => $lead->name ?? 'A client', 'did' => $what === 'paid' ? 'paid online' : 'accepted the quote', 'amount' => $money, 'what' => strtolower($label) . ' ' . $p->number],
                ($lead->name ?? 'A client') . ($what === 'paid' ? " just paid {$money} for " . strtolower($label) . " {$p->number}." : " accepted quote {$p->number} ({$money})."),
                ['lead_id' => (int) $p->lead_id, 'action_url' => '/app/crm/' . (int) $p->lead_id], '');
        } catch (\Throwable $e) {}
    }

    /** The client's page: white-label, the business's colours, one clear action. */
    public function page(object $p, string $notice = ''): string
    {
        $tid = TenantEmail::identity((int) $p->workspace_id, $p->business_id ? (int) $p->business_id : null);
        $kit = $tid['kit'] ?? [];
        $c = (is_string($kit['primary_color'] ?? null) && preg_match('/^#[0-9a-f]{6}$/i', $kit['primary_color']) && ! \App\Core\Brand\WorkspaceBrandKitResolver::isPlatformColor($kit['primary_color'])) ? $kit['primary_color'] : '#111827';
        $label = self::LABEL[$p->kind];
        $rows = '';
        foreach (json_decode((string) $p->items_json, true) as $it) $rows .= '<tr><td>' . e($it['description']) . ($it['qty'] != 1 ? ' <span class="m">× ' . e((string) (float) $it['qty']) . '</span>' : '') . '</td><td class="r">' . e($this->money($p->currency, $it['qty'] * $it['unit'])) . '</td></tr>';
        $canPay = $p->kind !== 'quote' && $this->account((int) $p->workspace_id) !== null;
        $state = match ($p->status) {
            'paid' => '<div class="ok">Paid ' . e($this->money($p->currency, (float) ($p->paid_amount ?: $p->total))) . ' on ' . e(date('j M Y', strtotime((string) $p->paid_at))) . '. Thank you!</div>',
            'accepted' => '<div class="ok">You accepted this quote on ' . e(date('j M Y', strtotime((string) $p->accepted_at))) . '. We will be in touch with the next step.</div>',
            'cancelled' => '<div class="warn">This request was withdrawn. Please contact us if you have questions.</div>',
            default => $p->kind === 'quote'
                ? '<form method="post" action="' . e($this->link($p)) . '/accept"><button type="submit">Accept this quote</button></form>'
                : ($canPay ? '<form method="post" action="' . e($this->link($p)) . '/checkout"><button type="submit">Pay ' . e($this->money($p->currency, (float) $p->total)) . ' securely</button></form><p class="m c">Card payment is handled by Stripe.</p>'
                          : '<p>Please reply to our email to arrange payment.</p>'),
        };
        $foot = implode(' · ', array_filter([e($tid['name']), $tid['phone'] ? e($tid['phone']) : null, $tid['website'] ? '<a href="' . e($tid['website']) . '">' . e(preg_replace('#^https?://#', '', $tid['website'])) . '</a>' : null]));
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . e($label . ' ' . $p->number . ' · ' . $tid['name']) . '</title>'
            . '<style>*{box-sizing:border-box}body{margin:0;background:#F3F4F6;font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;color:#1F2937}'
            . '.w{max-width:620px;margin:0 auto;padding:32px 16px}.card{background:#fff;border:1px solid #E5E7EB;border-radius:16px;overflow:hidden}.bar{height:5px;background:' . $c . '}'
            . '.in{padding:28px}.biz{font:700 20px Georgia,serif;color:#111827;margin:0 0 18px}.k{font:700 12px/1 sans-serif;letter-spacing:.12em;text-transform:uppercase;color:' . $c . '}'
            . 'h1{font:700 26px/1.2 Georgia,serif;margin:8px 0 4px;color:#111827}.m{color:#6B7280;font-size:14px}.c{text-align:center}table{width:100%;border-collapse:collapse;margin:20px 0 8px}'
            . 'td{padding:12px 0;border-bottom:1px solid #E5E7EB}.r{text-align:right;white-space:nowrap}tr.t td{border:0;font-weight:700;font-size:18px;padding-top:14px}'
            . 'button{width:100%;margin-top:18px;background:' . $c . ';color:#fff;border:0;border-radius:999px;padding:15px 20px;font:600 16px sans-serif;cursor:pointer;min-height:52px}button:focus-visible{outline:3px solid #93C5FD;outline-offset:2px}'
            . '.ok{margin-top:18px;background:#ECFDF5;color:#065F46;border-radius:12px;padding:14px 16px}.warn{margin-top:18px;background:#FEF2F2;color:#991B1B;border-radius:12px;padding:14px 16px}'
            . '.f{text-align:center;color:#6B7280;font-size:13px;margin-top:18px}.f a{color:#6B7280}</style></head><body><main class="w"><div class="card"><div class="bar"></div><div class="in">'
            . '<div class="biz">' . e($tid['name']) . '</div><div class="k">' . e($label) . ' ' . e($p->number) . '</div><h1>' . e($p->title) . '</h1>'
            . ($p->due_date ? '<div class="m">' . ($p->kind === 'quote' ? 'Valid until ' : 'Due by ') . e(date('j M Y', strtotime($p->due_date))) . '</div>' : '')
            . ($notice ? '<div class="warn">' . e($notice) . '</div>' : '')
            . '<table>' . $rows . '<tr class="t"><td>Total</td><td class="r">' . e($this->money($p->currency, (float) $p->total)) . '</td></tr></table>'
            . ($p->note ? '<p>' . nl2br(e($p->note)) . '</p>' : '') . $state . '</div></div><div class="f">' . $foot . '</div></main></body></html>';
    }
}
