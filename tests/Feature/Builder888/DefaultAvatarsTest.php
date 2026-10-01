<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\TemplateService;
use Tests\TestCase;

/** DEFAULT TEAM AVATARS (2026-09-06): named people without an uploaded photo get a platform avatar, never the hero photo. */
class DefaultAvatarsTest extends TestCase
{
    private function defaults(string $slug): array
    {
        $m = (new TemplateService())->getManifest($slug) ?: [];
        $vars = [];
        foreach (($m['variables'] ?? []) as $k => $spec) { $vars[$k] = is_array($spec) ? (string) ($spec['default'] ?? '') : ''; }
        return $vars;
    }

    public function test_person_slot_detection(): void
    {
        $this->assertSame(['prefix' => 'doctor', 'n' => 2], TemplateService::personSlotFor('doctor_2_image'));
        $this->assertSame(['prefix' => 'testimonial', 'n' => 1], TemplateService::personSlotFor('testimonial_1_avatar'));
        $this->assertNull(TemplateService::personSlotFor('gallery_3_image'));
        $this->assertNull(TemplateService::personSlotFor('hero_image'));
    }

    public function test_a_named_person_without_a_photo_gets_an_avatar_not_the_hero(): void
    {
        if (glob(storage_path('app/public/builder-avatars/avatar_*.jpg')) === []) $this->markTestSkipped('avatar set not generated');
        $vars = $this->defaults('dental');
        $vars['doctor_1_name'] = 'Dr Amina Khalid';
        $vars['doctor_1_image'] = '';
        $html = (new TemplateService())->render('dental', $vars, null);
        $this->assertMatchesRegularExpression('#/storage/builder-avatars/(?:avatar|photo)_\d\d\.jpg#', $html);
        // the doctor card must not carry the industry hero photo
        $this->assertDoesNotMatchRegularExpression('#data-field="doctor_1_image"[^>]*src="/storage/builder-heroes#', $html);
    }

    public function test_an_unnamed_person_slot_becomes_an_obvious_team_member_placeholder_with_an_avatar(): void
    {
        if (glob(storage_path('app/public/builder-avatars/avatar_*.jpg')) === []) $this->markTestSkipped('avatar set not generated');
        $vars = $this->defaults('pet_services');
        for ($i = 1; $i <= 4; $i++) { $vars["member_{$i}_name"] = ''; $vars["member_{$i}_role"] = ''; $vars["member_{$i}_image"] = '/storage/template-images/pet_services/gallery_1_image.jpg'; }
        $html = (new TemplateService())->render('pet_services', $vars, null);
        $this->assertDoesNotMatchRegularExpression('#member_\d_image" style="background-image:url\(\'[^\']*gallery_#', $html, 'a shop photo must never stand in for a person');
        $this->assertMatchesRegularExpression('#member_1_image" style="background-image:url\(\'/storage/builder-avatars/(?:avatar|photo)_\d\d\.jpg#', $html);
        $this->assertStringContainsString('data-field="member_1_name">Team Member<', $html);
    }

    public function test_a_customer_uploaded_photo_is_kept(): void
    {
        $vars = $this->defaults('pet_services');
        $vars['member_1_name'] = 'Layla'; $vars['member_1_image'] = '/storage/uploads/ws2/layla.jpg';
        $html = (new TemplateService())->render('pet_services', $vars, null);
        $this->assertStringContainsString('/storage/uploads/ws2/layla.jpg', $html);
    }

    public function test_avatar_choice_is_deterministic_and_rotates_per_slot(): void
    {
        if (glob(storage_path('app/public/builder-avatars/avatar_*.jpg')) === []) $this->markTestSkipped('avatar set not generated');
        $a = TemplateService::defaultAvatar('dental', 1); $b = TemplateService::defaultAvatar('dental', 2);
        $this->assertSame($a, TemplateService::defaultAvatar('dental', 1));
        $this->assertNotSame($a, $b);
    }
}
