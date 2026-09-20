<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\ArthurService;
use Tests\TestCase;

/**
 * RISK-0168 root cause (2026-09-20, EV-1077) — the pool is applied to every slot that still holds the manifest's
 * industry-hero placeholder, even when the hero-floor lookup came back null (a "borrowed" build). A design's own
 * shipped photograph (a portrait under /storage/template-images/…) is real content and is never replaced.
 */
class ImagePoolFloorTest extends TestCase
{
    private function inject(array $vars, array $manifest, array $pool, ?string $hero): array
    {
        $svc = app(ArthurService::class);
        $m = new \ReflectionMethod($svc, 'injectImagesToTemplate'); $m->setAccessible(true);
        $m->invokeArgs($svc, [&$vars, $manifest, $pool, $hero, false]);
        return $vars;
    }

    public function test_hero_placeholders_take_pool_photos_even_when_the_floor_lookup_is_null(): void
    {
        $hero = '/storage/builder-heroes/event_venue.jpg';
        $manifest = ['variables' => [
            'hero_image' => ['type' => 'image', 'default' => $hero],
            'service_1_image' => ['type' => 'image', 'default' => $hero],
            'service_2_image' => ['type' => 'image', 'default' => $hero],
            'gallery_1_image' => ['type' => 'image', 'default' => $hero],
            'story_image' => ['type' => 'image', 'default' => '/storage/template-images/portraits/realtor_1.jpg'],
            'headline' => ['type' => 'text', 'default' => 'x'],
        ]];
        $vars = ['hero_image' => $hero, 'service_1_image' => $hero, 'service_2_image' => $hero, 'gallery_1_image' => $hero, 'story_image' => '/storage/template-images/portraits/realtor_1.jpg'];
        $pool = ['/storage/template-images/hospitality/gallery_1.jpg', '/storage/template-images/hospitality/gallery_4.jpg', '/storage/template-images/hospitality/room_5_image.jpg'];
        $out = $this->inject($vars, $manifest, $pool, null);
        $this->assertSame($hero, $out['hero_image'], 'the hero keeps its floor');
        $this->assertNotSame($hero, $out['service_1_image']); $this->assertNotSame($hero, $out['service_2_image']); $this->assertNotSame($hero, $out['gallery_1_image']);
        $this->assertCount(3, array_unique([$out['service_1_image'], $out['service_2_image'], $out['gallery_1_image']]), 'three slots, three different photographs');
        $this->assertSame('/storage/template-images/portraits/realtor_1.jpg', $out['story_image'], 'a design-shipped portrait is content, not a placeholder');
        // and with the floor known, the same outcome
        $out2 = $this->inject($vars, $manifest, $pool, $hero);
        $this->assertNotSame($hero, $out2['service_1_image']); $this->assertSame($hero, $out2['hero_image']);
    }

    public function test_a_customers_own_photo_is_never_replaced(): void
    {
        $hero = '/storage/builder-heroes/gym.jpg';
        $manifest = ['variables' => ['service_1_image' => ['type' => 'image', 'default' => $hero], 'service_2_image' => ['type' => 'image', 'default' => $hero]]];
        $vars = ['service_1_image' => '/storage/uploads/42/my-gym.jpg', 'service_2_image' => $hero];
        $out = $this->inject($vars, $manifest, ['/storage/template-images/cafe/gallery_1.jpg'], null);
        $this->assertSame('/storage/uploads/42/my-gym.jpg', $out['service_1_image']);
        $this->assertSame('/storage/template-images/cafe/gallery_1.jpg', $out['service_2_image']);
    }
}
