<?php

namespace Tests\Feature\Frontend;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** A11Y CONTRACT (2026-08-31). Every defect below was live in the SPA today and was found by measuring the running
 *  app, not by reading the source. The shell is edited constantly by this loop, so each fix is pinned here: these
 *  assertions fail the moment one is undone.
 *
 *  Deliberately source-level. It cannot replace driving the browser — it exists so a regression is caught in CI
 *  rather than by a customer. */
class ShellAccessibilityContractTest extends TestCase
{
    private string $html;
    private string $social;
    private string $projects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->html     = File::get(public_path('app/index.html'));
        $this->social   = File::get(public_path('app/js/social.js'));
        $this->projects = File::get(public_path('app/js/projects.js'));
    }

    /** A11Y-2b: id="da-title" belonged to an <input>; duplicating it on a heading broke getElementById().value. */
    public function test_no_element_id_is_declared_twice(): void
    {
        preg_match_all('/\sid="([^"]+)"/', $this->html, $m);
        $counts = array_count_values($m[1]);
        $dupes  = array_keys(array_filter($counts, fn ($n) => $n > 1));
        $this->assertSame([], $dupes, 'duplicate ids silently hand every consumer the wrong element: ' . implode(', ', $dupes));
    }

    /** A11Y-1/A11Y-2: a dialog announces itself and carries a name. */
    public function test_every_shell_dialog_declares_its_role_and_name(): void
    {
        $this->assertSame(5, substr_count($this->html, 'role="dialog"'), 'the five shell dialogs must keep their role');
        foreach (['am-title', 'sm-title', 'nm-title', 'da-dialog-title', 'td-title'] as $labelledBy) {
            $this->assertStringContainsString('aria-labelledby="' . $labelledBy . '"', $this->html, "dialog labelled by {$labelledBy}");
        }
    }

    /** A11Y-1c/1d/2: a closed dialog must leave the tab order — 13 controls were reachable inside invisible dialogs. */
    public function test_closed_dialogs_are_hidden_not_merely_transparent(): void
    {
        foreach (['.modal-backdrop{', '.da-backdrop{'] as $sel) {
            $i = strpos($this->html, $sel);
            $this->assertNotFalse($i, "{$sel} rule missing");
            $rule = substr($this->html, $i, 400);
            $this->assertStringContainsString('visibility:hidden', $rule, "{$sel} must hide, not just fade");
        }
        $this->assertStringContainsString("attributeFilter: ['class', 'inert']", $this->html, 'A11Y-1f: the inert mirror must be self-healing');
        $this->assertStringContainsString('if (open === !isInert) return;', $this->html, 'A11Y-1f: the write guard prevents an observer loop');
    }

    /** A11Y-4: the agent team was ten <div onclick> — mouse-only. */
    public function test_agent_team_cards_are_buttons_with_pressed_state(): void
    {
        $this->assertStringContainsString('<button type="button" id="at-card-', $this->html);
        $this->assertStringContainsString('aria-pressed="', $this->html);
    }

    /** TEAM-1: the toggle re-fetched and threw the click away, so choosing a team never worked. */
    public function test_choosing_an_agent_renders_the_choice_instead_of_refetching_it_away(): void
    {
        $this->assertStringContainsString('window._renderAgentTeam', $this->html);
        $i = strpos($this->html, 'window._toggleAgent=function');
        $this->assertNotFalse($i);
        $body = substr($this->html, $i, 900);
        $this->assertStringNotContainsString('window._loadAgentTeam();', $body, 're-fetching here discards the owner\'s click');
        $this->assertStringContainsString('window._renderAgentTeam();', $body);
    }

    /** A11Y-5: all 32 content-calendar days were <div onclick>. */
    public function test_social_calendar_days_are_buttons_with_a_date_label(): void
    {
        $this->assertStringContainsString('<button type="button" class="cal-day', $this->social);
        $this->assertStringContainsString('aria-label="', $this->social);
        $this->assertStringNotContainsString('html += \'<div class="cal-day\' + (iT', $this->social, 'day cells must not go back to divs');
        $i = strpos($this->html, '.cal-day{');
        $this->assertNotFalse($i);
        $this->assertStringContainsString('background:none', substr($this->html, $i, 220), 'buttons need the UA chrome reset');
    }

    /** A11Y-6: visible labels that were never associated — including the password fields. */
    public function test_visible_labels_are_associated_with_their_control(): void
    {
        foreach (['prof-name', 'prof-email', 'prof-cur-pw', 'prof-new-pw', 'prof-conf-pw', 'st-industry'] as $id) {
            $this->assertStringContainsString('<label for="' . $id . '"', $this->html, "label for {$id} must be associated");
        }
        $this->assertStringContainsString('aria-label="Search projects by name or goal"', $this->projects);
    }

    /** A11Y-3: nothing may outrank the keyboard focus ring. */
    public function test_the_keyboard_focus_ring_is_restored_where_it_was_suppressed(): void
    {
        $this->assertStringContainsString('.dm-ta:focus:not(:focus-visible)', $this->html, 'the DM composer may only be quiet for the mouse');
        foreach (['#ws-create-title:focus-visible', '#ws-pub-domain:focus-visible', '.header-search input:focus-visible'] as $sel) {
            $this->assertStringContainsString($sel, $this->html, "{$sel} must keep its ring");
        }
    }

    /** PUBLISH-3 was briefly corrupted by a lost escaping level: \b became a literal backspace byte. */
    public function test_no_control_characters_leaked_into_the_shell_or_scripts(): void
    {
        foreach (['index.html' => $this->html, 'social.js' => $this->social, 'projects.js' => $this->projects] as $name => $src) {
            $this->assertStringNotContainsString(chr(8), $src, "{$name} contains a backspace byte — an escaping level was lost");
        }
    }
}
