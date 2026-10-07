<?php

namespace Tests\Unit\Builder;

use App\Engines\Builder\Services\UpgradeOfferService;
use PHPUnit\Framework\TestCase;

/**
 * UPGRADE-OFFER-1 — the field-name translation from a Classic design to a new one. Pure: no database, no files.
 * A shared name stays; a known older name is translated; a catalogue item moves to the family the new design uses;
 * the design's interface words and technical fields are never treated as the owner's content.
 */
class UpgradeOfferMappingTest extends TestCase
{
    public function test_shared_names_stay(): void
    {
        $this->assertSame('hero_title', UpgradeOfferService::targetName('hero_title'));
        $this->assertSame('service_3_price', UpgradeOfferService::targetName('service_3_price'));
    }

    public function test_older_names_are_translated_keeping_their_numbers(): void
    {
        $this->assertSame('marq_2', UpgradeOfferService::targetName('proof_2'));
        $this->assertSame('step_4_body', UpgradeOfferService::targetName('process_4_text'));
        $this->assertSame('testimonial_3_name', UpgradeOfferService::targetName('testimonial_3_author'));
        $this->assertSame('book_title', UpgradeOfferService::targetName('booking_title'));
        $this->assertSame('footer_blurb', UpgradeOfferService::targetName('footer_tagline'));
    }

    public function test_technical_fields_are_not_owner_content(): void
    {
        foreach (['canonical_url', 'og_locale', 'primary_deep', 'lu_hidden_blocks', 'section:hero', 'footer_col_2'] as $k) {
            $this->assertSame('', UpgradeOfferService::targetName($k), $k);
        }
    }

    public function test_catalogue_items_move_to_the_family_the_new_design_uses(): void
    {
        $hotel = ['room_2_title' => [], 'room_2_price' => [], 'room_2_text' => []];
        $this->assertSame('room_2_title', UpgradeOfferService::resolve('service_2_title', $hotel));
        $this->assertSame('room_2_price', UpgradeOfferService::resolve('service_2_price', $hotel));
        $travel = ['package_1_title' => []];
        $this->assertSame('package_1_title', UpgradeOfferService::resolve('service_1_title', $travel));
        // a design that has the name keeps it
        $this->assertSame('service_1_title', UpgradeOfferService::resolve('service_1_title', ['service_1_title' => [], 'room_1_title' => []]));
        // nothing to move to: the name stays, so the check reports it as having no place
        $this->assertSame('service_9_tag_1', UpgradeOfferService::resolve('service_9_tag_1', $hotel));
    }

    public function test_aliases_list_only_the_names_that_change(): void
    {
        $a = UpgradeOfferService::aliasesFor(['hero_title', 'proof_1', 'service_1_title', 'canonical_url'], ['hero_title' => [], 'marq_1' => [], 'room_1_title' => []]);
        $this->assertSame(['proof_1' => 'marq_1', 'service_1_title' => 'room_1_title'], $a);
    }
}
