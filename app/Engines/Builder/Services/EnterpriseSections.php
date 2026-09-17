<?php

namespace App\Engines\Builder\Services;

use Illuminate\Support\Facades\DB;

/**
 * MRDIGITAL888 G2 (2026-09-17) — theme-less fallbacks for the three enterprise
 * section types added to SectionSchema: logo_wall, process_steps, case_studies.
 *
 * MrDigitalEnterpriseTheme renders them with the navy design; these generic
 * renderers keep the types usable on ANY renderer-path site (and make Arthur's
 * add_section of these types render immediately). case_studies is data-backed:
 * it reads published articles in blog_category 'case-studies' at render time
 * (brief_json.case_study carries sector/practice/kpis). Pure output — no writes.
 */
trait EnterpriseSections
{
    private function renderLogoWall(array $sec, array $brand): string
    {
        $items = '';
        foreach ((array) ($sec['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $n = e((string) ($it['name'] ?? '')); if ($n === '') continue;
            $logo = $this->safeUrl((string) ($it['logo_url'] ?? ''), '');
            $items .= '<span style="font-weight:700;letter-spacing:.04em;opacity:.7;white-space:nowrap">' . ($logo !== '' ? '<img src="' . $logo . '" alt="' . $n . '" style="height:28px;width:auto;filter:grayscale(1)" loading="lazy">' : $n) . '</span>';
        }
        if ($items === '') return '';
        $h = e((string) ($sec['heading'] ?? ''));
        return '<section style="padding:32px 24px;border-top:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb"><div style="max-width:1200px;margin:0 auto;display:flex;gap:32px;align-items:center;flex-wrap:wrap">'
            . ($h !== '' ? '<div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.7">' . $h . '</div>' : '') . '<div style="display:flex;gap:36px;flex-wrap:wrap;align-items:center">' . $items . '</div></div></section>';
    }

    private function renderProcessSteps(array $sec, array $brand): string
    {
        $primary = $this->sanitizeCssColor($brand['primary'] ?? '#1F2937', '#1F2937');
        $li = ''; $n = 0;
        foreach ((array) ($sec['steps'] ?? []) as $st) {
            if (!is_array($st)) continue; $n++;
            $li .= '<li style="list-style:none;padding:20px 20px 20px 0"><div style="font-size:12px;letter-spacing:.1em;color:' . $primary . '">STAGE ' . $n . '</div><h3 style="font-size:18px;margin:10px 0 6px">' . e((string) ($st['title'] ?? '')) . '</h3><p style="font-size:14px;opacity:.8;margin:0">' . e((string) ($st['body'] ?? '')) . '</p>'
                . (!empty($st['deliverable']) ? '<div style="margin-top:12px;font-size:12px;opacity:.7">Deliverable · <b>' . e((string) $st['deliverable']) . '</b></div>' : '') . '</li>';
        }
        if ($li === '') return '';
        $h = e((string) ($sec['heading'] ?? '')); $sub = e((string) ($sec['subheading'] ?? ''));
        return '<section style="padding:72px 24px"><div style="max-width:1200px;margin:0 auto">' . ($sub !== '' ? '<div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.7">' . $sub . '</div>' : '') . ($h !== '' ? '<h2 style="margin:10px 0 28px">' . $h . '</h2>' : '')
            . '<ol style="margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;border-top:1px solid #e5e7eb">' . $li . '</ol></div></section>';
    }

    /** Published case studies bound to the website (articles in blog_category 'case-studies'). */
    protected function enterpriseCaseStudies(array $website, int $limit = 6, int $excludeId = 0)
    {
        $wsId = (int) ($website['workspace_id'] ?? 0); $wid = (int) ($website['id'] ?? 0);
        $q = DB::table('articles')->where('workspace_id', $wsId)
            ->where(function ($q) use ($wid) { if ($wid > 0) { $q->where('website_id', $wid)->orWhereNull('website_id'); } })
            ->where('is_marketing_blog', 1)->where('status', 'published')->whereNull('deleted_at')
            ->whereRaw('LOWER(REPLACE(blog_category, " ", "-")) IN ("case-studies", "case-study")');
        if ($excludeId > 0) $q->where('id', '!=', $excludeId);
        return $q->orderByDesc('published_at')->orderByDesc('id')->limit(max(1, min(48, $limit)))
            ->get(['id', 'title', 'slug', 'excerpt', 'blog_category', 'featured_image_url', 'featured_image_alt', 'published_at', 'brief_json']);
    }

    protected function enterpriseCaseStudyBase(array $website): string
    {
        $s = $website['settings_json'] ?? []; if (is_string($s)) $s = json_decode($s, true) ?: [];
        $b = strtolower(trim((string) ($s['case_study_base'] ?? 'case-studies')));
        return preg_match('/^[a-z0-9\-]{1,40}$/', $b) ? $b : 'case-studies';
    }

    private function renderCaseStudies(array $sec, array $brand, array $website = []): string
    {
        $primary = $this->sanitizeCssColor($brand['primary'] ?? '#1F2937', '#1F2937');
        $rows = $this->enterpriseCaseStudies($website, (int) ($sec['limit'] ?? 6));
        $pr = trim((string) ($sec['practice'] ?? '')); $se = trim((string) ($sec['sector'] ?? ''));
        $base = $this->enterpriseCaseStudyBase($website);
        $cards = '';
        foreach ($rows as $a) {
            $b = is_string($a->brief_json ?? null) ? (json_decode($a->brief_json, true) ?: []) : []; $cs = is_array($b['case_study'] ?? null) ? $b['case_study'] : [];
            if (($pr !== '' && ($cs['practice'] ?? '') !== $pr) || ($se !== '' && ($cs['sector'] ?? '') !== $se)) continue;
            $kp = '';
            foreach (array_slice((array) ($cs['kpis'] ?? []), 0, 2) as $k) if (is_array($k)) $kp .= '<div><b style="display:block;font-size:22px">' . e((string) ($k['value'] ?? '')) . '</b><small style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;opacity:.7">' . e((string) ($k['label'] ?? '')) . '</small></div>';
            $cards .= '<a href="/' . e($base) . '/' . e((string) $a->slug) . '" style="display:flex;flex-direction:column;gap:10px;border:1px solid #e5e7eb;border-radius:4px;padding:20px;text-decoration:none;color:inherit">'
                . (!empty($cs['sector']) ? '<span style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:' . $primary . '">' . e(ucfirst(str_replace('-', ' ', (string) $cs['sector']))) . '</span>' : '')
                . '<h3 style="font-size:18px;margin:0">' . e((string) $a->title) . '</h3>' . (!empty($a->excerpt) ? '<p style="font-size:14px;opacity:.8;margin:0">' . e(mb_substr((string) $a->excerpt, 0, 160)) . '</p>' : '')
                . ($kp !== '' ? '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;border-top:1px solid #e5e7eb;padding-top:12px;margin-top:auto">' . $kp . '</div>' : '') . '</a>';
        }
        $h = e((string) ($sec['heading'] ?? 'Case studies')); $sub = e((string) ($sec['subheading'] ?? ''));
        if ($cards === '') {
            if (!empty($sec['hide_when_empty'])) return '';
            return '<section style="padding:72px 24px"><div style="max-width:1200px;margin:0 auto"><h2>' . $h . '</h2><p style="opacity:.7">' . e((string) ($sec['empty_text'] ?? 'Case studies are being prepared for publication.')) . '</p></div></section>';
        }
        $cta = !empty($sec['cta_text']) ? '<p style="margin-top:24px"><a href="' . $this->safeUrl((string) ($sec['cta_url'] ?? '#'), '#') . '" style="font-weight:600;color:' . $primary . '">' . e((string) $sec['cta_text']) . ' →</a></p>' : '';
        return '<section style="padding:72px 24px"><div style="max-width:1200px;margin:0 auto">' . ($sub !== '' ? '<div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.7">' . $sub . '</div>' : '') . '<h2 style="margin:10px 0 28px">' . $h . '</h2>'
            . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px">' . $cards . '</div>' . $cta . '</div></section>';
    }
}
