<?php

namespace App\Engines\Builder\Support;

use Illuminate\Support\Facades\DB;

/**
 * PLATFORM SCRIPTS FOR EVERY EXPORTED SITE (DEC-0051 gap closure, 2026-09-15).
 *
 *  1. FORMS — every <form> a design ships was inert (action="#", or a fake alert() in onsubmit — RISK-0174): the poster
 *     below captures any form without a real action, posts it to the public contact endpoint (into the workspace CRM),
 *     and shows an inline, site-styled message. Booking forms (a date/time/service field) are tagged as bookings.
 *  2. TRACKING — GA4 / Google Tag Manager / Meta pixel / TikTok pixel ids from settings_json.tracking, in the <head>.
 *
 * Idempotent: each block carries an id and is replaced, never duplicated. Applied at deploy (home + every page) and
 * by the `sites:inject-scripts` sweep for exports written before this existed.
 */
final class SiteScripts
{
    public static function inject(string $html, int $websiteId, ?array $settings = null): string
    {
        if ($settings === null) {
            $settings = json_decode((string) (DB::table('websites')->where('id', $websiteId)->value('settings_json') ?: '{}'), true) ?: [];
        }
        $html = self::stripFakeSubmits($html);
        $html = self::replaceBlock($html, 'lu-forms-js', self::formsScript($websiteId), 'body');
        $html = self::replaceBlock($html, 'lu-tracking', self::trackingSnippet((array) ($settings['tracking'] ?? [])), 'head');
        return $html;
    }

    /** `onsubmit="event.preventDefault();alert('Your table is reserved!…')"` — a fabricated success; gone. */
    public static function stripFakeSubmits(string $html): string
    {
        return preg_replace('/\sonsubmit="[^"]*(?:alert|preventDefault)[^"]*"/i', '', $html) ?? $html;
    }

    private static function replaceBlock(string $html, string $id, string $block, string $where): string
    {
        $html = preg_replace('~\s*<(?:script|div)\b[^>]*\bid="' . preg_quote($id, '~') . '"[^>]*>.*?</(?:script|div)>~is', '', $html) ?? $html;
        $html = preg_replace('~\s*<!-- ' . preg_quote($id, '~') . ' -->.*?<!-- /' . preg_quote($id, '~') . ' -->~is', '', $html) ?? $html;
        if (trim($block) === '') return $html;
        if ($where === 'head') {
            return stripos($html, '</head>') !== false ? preg_replace('~</head>~i', "\n" . $block . "\n</head>", $html, 1) : $block . $html;
        }
        return stripos($html, '</body>') !== false ? preg_replace('~</body>~i', "\n" . $block . "\n</body>", $html, 1) : $html . "\n" . $block;
    }

    public static function trackingSnippet(array $t): string
    {
        $ga4 = preg_match('/^G-[A-Z0-9]{4,20}$/', (string) ($t['ga4'] ?? '')) ? (string) $t['ga4'] : '';
        $gtm = preg_match('/^GTM-[A-Z0-9]{4,12}$/', (string) ($t['gtm'] ?? '')) ? (string) $t['gtm'] : '';
        $meta = preg_match('/^\d{8,20}$/', (string) ($t['meta_pixel'] ?? '')) ? (string) $t['meta_pixel'] : '';
        $tiktok = preg_match('/^[A-Z0-9]{10,40}$/i', (string) ($t['tiktok_pixel'] ?? '')) ? (string) $t['tiktok_pixel'] : '';
        if ($ga4 === '' && $gtm === '' && $meta === '' && $tiktok === '') return '';
        $s = '<!-- lu-tracking -->';
        if ($gtm !== '') $s .= "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$gtm}');</script>";
        if ($ga4 !== '') $s .= "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$ga4}\"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$ga4}');</script>";
        if ($meta !== '') $s .= "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','{$meta}');fbq('track','PageView');</script>";
        if ($tiktok !== '') $s .= "<script>!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie'],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var i='https://analytics.tiktok.com/i18n/pixel/events.js';ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=document.createElement('script');o.type='text/javascript',o.async=!0,o.src=i+'?sdkid='+e+'&lib='+t;var a=document.getElementsByTagName('script')[0];a.parentNode.insertBefore(o,a)};ttq.load('{$tiktok}');ttq.page();}(window,document,'ttq');</script>";
        return $s . '<!-- /lu-tracking -->';
    }

