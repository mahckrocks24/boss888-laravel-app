<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Support\GenerationVariableContract as C;
use Tests\TestCase;

/**
 * BUILDER888 · P1-8B — the provider → Builder boundary.
 *
 * Templates declare 1,890 placeholders and every one is scalar; repeating
 * content lives in indexed families (service_1_title, testimonial_2_quote…).
 * These tests prove untrusted provider output becomes a flat scalar map,
 * that lists expand into those families, and that nothing unrepresentable is
 * guessed at.
 */
class GenerationContractTest extends TestCase
{
    // ── scalars keep their meaning ──────────────────────────────────────────

    public function test_scalars_pass_through_as_strings(): void
    {
        $r = C::fromProvider(['business_name' => 'Northgate', 'founded' => 1998, 'active' => true]);

        $this->assertSame('Northgate', $r['variables']['business_name']);
        $this->assertSame('1998', $r['variables']['founded']);
        $this->assertSame('1', $r['variables']['active']);
        $this->assertSame([], $r['rejected']);
    }

    public function test_null_and_empty_are_dropped_not_rendered(): void
    {
        $r = C::fromProvider(['a' => null, 'b' => '', 'c' => []]);
        $this->assertSame([], $r['variables']);
        $this->assertSame([], $r['rejected']);
    }

    // ── the frozen failure: a list of services ──────────────────────────────

    public function test_scalar_service_list_expands_into_indexed_family(): void
    {
        $r = C::fromProvider(['services' => ['Puncture repair', 'Gear tuning', 'Brake service']]);

        $this->assertSame('Puncture repair', $r['variables']['service_1_title']);
        $this->assertSame('Gear tuning',     $r['variables']['service_2_title']);
        $this->assertSame('Brake service',   $r['variables']['service_3_title']);
        $this->assertSame('',                $r['variables']['service_1_display']);
        $this->assertSame('display:none',    $r['variables']['service_4_display']);
        $this->assertNotEmpty($r['expanded']);
        $this->assertSame([], $r['rejected']);
    }

    public function test_list_of_structures_expands_field_by_field(): void
    {
        $r = C::fromProvider(['testimonials' => [
            ['quote' => 'Fixed my bike same day.', 'author' => 'Priya'],
            ['quote' => 'Honest and fast.',        'author' => 'Tom'],
        ]]);

        $this->assertSame('Fixed my bike same day.', $r['variables']['testimonial_1_quote']);
        $this->assertSame('Priya',                   $r['variables']['testimonial_1_author']);
        $this->assertSame('Tom',                     $r['variables']['testimonial_2_author']);
        $this->assertSame([], $r['rejected']);
    }

    public function test_irregular_plurals_are_recognised(): void
    {
        $r = C::fromProvider(['faqs' => [['q' => 'Do you do walk-ins?', 'a' => 'Yes.']]]);
        $this->assertSame('Do you do walk-ins?', $r['variables']['faq_1_q']);
        $this->assertSame('Yes.',                $r['variables']['faq_1_a']);
    }

    public function test_expansion_is_capped_at_the_template_slot_count(): void
    {
        $r = C::fromProvider(['services' => array_map(fn($i) => "S$i", range(1, 20))]);

        $this->assertArrayHasKey('service_6_title', $r['variables']);
        $this->assertArrayNotHasKey('service_7_title', $r['variables']);
    }

    public function test_expansion_respects_the_templates_declared_placeholders(): void
    {
        // A template that only lays out two service slots.
        $declared = ['service_1_title' => true, 'service_2_title' => true, 'service_3_display' => true];
        $r = C::fromProvider(['services' => ['A', 'B', 'C']], $declared);

        $this->assertSame('A', $r['variables']['service_1_title']);
        $this->assertSame('B', $r['variables']['service_2_title']);
        $this->assertArrayNotHasKey('service_3_title', $r['variables']);
    }

    // ── lists with no family ────────────────────────────────────────────────

    public function test_unknown_scalar_list_falls_back_to_a_readable_join(): void
    {
        $r = C::fromProvider(['hero_subtitle' => ['Architecture', 'Interior Design', 'Consulting']]);
        $this->assertSame('Architecture, Interior Design, Consulting', $r['variables']['hero_subtitle']);
        $this->assertSame([], $r['rejected']);
    }

    public function test_unknown_list_of_structures_is_rejected_not_guessed(): void
    {
        $r = C::fromProvider(['mystery' => [['a' => 1], ['b' => 2]]]);

        $this->assertArrayNotHasKey('mystery', $r['variables']);
        $this->assertNotEmpty($r['rejected']);
    }

    public function test_associative_map_is_rejected_not_flattened(): void
    {
        $r = C::fromProvider(['hours' => ['monday' => '9-5', 'tuesday' => '9-5']]);

        $this->assertArrayNotHasKey('hours', $r['variables']);
        $this->assertStringContainsString('hours', implode(' ', $r['rejected']));
    }

    public function test_decoded_json_object_is_rejected(): void
    {
        $r = C::fromProvider(['blob' => json_decode('{"a":1}')]);
        $this->assertArrayNotHasKey('blob', $r['variables']);
        $this->assertNotEmpty($r['rejected']);
    }

    // ── the whole point: output is always a flat scalar map ─────────────────

    public function test_output_is_always_a_flat_map_of_strings(): void
    {
        $hostile = [
            'business_name' => 'Northgate',
            'services'      => ['A', 'B'],
            'testimonials'  => [['quote' => 'q', 'author' => 'a']],
            'hours'         => ['mon' => '9-5'],
            'blob'          => json_decode('{"x":[1,2]}'),
            'nested'        => [[1, 2], [3, 4]],
            'empty'         => [],
            'n'             => null,
        ];

        foreach (C::fromProvider($hostile)['variables'] as $k => $v) {
            $this->assertIsString($k);
            $this->assertIsString($v, "value for {$k} is not a string");
        }
    }

    public function test_every_produced_value_survives_str_replace(): void
    {
        $r = C::fromProvider([
            'services'     => ['A', 'B', 'C'],
            'testimonials' => [['quote' => 'q', 'author' => 'a']],
            'hours'        => ['mon' => '9-5'],
            'odd'          => json_decode('{"z":1}'),
        ]);

        $html = '<p>{{service_1_title}} {{testimonial_1_quote}}</p>';
        foreach ($r['variables'] as $k => $v) {
            $html = str_replace('{{' . $k . '}}', $v, $html);   // must never TypeError
        }

        $this->assertStringContainsString('A', $html);
        $this->assertStringContainsString('q', $html);
    }
}
