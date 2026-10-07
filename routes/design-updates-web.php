<?php

/*
 * DESIGN-UPDATES-1 — the preview pages behind a design update: the site as it is now and as it would be after, plus the
 * probe's screenshots. Signed, short-lived, relative links (the owner's preview frame and the probe use them); never indexed.
 * The "after" page outlines the sections the owner should look at and answers the preview screen's "show me" requests.
 */

use App\Engines\Builder\Services\DesignUpdateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/design-update/{id}/{which}', function ($id, $which) {
    $u = DB::table('design_updates')->where('id', (int) $id)->first(['id', 'website_id', 'report_json']);
    if (! $u) abort(404);
    $dir = DesignUpdateService::dir((int) $u->website_id, (int) $u->id);
    $h = ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow'];
    if (preg_match('/^(now|after)-(desk|phone)$/', $which)) {
        $p = "{$dir}/{$which}.webp"; if (! is_file($p)) abort(404);
        return response()->file($p, $h + ['Content-Type' => 'image/webp']);
    }
    $__page = preg_match('/^page-([a-z0-9][a-z0-9\-]*)-(now|after)$/', $which, $__pm) ? $__pm : null;   // RECHROME-1: inner pages
    if (! $__page && ! in_array($which, ['now', 'after'], true)) abort(404);
    if ($__page) {
        $__tpl = app(\App\Engines\Builder\Services\TemplateService::class);
        $__ip = $__tpl->innerPages((int) $u->website_id)[$__page[1]] ?? null; if (! $__ip) abort(404);
        $html = $__page[2] === 'now' ? (string) @file_get_contents($__ip['path']) : (string) $__tpl->previewInnerPage((int) $u->website_id, $__page[1], (string) @file_get_contents("{$dir}/after.html"));
        if ($html === '') abort(404);
        $which = $__page[2] === 'now' ? 'now' : 'after-page';
    } else {
    $snap = $which === 'after' ? 'after.html' : 'now.html';
    $html = (string) @file_get_contents("{$dir}/{$snap}"); if ($html === '') abort(404);
    }
    $r = json_decode((string) $u->report_json, true) ?: [];
    $marks = [];
    if ($which === 'after') foreach ((array) ($r['changes'] ?? []) as $c) { $tag = ['kept_custom' => 'Kept as you have it', 'new_section' => 'New from the design', 'added_kept' => 'Your added section']; if (isset($tag[$c['kind']])) $marks[$c['block']] = $tag[$c['kind']]; }
    $kit = '<meta name="robots" content="noindex,nofollow"><style id="lu-dupd-kit">[data-lu-dupd-mark]{outline:3px dashed rgba(124,92,255,.9)!important;outline-offset:-6px;position:relative}'
        . '[data-lu-dupd-mark]::after{content:attr(data-lu-dupd-mark);position:absolute;top:12px;left:12px;z-index:50;background:rgba(124,92,255,.95);color:#fff;font:600 12px/1 system-ui,-apple-system,sans-serif;padding:7px 10px;border-radius:999px;pointer-events:none}'
        . '[data-lu-dupd-flash]{outline-color:#fff!important;box-shadow:0 0 0 6px rgba(124,92,255,.55) inset!important}</style>';
    $js = '<script id="lu-dupd-js">(function(){var M=' . json_encode($marks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';function mark(){Object.keys(M).forEach(function(b){var e=document.querySelector("[data-block=\""+b+"\"]");if(e)e.setAttribute("data-lu-dupd-mark",M[b]);});}'
        . 'if(document.readyState!=="loading")mark();else document.addEventListener("DOMContentLoaded",mark);'
        . 'document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("a,button[type=submit]");if(a){e.preventDefault();}},true);document.addEventListener("submit",function(e){e.preventDefault();},true);'
        . 'window.addEventListener("message",function(e){var d=e.data||{};if(!d.lu_dupd_show)return;var el=document.querySelector("[data-block=\""+String(d.lu_dupd_show).replace(/[^a-z0-9_\-]/gi,"")+"\"]");if(!el)return;el.scrollIntoView({behavior:"smooth",block:"start"});el.setAttribute("data-lu-dupd-flash","1");setTimeout(function(){el.removeAttribute("data-lu-dupd-flash");},1600);});})();</script>';
    // the preview frame is sandboxed (no access to the app's storage): the site's own scripts get a storage that forgets, so they run
    $shim = '<script id="lu-dupd-shim">(function(){function mk(){var m={};return{getItem:function(k){return Object.prototype.hasOwnProperty.call(m,k)?m[k]:null},setItem:function(k,v){m[k]=String(v)},removeItem:function(k){delete m[k]},clear:function(){m={}},key:function(i){return Object.keys(m)[i]||null},get length(){return Object.keys(m).length}}}'
        . '["localStorage","sessionStorage"].forEach(function(n){try{window[n].length}catch(e){try{Object.defineProperty(window,n,{value:mk(),configurable:true})}catch(x){}}});try{document.cookie}catch(e){try{Object.defineProperty(document,"cookie",{get:function(){return""},set:function(){},configurable:true})}catch(x){}}})();</script>';
    $html = preg_replace('#<head\b[^>]*>#i', '$0' . $shim, $html, 1) ?? $html;
    $html = preg_replace('#</head>#i', $kit . '</head>', $html, 1) ?? $html;
    $html = preg_replace('#</body>#i', $js . '</body>', $html, 1) ?? ($html . $js);
    return response($html, 200, $h + ['Content-Type' => 'text/html; charset=UTF-8']);
})->middleware('signed:relative')->whereNumber('id')->name('design-update.view');
