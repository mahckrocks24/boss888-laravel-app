<?php

namespace App\Engines\Resume\Services;

/**
 * RESUME888 — the "Clean" template: A4, single column, ATS-safe, dompdf-friendly (no flex/grid, tables for layout
 * only where unavoidable). Same HTML feeds the browser preview and the PDF.
 */
class ResumeRenderer
{
    public static function html(array $d, string $template = 'clean', bool $forPdf = true): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $p = $d['person'] ?? []; $name = trim((string) ($p['full_name'] ?? '')) ?: 'Your Name';
        $headline = trim((string) ($p['headline'] ?? '')) ?: implode(' · ', array_slice((array) ($d['target']['roles'] ?? []), 0, 2));
        $contact = array_filter([$p['phone'] ?? null, $p['email'] ?? null, trim(($p['city'] ?? '') . (!empty($p['country']) ? ', ' . self::countryName($p['country']) : ''), ', ') ?: null]);
        $details = array_filter(['Nationality' => $p['nationality'] ?? 'Filipino', 'Visa status' => $p['visa_status'] ?? null, 'Availability' => $p['availability'] ?? null, 'Driving licence' => !empty($p['driving_licence']) ? implode(', ', (array) $p['driving_licence']) : null]);
        $photo = '';
        if (!empty($p['photo_data_uri'])) $photo = '<img class="photo" src="' . $e($p['photo_data_uri']) . '" alt="">';
        $css = self::css($forPdf);
        $h = "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"utf-8\"><title>{$e($name)} — CV</title><style>{$css}</style></head><body class=\"tpl-{$e($template)}\"><div class=\"cv\">";
        $h .= '<header class="head"><table class="headtbl"><tr><td class="headtxt"><h1>' . $e($name) . '</h1>' . ($headline ? '<div class="headline">' . $e($headline) . '</div>' : '') . '<div class="contact">' . implode(' &nbsp;·&nbsp; ', array_map($e, $contact)) . '</div></td>' . ($photo ? '<td class="headphoto">' . $photo . '</td>' : '') . '</tr></table></header>';
        if (!empty($d['summary'])) $h .= '<section><h2>Summary</h2><p class="sum">' . $e($d['summary']) . '</p></section>';
        $jobs = array_values(array_filter((array) ($d['experience'] ?? []), fn ($j) => !empty($j['title']) || !empty($j['employer'])));
        if ($jobs) {
            $h .= '<section><h2>Experience</h2>';
            foreach ($jobs as $j) {
                $h .= '<div class="job"><div class="jobhead"><span class="jt">' . $e($j['title'] ?? '') . '</span>' . (!empty($j['employer']) ? ' <span class="je">— ' . $e($j['employer']) . '</span>' : '') . '<span class="jd">' . $e(self::dateRange($j)) . '</span></div>';
                if (!empty($j['city'])) $h .= '<div class="jc">' . $e($j['city']) . '</div>';
                $bul = (array) ($j['bullets'] ?? []); if (!$bul && !empty($j['raw'])) $bul = ResumeWriter::templatedBullets($j);
                if ($bul) { $h .= '<ul>'; foreach ($bul as $b) $h .= '<li>' . $e($b) . '</li>'; $h .= '</ul>'; }
                $h .= '</div>';
            }
            $h .= '</section>';
        }
        $edu = array_values(array_filter((array) ($d['education'] ?? []), fn ($x) => !empty($x['qualification']) || !empty($x['school'])));
        if ($edu) { $h .= '<section><h2>Education</h2>'; foreach ($edu as $x) $h .= '<div class="row"><span class="b">' . $e($x['qualification'] ?? '') . '</span>' . (!empty($x['field']) ? ', ' . $e($x['field']) : '') . (!empty($x['school']) ? ' — ' . $e($x['school']) : '') . (!empty($x['year']) ? ' <span class="jd">' . $e($x['year']) . '</span>' : '') . '</div>'; $h .= '</section>'; }
        $lic = array_values(array_filter(array_map(fn ($l) => is_array($l) ? trim((string) ($l['name'] ?? '')) . (!empty($l['expires']) ? ' (valid to ' . $l['expires'] . ')' : '') : trim((string) $l), (array) ($d['licences'] ?? []))));
        if ($lic) $h .= '<section><h2>Licences &amp; Certificates</h2><ul class="inline">' . implode('', array_map(fn ($l) => '<li>' . $e($l) . '</li>', $lic)) . '</ul></section>';
        $skills = array_values(array_filter(array_map('trim', (array) ($d['skills'] ?? []))));
        if ($skills) $h .= '<section><h2>Skills</h2><p class="chips">' . implode(' · ', array_map($e, $skills)) . '</p></section>';
        $langs = array_values(array_filter(array_map(fn ($l) => is_array($l) ? trim((string) ($l['name'] ?? '')) . (!empty($l['level']) ? ' (' . $l['level'] . ')' : '') : trim((string) $l), (array) ($d['languages'] ?? []))));
        if ($langs) $h .= '<section><h2>Languages</h2><p class="chips">' . implode(' · ', array_map($e, $langs)) . '</p></section>';
        if ($details) { $h .= '<section><h2>Personal Details</h2><table class="det">'; foreach ($details as $k => $v) $h .= '<tr><td class="k">' . $e($k) . '</td><td>' . $e($v) . '</td></tr>'; $h .= '</table></section>'; }
        $refs = array_values(array_filter((array) ($d['references'] ?? []), fn ($r) => !empty($r['name'])));
        if ($refs) { $h .= '<section><h2>References</h2>'; foreach ($refs as $r) $h .= '<div class="row">' . $e($r['name']) . (!empty($r['role']) ? ', ' . $e($r['role']) : '') . (!empty($r['contact']) ? ' — ' . $e($r['contact']) : '') . '</div>'; $h .= '</section>'; }
        else $h .= '<p class="refs">References available on request.</p>';
        $h .= '</div></body></html>';
        return $h;
    }

    public static function pdf(array $d, string $template = 'clean'): string
    {
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml(self::html($d, $template, true));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    private static function css(bool $pdf): string
    {
        $font = $pdf ? "'DejaVu Sans', Helvetica, Arial, sans-serif" : "Inter, 'Segoe UI', Roboto, Arial, sans-serif";
        return "@page{margin:16mm 16mm 18mm}body{margin:0;font-family:{$font};font-size:10.5pt;color:#141826;line-height:1.38}.cv{max-width:180mm;margin:0 auto}"
            . ".headtbl{width:100%;border-collapse:collapse}.headtxt{vertical-align:top}.headphoto{width:30mm;text-align:right;vertical-align:top}.photo{width:28mm;height:34mm;object-fit:cover;border:1px solid #d5d9e5}"
            . "h1{font-size:22pt;margin:0 0 2pt;letter-spacing:.2pt}.headline{font-size:11.5pt;color:#0038A8;font-weight:bold;margin-bottom:3pt}.contact{font-size:9.5pt;color:#3A4152}"
            . "h2{font-size:10.5pt;text-transform:uppercase;letter-spacing:1.2pt;color:#0038A8;border-bottom:1.2pt solid #0038A8;padding-bottom:2pt;margin:12pt 0 6pt}"
            . "section{margin:0}.sum{margin:0}.job{margin:0 0 7pt}.jobhead{font-size:10.5pt}.jt{font-weight:bold}.je{color:#3A4152}.jd{float:right;color:#5B6478;font-size:9.5pt}.jc{color:#5B6478;font-size:9.5pt}"
            . "ul{margin:3pt 0 0;padding-left:14pt}li{margin:0 0 2pt}ul.inline{padding-left:14pt}.row{margin:0 0 3pt}.b{font-weight:bold}.chips{margin:0}.det{border-collapse:collapse}.det td{padding:1pt 12pt 1pt 0;vertical-align:top}.det .k{color:#5B6478;width:32mm}.refs{color:#5B6478;font-size:9.5pt;margin-top:12pt}";
    }

    public static function dateRange(array $j): string
    {
        $f = fn ($v) => ($v === 'present' || $v === '') ? ($v === 'present' ? 'Present' : '') : (preg_match('/^(\d{4})-(\d{2})$/', (string) $v, $m) ? date('M Y', mktime(0, 0, 0, (int) $m[2], 1, (int) $m[1])) : (string) $v);
        $s = $f($j['start'] ?? ''); $e = $f($j['end'] ?? '');
        return trim($s . ($s !== '' && $e !== '' ? ' – ' : '') . $e);
    }
    public static function countryName(string $code): string
    {
        return ['AE' => 'UAE', 'QA' => 'Qatar', 'PH' => 'Philippines', 'SA' => 'Saudi Arabia', 'KW' => 'Kuwait', 'BH' => 'Bahrain', 'OM' => 'Oman'][strtoupper($code)] ?? $code;
    }
}
