<?php

namespace App\Core\Lifecycle;

/**
 * MAIL-BRAND-2 (Owner 2026-09-28: "6/10 … more artistic and well thought out … enterprise quality"). The one design
 * every platform email uses:
 *   an illustrated hero per email type (public/email/hero-*.jpg), an eyebrow, an editorial headline and lead, then
 *   purpose-built components — numbered steps, stat tiles, plan cards, a callout — one pill button, Sarah's portrait
 *   in the sign-off, and a quiet footer. Table-based and inline-styled for Gmail, Outlook, Apple Mail and Yahoo; Inter
 *   where the client allows web fonts; a plain-text twin (text()).
 *
 * $o keys (all optional): hero (name), eyebrow, heading, lead, greeting, paragraphs[], steps[[title, text]],
 * stats[[value, label]], plans[[name, price, detail, badge|null]], callout, list[], button[label, url],
 * secondary[label, url], after[], signoff ('sarah'|'team'), tone ('security'), reason, preferences, unsubscribe.
 */
final class EmailLayout
{
    public const PURPLE = '#5B4BE8';
    private const INK = '#14112E';
    private const BODY = '#40445A';
    private const MUTED = '#7A7F96';
    private const TINT = '#F5F3FF';
    private const LINE = '#E9E6F8';
    private const FONT = "Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    public static function appUrl(): string
    {
        return rtrim((string) (config('app.customer_url') ?: config('app.url')), '/');
    }

    public static function render(array $o): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $base = self::appUrl();
        $F = self::FONT; $P = self::PURPLE; $INK = self::INK; $BODY = self::BODY; $MUT = self::MUTED; $TINT = self::TINT; $LINE = self::LINE;
        $sec = ($o['tone'] ?? '') === 'security';
        $pad = 'padding:0 44px';
        $rows = '';

        if (! empty($o['hero'])) {
            $rows .= '<tr><td style="padding:0;line-height:0;font-size:0"><img src="' . $e($base . '/email/hero-' . $o['hero'] . '.jpg?v=2') . '" width="600" alt="" style="display:block;width:100%;max-width:600px;height:auto;border:0;border-radius:24px 24px 0 0"></td></tr>';
        }
        $rows .= '<tr><td style="' . $pad . ';padding-top:40px">';
        if (! empty($o['eyebrow'])) $rows .= '<div style="font:700 12px/16px ' . $F . ';letter-spacing:1.6px;text-transform:uppercase;color:' . ($sec ? '#B42318' : $P) . ';margin:0 0 14px">' . $e($o['eyebrow']) . '</div>';
        if (! empty($o['heading'])) $rows .= '<h1 style="margin:0 0 16px;font:800 30px/37px ' . $F . ';letter-spacing:-0.6px;color:' . $INK . '">' . $e($o['heading']) . '</h1>';
        if (! empty($o['lead'])) $rows .= '<p style="margin:0 0 22px;font:400 18px/28px ' . $F . ';color:' . $BODY . '">' . $o['lead'] . '</p>';
        if (! empty($o['greeting']) && empty($o['lead'])) $rows .= '<p style="margin:0 0 14px;font:600 16px/26px ' . $F . ';color:' . $INK . '">' . $e($o['greeting']) . '</p>';
        foreach ((array) ($o['paragraphs'] ?? []) as $p) $rows .= '<p style="margin:0 0 16px;font:400 16px/26px ' . $F . ';color:' . $BODY . '">' . $p . '</p>';
        $rows .= '</td></tr>';

