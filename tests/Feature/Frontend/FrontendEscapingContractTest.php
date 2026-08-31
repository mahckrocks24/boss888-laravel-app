<?php

namespace Tests\Feature\Frontend;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** SEC-3 / REASON-2 (2026-08-31). The SPA builds HTML by string concatenation, so any value that reaches innerHTML
 *  must be escaped at the point of use. These are the three places where untrusted-ish text was found going in raw:
 *  provider/runtime error text in the Queue, a provider image URL in the SEO grid, and the publish URL in Builder.
 *
 *  Source-level on purpose — it cannot prove the DOM is safe, only that the escaping was not deleted. */
class FrontendEscapingContractTest extends TestCase
{
    public function test_the_queue_escapes_the_failure_reason_it_renders(): void
    {
        $core = File::get(public_path('app/js/core.js'));
        $i = strpos($core, 'REASON-2: a failed row must say why');
        $this->assertNotFalse($i, 'the Why cell must still exist');
        $block = substr($core, $i, 700);
        $this->assertStringContainsString('_cmdcEsc(why', $block, 'raw error text must be escaped before innerHTML');
        $this->assertStringNotContainsString("+ why +", $block, 'the reason must never be concatenated unescaped');
    }

    public function test_provider_and_publish_urls_are_encoded_before_becoming_attributes(): void
    {
        $seo = File::get(public_path('app/js/seo.js'));
        $this->assertStringContainsString("encodeURI(String(d.image_url", $seo, 'SEC-3: the generated image URL is an attribute value');
        $this->assertStringNotContainsString("'<img src=\"' + d.image_url + '\"", $seo, 'must not go back to raw concatenation');

        $builder = File::get(public_path('app/js/builder.js'));
        $this->assertStringContainsString("encodeURI(String(liveUrl", $builder, 'SEC-3: the publish URL is an href value');
        $this->assertStringContainsString('rel="noopener"', $builder, 'a target=_blank link needs noopener');
    }
}