    /** The form poster: any form without a real action is sent to the workspace CRM; the reply is site-styled, never a native dialog. */
    public static function formsScript(int $websiteId = 0): string
    {
        $SITEID = (int) $websiteId;
        $api = rtrim((string) config('app.url'), '/') . '/api/public/contact/by-host';
        $apiJson = json_encode($api, JSON_UNESCAPED_SLASHES);
        return '<script id="lu-forms-js">(function(){'
            . 'var API=' . $apiJson . ';'
            . 'function label(el){var id=el.getAttribute("id");var l=id?el.form.querySelector("label[for=\""+id+"\"]"):null;if(!l&&el.closest("label"))l=el.closest("label");var t="";if(l){var c=l.cloneNode(true);Array.prototype.forEach.call(c.querySelectorAll("input,select,textarea,button"),function(x){x.parentNode.removeChild(x);});t=c.textContent;}t=t||el.getAttribute("placeholder")||el.getAttribute("aria-label")||el.name||"";return t.replace(/\s+/g," ").replace(/[:*]+$/,"").trim();}'
            . 'function own(f){var a=(f.getAttribute("action")||"").trim();if(f.hasAttribute("data-lu-native"))return false;if(f.id==="lu-enq")return false;return a===""||a==="#"||a==="javascript:void(0)"||/^#/.test(a);}'
            . 'function msgEl(f){var m=f.querySelector(".lu-form-msg");if(!m){m=document.createElement("p");m.className="lu-form-msg";m.setAttribute("aria-live","polite");m.style.cssText="margin:12px 0 0;font-size:14px;line-height:1.45";f.appendChild(m);}return m;}'
            . 'function slug(s){return (s||"").toLowerCase().replace(/[^a-z0-9]+/g,"_").replace(/^_+|_+$/g,"").slice(0,40);}'
            . 'function key(el){var n=(el.name||el.id||"").toLowerCase();if(n)return n;var t=(el.type||"").toLowerCase();if(t==="email")return "email";if(t==="tel")return "phone";if(t==="date")return "preferred_date";if(t==="time")return "preferred_time";if(el.tagName==="TEXTAREA")return "message";var l=slug(label(el));if(/mail/.test(l))return "email";if(/phone|mobile|tel|whatsapp/.test(l))return "phone";if(/^(your_)?(full_)?name$|^name_/.test(l))return "name";if(/message|comment|detail|note|enquir|inquir/.test(l))return "message";return l||("field_"+Array.prototype.indexOf.call(el.form.elements,el));}'
            . 'function ensure(f,needName,needEmail){var added=false,anchor=f.querySelector("button[type=submit],input[type=submit],button:not([type])");function add(type,name,ph){if(f.querySelector("[name=\""+name+"\"]"))return;var i=document.createElement("input");i.type=type;i.name=name;i.placeholder=ph;i.required=true;i.setAttribute("data-lu-added","1");i.style.cssText="display:block;width:100%;box-sizing:border-box;margin:8px 0;padding:11px 12px;font:inherit;font-size:15px;border:1px solid rgba(0,0,0,.2);border-radius:8px;background:#fff;color:#111";if(anchor&&anchor.parentNode)anchor.parentNode.insertBefore(i,anchor);else f.appendChild(i);added=true;}if(needName)add("text","name","Your name");if(needEmail)add("email","email","Your email");return added;}'
            . 'function post(f){var b=f.querySelector("button[type=submit],input[type=submit],button:not([type])"),m=msgEl(f);var d={},lines=[];'
            . 'Array.prototype.forEach.call(f.elements,function(el){if(el.disabled||el.type==="submit"||el.type==="button"||el.tagName==="BUTTON")return;if((el.type==="checkbox"||el.type==="radio")&&!el.checked)return;var k=key(el),v=(el.value||"").trim();if(v==="")return;d[k]=d[k]?d[k]+", "+v:v;});'
            . 'var name=d.name||d.full_name||d.fullname||((d.firstname||d.first_name||"")+" "+(d.lastname||d.last_name||"")).trim();var email=d.email||d.email_address||"";var phone=d.phone||d.tel||d.telephone||d.mobile||d.whatsapp||"";var message=d.message||d.comments||d.comment||d.details||d.notes||d.enquiry||"";'
            . 'Array.prototype.forEach.call(f.elements,function(el){if(el.type==="submit"||el.type==="button"||el.type==="hidden"||el.tagName==="BUTTON")return;var k=key(el);if(["name","full_name","fullname","firstname","first_name","lastname","last_name","email","email_address","phone","tel","telephone","mobile","whatsapp","message","comments","comment","details","notes","enquiry"].indexOf(k)>=0)return;if(d[k])lines.push(label(el)+": "+d[k]);});'
            . 'var booking=!!(d.preferred_date||d.date||d.preferred_time||d.time||d.appointment_date||d.checkin||d.check_in||/book|reserv|appoint/i.test(f.className+" "+(f.id||"")+" "+(b?b.textContent:"")));'
            . 'if(!name||!email){var added=ensure(f,!name,!email);m.textContent=added?(booking?"Add your name and email so we can confirm your booking.":"Add your name and email so we can reply."):"Please add your name and email so we can reply.";m.style.color="#b91c1c";var first=f.querySelector("[data-lu-added]");if(first)first.focus();return;}'
            . 'var full=(message||(booking?"Booking request":"Enquiry from the website"))+(lines.length?"\n\n"+lines.join("\n"):"");'
            . 'var was=b?b.textContent:"";if(b){b.disabled=true;b.textContent="Sending…";}m.style.color="";m.textContent="";'
            . 'fetch(API,{method:"POST",headers:{"Content-Type":"application/json","Accept":"application/json"},body:JSON.stringify({name:name,firstname:name,email:email,phone:phone,message:full,source:booking?"booking_form":"contact_form",page:location.pathname,fields:d})})'
            . '.then(function(r){return r.json().catch(function(){return {};}).then(function(j){return {ok:r.ok,j:j};});})'
            . '.then(function(x){if(x.ok){m.textContent=booking?"Thank you — your booking request has been sent. We will confirm shortly.":"Thank you — your message has been sent. We will be in touch shortly.";f.reset();}else{m.textContent=(x.j&&x.j.message&&/not published|not found/i.test(x.j.message))?"This site is not published yet, so the form is not live. Once it is published, messages come straight to the owner.":((x.j&&x.j.message)||"Sorry, that did not go through. Please try again or use the contact details on this page.");m.style.color="#b91c1c";if(b){b.disabled=false;b.textContent=was;}}})'
            . '.catch(function(){m.textContent="Sorry, that did not go through. Please try again.";m.style.color="#b91c1c";if(b){b.disabled=false;b.textContent=was;}});}'
            . 'function arm(){Array.prototype.forEach.call(document.querySelectorAll("form"),function(f){if(f.__lu||!own(f))return;f.__lu=true;f.removeAttribute("onsubmit");f.setAttribute("novalidate","novalidate");f.addEventListener("submit",function(e){e.preventDefault();post(f);});});}'
            . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",arm);else arm();'
            // STORE PAYMENTS (DEC-0051): the checkout form carries the page address; a return with ?paid= confirms and thanks
            . 'function pay(){Array.prototype.forEach.call(document.querySelectorAll("form.lu-pay"),function(f){var i=f.querySelector("[name=return]");if(i)i.value=location.href.split("?")[0];});var q=new URLSearchParams(location.search),sid=q.get("paid");if(!sid)return;var box=document.createElement("div");box.setAttribute("role","status");box.style.cssText="position:fixed;left:12px;right:12px;bottom:12px;z-index:9999;background:#14532d;color:#fff;padding:14px 18px;border-radius:12px;font:15px system-ui,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.3)";box.textContent="Checking your payment…";document.body.appendChild(box);'
            . 'fetch(API.replace("/contact/by-host","/store-confirm/")+' . $SITEID . ',{method:"POST",headers:{"Content-Type":"application/json","Accept":"application/json"},body:JSON.stringify({session:sid})}).then(function(r){return r.json();}).then(function(j){box.textContent=j&&j.paid?"Payment received — thank you! We will be in touch shortly.":"Your payment is being confirmed — you will hear from us shortly.";}).catch(function(){box.textContent="Thank you — we will confirm your payment shortly.";});setTimeout(function(){box.remove();},12000);history.replaceState(null,"",location.pathname);}'
            . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",pay);else pay();'
            . '})();</script>';
    }
}