        // stat tiles
        if (! empty($o['stats'])) {
            $cells = ''; $n = count($o['stats']);
            foreach (array_values($o['stats']) as $i => [$v, $l]) {
                $cells .= '<td valign="top" width="' . floor(100 / $n) . '%" style="padding:' . ($i ? '0 0 0 10px' : '0') . '"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td valign="top" height="96" style="background:' . $TINT . ';border-radius:16px;padding:20px 18px;height:96px;box-sizing:border-box">'
                    . '<div style="font:800 32px/36px ' . $F . ';letter-spacing:-0.8px;color:' . $INK . '">' . $e($v) . '</div>'
                    . '<div style="font:600 12px/16px ' . $F . ';letter-spacing:0.6px;text-transform:uppercase;color:' . $MUT . ';margin-top:6px">' . $e($l) . '</div></td></tr></table></td>';
            }
            $rows .= '<tr><td style="' . $pad . ';padding-top:6px;padding-bottom:22px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $cells . '</tr></table></td></tr>';
        }

        // numbered steps
        if (! empty($o['steps'])) {
            $s = '';
            foreach (array_values($o['steps']) as $i => [$title, $text]) {
                $s .= '<tr><td valign="top" width="44" style="padding:0 0 18px"><div style="width:32px;height:32px;border-radius:16px;background:' . $TINT . ';color:' . $P . ';font:800 15px/32px ' . $F . ';text-align:center">' . ($i + 1) . '</div></td>'
                    . '<td valign="top" style="padding:4px 0 18px"><div style="font:700 16px/22px ' . $F . ';color:' . $INK . '">' . $title . '</div>'
                    . ($text !== '' ? '<div style="font:400 15px/23px ' . $F . ';color:' . $BODY . ';margin-top:3px">' . $text . '</div>' : '') . '</td></tr>';
            }
            $rows .= '<tr><td style="' . $pad . ';padding-top:4px;padding-bottom:6px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $s . '</table></td></tr>';
        }

        // check list
        if (! empty($o['list'])) {
            $s = '';
            foreach ((array) $o['list'] as $li) $s .= '<tr><td valign="top" width="30" style="padding:1px 0 12px"><div style="width:20px;height:20px;border-radius:10px;background:' . ($sec ? '#FEE4E2' : '#E7F8F0') . ';color:' . ($sec ? '#B42318' : '#12805C') . ';font:800 12px/20px ' . $F . ';text-align:center">' . ($sec ? '!' : '&#10003;') . '</div></td><td style="padding:0 0 12px;font:400 16px/24px ' . $F . ';color:' . $BODY . '">' . $li . '</td></tr>';
            $rows .= '<tr><td style="' . $pad . ';padding-bottom:10px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $s . '</table></td></tr>';
        }

        // callout
        if (! empty($o['callout'])) {
            $rows .= '<tr><td style="' . $pad . ';padding-bottom:22px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="background:' . ($sec ? '#FFF6F5' : $TINT) . ';border-radius:16px;padding:18px 20px;font:500 15px/24px ' . $F . ';color:' . $INK . '">' . $o['callout'] . '</td></tr></table></td></tr>';
        }

        // plan cards, two per row
        if (! empty($o['plans'])) {
            $cards = array_values($o['plans']); $grid = '';
            for ($i = 0; $i < count($cards); $i += 2) {
                $grid .= '<tr>';
                foreach ([0, 1] as $k) {
                    $c = $cards[$i + $k] ?? null;
                    if (! $c) { $grid .= '<td width="50%"></td>'; continue; }
                    [$name, $price, $detail, $badge] = array_pad($c, 4, null);
                    $hl = $badge !== null && $badge !== '';
                    $grid .= '<td width="50%" valign="top" style="padding:' . ($k ? '0 0 12px 6px' : '0 6px 12px 0') . '"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="border:' . ($hl ? '2px solid ' . $P : '1px solid ' . $LINE) . ';border-radius:16px;padding:' . ($hl ? '17px 17px' : '18px 18px') . ';background:#FFFFFF">'
                        . ($hl ? '<div style="display:inline-block;background:' . $P . ';color:#FFFFFF;font:700 11px/11px ' . $F . ';letter-spacing:0.6px;text-transform:uppercase;border-radius:999px;padding:6px 10px;margin-bottom:10px">' . $e($badge) . '</div>' : '')
                        . '<div style="font:700 15px/20px ' . $F . ';color:' . $INK . '">' . $e($name) . '</div>'
                        . '<div style="font:800 26px/32px ' . $F . ';letter-spacing:-0.5px;color:' . $INK . ';margin-top:4px">' . $e($price) . '<span style="font:500 14px ' . $F . ';color:' . $MUT . '"> /month</span></div>'
                        . '<div style="font:400 14px/21px ' . $F . ';color:' . $BODY . ';margin-top:6px">' . $e($detail) . '</div></td></tr></table></td>';
                }
                $grid .= '</tr>';
            }
            $rows .= '<tr><td style="' . $pad . ';padding-bottom:12px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $grid . '</table></td></tr>';
        }

