<?php

namespace Tests\Feature\Chat\Regression;

use Tests\TestCase;

/**
 * CR-01 — the Aria/Builder markup leak.
 *
 * WHAT THE CUSTOMER SAW
 *   Hi ✦ Assistant <svg width="14" …> Not enough credits — chat costs 0.1
 *   credit (1 credit per 10 chats). Please top up.
 *
 * TWO DEFECTS COMPOSED
 *   1. builder.js rendered a caught error through the ASSISTANT message path,
 *      so an infrastructure/billing failure was presented as something the
 *      agent said (clause X-08 / G-06 / F-07).
 *   2. It prefixed that message with window.icon()'s raw SVG string, which the
 *      message formatter escapes and therefore displays as literal characters
 *      (clause G-04 / G-05).
 *   3. arthur-chat.js assigned an SVG string to .textContent, which renders the
 *      raw tag as visible text by definition (clause G-04).
 *
 * These assertions are written against the PRODUCTION source. They fail before
 * the fix and pass after it.
 */
class Cr01MarkupLeakTest extends TestCase
{
    private function src(string $rel): string
    {
        $path = base_path($rel);
        $this->assertFileExists($path, "expected production source {$rel}");

        return (string) file_get_contents($path);
    }

    /**
     * @test
     *
     * SCOPE NOTE. The icon-into-textContent defect is not confined to chat: a
     * platform-wide scan during P1 found 26 occurrences across six bundles —
     * publish buttons, status labels and toasts. Those are UI chrome, not conversational
     * content, and repairing them would be the broad renderer rewrite P1
     * explicitly excludes. They are registered as CR-21 and pinned by
     * platform_wide_icon_in_text_defect_has_not_grown() below, so the finding is
     * recorded and guarded rather than quietly dropped.
     *
     * This assertion covers the CHAT render path, which is what CR-01 is.
     */
    public function no_icon_svg_is_assigned_to_text_content_in_the_chat_render_path(): void
    {
        foreach (['public/app/js/arthur-chat.js', 'public/app/js/messages-ui.js'] as $file) {
            $src = $this->src($file);
            $this->assertDoesNotMatchRegularExpression(
                '/\.textContent\s*=\s*[^;\n]*window\.icon\s*\(/',
                $src,
                "{$file} assigns an icon helper's SVG string to .textContent — the raw <svg …> tag renders as visible text (clause G-04)"
            );
        }
    }

    /**
     * @test
     *
     * CR-21 — the same defect class outside chat. Pinned at its measured P1
     * baseline so it cannot silently spread while it waits to be scoped.
     * Lowering this number is welcome; raising it is a regression.
     */
    public function platform_wide_icon_in_text_defect_has_not_grown(): void
    {
        $baseline = 26;   // measured 2026-07-26: builder 9, crm 6, creative 5, core 3, write 2, calendar 1
        $total = 0;
        $perFile = [];
        foreach (glob(base_path('public/app/js/*.js')) as $path) {
            if (str_contains($path, '.bak')) {
                continue;
            }
            $n = preg_match_all('/\.textContent\s*=[^;\n]*window\.icon\s*\(/', (string) file_get_contents($path));
            if ($n > 0) {
                $perFile[basename($path)] = $n;
                $total += $n;
            }
        }

        $this->assertLessThanOrEqual(
            $baseline,
            $total,
            'CR-21 has grown beyond its P1 baseline — more UI code is now writing icon markup into text contexts: '
            . json_encode($perFile)
        );
    }

    /** @test */
    public function no_icon_svg_is_concatenated_into_a_message_body(): void
    {
        foreach (['public/app/js/builder.js', 'public/app/js/arthur-chat.js'] as $file) {
            $src = $this->src($file);
            $this->assertDoesNotMatchRegularExpression(
                '/(AddMsg|addMessage|appendMessage)\s*\([^)]*window\.icon\s*\(/',
                $src,
                "{$file} concatenates an icon helper's SVG output into a chat message body (clause G-04/G-05)"
            );
        }
    }

    /** @test */
    public function errors_are_not_rendered_through_the_assistant_message_path(): void
    {
        $src = $this->src('public/app/js/builder.js');
        $this->assertDoesNotMatchRegularExpression(
            "/AddMsg\s*\(\s*['\"]assistant['\"][^)]*\b(e|err|error)\.message/",
            $src,
            'builder.js renders a caught error as an assistant message, so a billing or infrastructure failure appears to the customer as something the agent said (clause X-08/F-07)'
        );
    }

    /** @test */
    public function aria_credit_refusal_does_not_quote_internal_metering_to_the_customer(): void
    {
        $src = \Tests\Support\RouteSource::all();

        // The customer-facing string must not explain the internal rate formula.
        $this->assertStringNotContainsString(
            'Not enough credits — chat costs 0.1 credit (1 credit per 10 chats). Please top up.',
            $src,
            'the Aria/SEO credit refusal still returns the original machine copy quoting the internal metering formula (clause X-02)'
        );
    }

    /** @test */
    public function aria_credit_refusal_carries_a_structured_error_code(): void
    {
        $src = \Tests\Support\RouteSource::all();

        // Both assistant refusal sites must classify with the closed taxonomy.
        $occurrences = preg_match_all("/'code'\s*=>\s*'CHAT_INSUFFICIENT_CREDITS'/", $src);
        $this->assertGreaterThanOrEqual(
            2,
            $occurrences,
            'the assistant credit-refusal sites do not emit a CHAT_INSUFFICIENT_CREDITS code from the closed taxonomy (clause X-01)'
        );
    }

    /** @test */
    public function no_provider_call_occurs_inside_an_insufficient_credit_branch(): void
    {
        $src = \Tests\Support\RouteSource::all();
        if (!preg_match_all('/if\s*\(!\s*\$\w*meter\w*\[.sufficient.\]\)\s*\{(.{0,1200}?)\n\s*\}/s', $src, $m)) {
            $this->markTestSkipped('no insufficient-credit branch located');
        }
        foreach ($m[1] as $i => $branch) {
            $this->assertDoesNotMatchRegularExpression(
                '/RuntimeClient|->chatJson\(|->aiRun\(|Http::post\(/',
                $branch,
                "insufficient-credit branch #{$i} invokes a provider after refusing (clause K-02)"
            );
        }
    }

    /** @test */
    public function no_persisted_message_content_contains_component_markup(): void
    {
        // Guards the store as well as the renderer: once serialized, a leak is
        // permanent and survives any client fix.
        if (!\Illuminate\Support\Facades\Schema::hasTable('agent_messages')) {
            $this->markTestSkipped('agent_messages absent');
        }
        $bad = \Illuminate\Support\Facades\DB::table('agent_messages')
            ->where('content', 'like', '%<svg%')
            ->count();

        $this->assertSame(0, $bad, 'component SVG markup has been serialized into stored message content (clause G-05)');
    }
}
