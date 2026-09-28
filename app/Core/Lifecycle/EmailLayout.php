<?php

namespace App\Core\Lifecycle;

/**
 * MAIL-BRAND-1 (Owner 2026-09-28, "build now" on the enterprise sign-up plan): ONE branded design for every platform
 * email — logo header, white card, one purple button, an optional sign-off from Sarah, a plain footer that says why the
 * email arrived and where to change that. Table-based and inline-styled so Gmail, Outlook, Apple Mail and Yahoo agree;
 * every email also carries a plain-text version (text()).
 *
 * $o keys: preheader, heading, greeting, paragraphs (strings; may hold <strong>/<a>), list (strings), button
 * [label, url], after (strings, small), signoff ('sarah' | 'team' | null), reason (footer line), unsubscribe (url|null),
 * preferences (url|null).
 */
final class EmailLayout
{
    public const PURPLE = '#6C5CE7';

    public static function appUrl(): string
    {
        // One setting to move every link off staging.* when the customer-facing app address exists (plan decision 1).
        return rtrim((string) (config('app.customer_url') ?: config('app.url')), '/');
    }

    public static function render(array $o): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $base = self::appUrl();
        $logo = $base . '/img/logo-icon-new.png';
        $pre = $e($o['preheader'] ?? '');
        $html = '';
        if (! empty($o['heading'])) $html .= '<h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;font-weight:700;color:#0F1117">' . $e($o['heading']) . '</h1>';
        if (! empty($o['greeting'])) $html .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#2B3345">' . $e($o['greeting']) . '</p>';
        foreach ((array) ($o['paragraphs'] ?? []) as $p) $html .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#2B3345">' . $p . '</p>';
        if (! empty($o['list'])) {
            $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:4px 0 18px">';
            foreach ((array) $o['list'] as $li) $html .= '<tr><td valign="top" style="padding:0 10px 8px 0;font-size:15px;line-height:1.5;color:' . self::PURPLE . ';font-weight:700">&#10003;</td><td style="padding:0 0 8px;font-size:15px;line-height:1.5;color:#2B3345">' . $li . '</td></tr>';
            $html .= '</table>';
        }
        if (! empty($o['button'][0]) && ! empty($o['button'][1])) {
            $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 22px"><tr><td style="background:' . self::PURPLE . ';border-radius:10px">'
                . '<a href="' . $e($o['button'][1]) . '" style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:700;color:#FFFFFF;text-decoration:none;border-radius:10px">' . $e($o['button'][0]) . '</a></td></tr></table>';
        }
        foreach ((array) ($o['after'] ?? []) as $p) $html .= '<p style="margin:0 0 10px;font-size:13px;line-height:1.6;color:#6B7489">' . $p . '</p>';
        $sign = '';
        if (($o['signoff'] ?? null) === 'sarah') {
            $sign = '<tr><td style="padding:0 36px 28px"><p style="margin:0;font-size:15px;line-height:1.5;color:#2B3345">Sarah<br><span style="font-size:13px;color:#6B7489">Your marketing manager at LevelUpGrowth</span></p></td></tr>';
        } elseif (($o['signoff'] ?? null) === 'team') {
            $sign = '<tr><td style="padding:0 36px 28px"><p style="margin:0;font-size:14px;color:#6B7489">The LevelUpGrowth team</p></td></tr>';
        }
        $links = [];
        if (! empty($o['preferences'])) $links[] = '<a href="' . $e($o['preferences']) . '" style="color:#6B7489;text-decoration:underline">Email preferences</a>';
        if (! empty($o['unsubscribe'])) $links[] = '<a href="' . $e($o['unsubscribe']) . '" style="color:#6B7489;text-decoration:underline">Unsubscribe</a>';
        $reason = $e($o['reason'] ?? 'You received this email because you have a LevelUpGrowth account.');
        return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light"><title>' . $e($o['heading'] ?? 'LevelUpGrowth') . '</title></head>'
            . '<body style="margin:0;padding:0;background:#F3F4F8;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#0F1117">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . $pre . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F4F8;padding:32px 12px"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px">'
            . '<tr><td style="padding:0 4px 18px"><a href="' . $e($base) . '/app/" style="text-decoration:none;color:#0F1117"><img src="' . $e($logo) . '" width="32" height="32" alt="" style="vertical-align:middle;border:0;border-radius:8px">'
            . '<span style="vertical-align:middle;margin-left:10px;font-size:18px;font-weight:800;color:#0F1117">LevelUpGrowth</span></a></td></tr>'
            . '<tr><td style="background:#FFFFFF;border:1px solid #E6E8F0;border-radius:16px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr><td style="padding:32px 36px 12px">' . $html . '</td></tr>' . $sign . '</table></td></tr>'
            . '<tr><td style="padding:20px 8px 0;font-size:12px;line-height:1.6;color:#6B7489">' . $reason
            . ($links ? '<br>' . implode(' &middot; ', $links) : '') . '<br>LevelUpGrowth &middot; <a href="https://levelupgrowth.io" style="color:#6B7489">levelupgrowth.io</a></td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** The plain-text twin every email carries. */
    public static function text(array $o): string
    {
        $t = fn ($s) => trim(html_entity_decode(strip_tags(preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#i', '$2 ($1)', (string) $s)), ENT_QUOTES, 'UTF-8'));
        $out = [];
        if (! empty($o['heading'])) $out[] = $t($o['heading']);
        if (! empty($o['greeting'])) $out[] = $t($o['greeting']);
        foreach ((array) ($o['paragraphs'] ?? []) as $p) $out[] = $t($p);
        foreach ((array) ($o['list'] ?? []) as $li) $out[] = '- ' . $t($li);
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