        // the one button (+ a quiet secondary link)
        if (! empty($o['button'][0]) && ! empty($o['button'][1])) {
            $rows .= '<tr><td style="' . $pad . ';padding-top:6px;padding-bottom:26px"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:' . ($sec ? $INK : $P) . ';border-radius:14px;mso-padding-alt:16px 34px">'
                . '<a href="' . $e($o['button'][1]) . '" style="display:inline-block;padding:16px 34px;font:700 16px/20px ' . $F . ';color:#FFFFFF;text-decoration:none;border-radius:14px">' . $e($o['button'][0]) . ' &rarr;</a></td>'
                . (! empty($o['secondary'][1]) ? '<td style="padding-left:20px"><a href="' . $e($o['secondary'][1]) . '" style="font:600 15px ' . $F . ';color:' . $P . ';text-decoration:none">' . $e($o['secondary'][0]) . '</a></td>' : '')
                . '</tr></table></td></tr>';
        }

        foreach ((array) ($o['after'] ?? []) as $p) $rows .= '<tr><td style="' . $pad . ';padding-bottom:12px;font:400 14px/22px ' . $F . ';color:' . $MUT . '">' . $p . '</td></tr>';

        // sign-off
        if (($o['signoff'] ?? null) === 'sarah') {
            $rows .= '<tr><td style="' . $pad . ';padding-top:10px;padding-bottom:36px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="border-top:1px solid ' . $LINE . ';padding-top:22px">'
                . '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td width="60" valign="middle"><img src="' . $e($base . '/email/sarah-192.png') . '" width="48" height="48" alt="Sarah" style="display:block;width:48px;height:48px;border-radius:24px;border:0"></td>'
                . '<td valign="middle"><div style="font:700 16px/22px ' . $F . ';color:' . $INK . '">Sarah</div><div style="font:400 14px/20px ' . $F . ';color:' . $MUT . '">Your marketing manager at LevelUpGrowth</div></td></tr></table></td></tr></table></td></tr>';
        } elseif (($o['signoff'] ?? null) === 'team') {
            $rows .= '<tr><td style="' . $pad . ';padding-top:10px;padding-bottom:36px"><div style="border-top:1px solid ' . $LINE . ';padding-top:22px;font:600 15px ' . $F . ';color:' . $INK . '">The LevelUpGrowth team</div></td></tr>';
        } else {
            $rows .= '<tr><td style="padding:0 0 24px"></td></tr>';
        }

        $links = [];
        if (! empty($o['preferences'])) $links[] = '<a href="' . $e($o['preferences']) . '" style="color:' . $MUT . ';text-decoration:underline">Email preferences</a>';
        if (! empty($o['unsubscribe'])) $links[] = '<a href="' . $e($o['unsubscribe']) . '" style="color:' . $MUT . ';text-decoration:underline">Unsubscribe</a>';
        $reason = $e($o['reason'] ?? 'You received this email because you have a LevelUpGrowth account.');
        $pre = $e($o['preheader'] ?? '');

        return '<!DOCTYPE html><html lang="en" xmlns="http://www.w3.org/1999/xhtml"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="x-apple-disable-message-reformatting"><meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light">'
            . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">'
            . '<title>' . $e($o['heading'] ?? 'LevelUpGrowth') . '</title>'
            . '<style>@media (max-width:620px){.lu-card td[style*="padding:0 44px"]{padding-left:24px!important;padding-right:24px!important}.lu-h1{font-size:26px!important;line-height:32px!important}}</style></head>'
            . '<body style="margin:0;padding:0;background:#EFEDF8;-webkit-font-smoothing:antialiased">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all">' . $pre . '&#8203;&zwnj;&nbsp;&#8203;&zwnj;&nbsp;&#8203;&zwnj;&nbsp;&#8203;&zwnj;&nbsp;</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EFEDF8"><tr><td align="center" style="padding:36px 12px 40px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px">'
            . '<tr><td align="center" style="padding:0 0 22px"><a href="https://levelupgrowth.io" style="text-decoration:none"><img src="' . $e($base . '/img/logo-icon-new.png') . '" width="30" height="30" alt="" style="vertical-align:middle;border:0">'
            . '<span style="vertical-align:middle;margin-left:10px;font:800 19px ' . $F . ';letter-spacing:-0.3px;color:' . $INK . '">LevelUpGrowth</span></a></td></tr>'
            . '<tr><td class="lu-card" style="background:#FFFFFF;border-radius:24px;box-shadow:0 1px 2px rgba(20,17,46,0.04),0 12px 32px rgba(20,17,46,0.08)"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table></td></tr>'
            . '<tr><td align="center" style="padding:28px 24px 0;font:400 12px/19px ' . $F . ';color:' . $MUT . '">' . $reason
            . ($links ? '<br>' . implode(' &nbsp;&middot;&nbsp; ', $links) : '')
            . '<br><br><span style="font-weight:700;color:' . $INK . '">LevelUpGrowth</span> &nbsp;&middot;&nbsp; <a href="https://levelupgrowth.io" style="color:' . $MUT . ';text-decoration:none">levelupgrowth.io</a></td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** The plain-text twin every email carries. */
    public static function text(array $o): string
    {
        $t = fn ($s) => trim(html_entity_decode(strip_tags(preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#i', '$2 ($1)', (string) $s)), ENT_QUOTES, 'UTF-8'));
        $out = [];
        foreach (['eyebrow', 'heading', 'lead', 'greeting'] as $k) if (! empty($o[$k])) $out[] = $t($o[$k]);
        foreach ((array) ($o['paragraphs'] ?? []) as $p) $out[] = $t($p);
        foreach ((array) ($o['stats'] ?? []) as [$v, $l]) $out[] = $t($l) . ': ' . $t($v);
        foreach (array_values((array) ($o['steps'] ?? [])) as $i => [$a, $b]) $out[] = ($i + 1) . '. ' . $t($a) . ($b !== '' ? ' - ' . $t($b) : '');
        foreach ((array) ($o['list'] ?? []) as $li) $out[] = '- ' . $t($li);
        if (! empty($o['callout'])) $out[] = $t($o['callout']);
        foreach ((array) ($o['plans'] ?? []) as $c) $out[] = $t($c[0]) . ': ' . $t($c[1]) . '/month, ' . $t($c[2]);
        if (! empty($o['button'][1])) $out[] = $t($o['button'][0] ?? '') . ': ' . $o['button'][1];
        foreach ((array) ($o['after'] ?? []) as $p) $out[] = $t($p);
        if (($o['signoff'] ?? null) === 'sarah') $out[] = "Sarah\nYour marketing manager at LevelUpGrowth";
        $out[] = '--';
        $out[] = $t($o['reason'] ?? 'You received this email because you have a LevelUpGrowth account.');
        if (! empty($o['unsubscribe'])) $out[] = 'Unsubscribe: ' . $o['unsubscribe'];
        $out[] = 'LevelUpGrowth - https://levelupgrowth.io';
        return implode("\n\n", $out);
    }
}
