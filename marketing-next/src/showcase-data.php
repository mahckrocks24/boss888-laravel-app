<?php
declare(strict_types=1);
/**
 * The sites we show. Every one is a real website Arthur built from the brief printed beside it, published on a real
 * subdomain a visitor can open right now. Build seconds are measured (16-showcase.php, 2026-09-08). Nothing is a mock-up
 * and nothing is a "template preview": the brief went in, this came out.
 *
 * A site is listed here only when the published page carries the customer's own business name. The estate-agent build of
 * the same run is deliberately absent: it published under an invented broker's name (RISK-0145).
 */
return [
    'transcript' => [
        // The real conversation, captured 2026-09-08 (17-transcript.php). Times are the measured round trips.
        ['who' => 'you', 'text' => "I run Ironhaus, a strength and conditioning gym in Bristol. Small-group coaching, Olympic lifting platforms, a proper barbell floor."],
        ['who' => 'arthur', 'text' => "That sounds like a proper lifting gym — I love it. What kind of vibe should the website have: sleek and modern, raw and industrial, or minimal and clean?", 'ms' => 5268],
        ['who' => 'you', 'text' => "Industrial. Small-group strength coaching, Olympic lifting, a 6-week on-ramp for beginners, open gym hours. Orange on charcoal."],
        ['who' => 'arthur', 'text' => "Here's what I have: Ironhaus, Bristol. Strength and conditioning gym. Small-group coaching, Olympic lifting, a 6-week on-ramp, open gym. Bold and motivating, orange on charcoal. Add your logo and photos below, then I'll build it.", 'ms' => 5452],
        ['who' => 'you', 'text' => "That's right. Build it."],
    ],
    'build_steps' => ['Reading the brief', 'Choosing the layout', 'Writing every page', 'Making the images', 'Publishing'],
    'build_seconds' => 18,
    'sites' => [
        ['slug' => 'ironhaus', 'name' => 'Ironhaus', 'what' => 'Strength gym', 'where' => 'Bristol', 'seconds' => 18,
         'brief' => 'A strength and conditioning gym in Bristol. Small-group coaching, Olympic lifting, a 6-week on-ramp for beginners. Bold and industrial, orange on charcoal.'],
        ['slug' => 'harperlane', 'name' => 'Harper Lane Interiors', 'what' => 'Interior design studio', 'where' => 'Edinburgh', 'seconds' => 22,
         'brief' => 'A residential interior design studio in Edinburgh. Full-house renovations, kitchens, joinery, styling. Quiet luxury, photography-led.'],
        ['slug' => 'aurelia', 'name' => 'Aurelia', 'what' => 'Italian restaurant', 'where' => 'London', 'seconds' => 34,
         'brief' => 'A modern Italian restaurant in Marylebone. Sharing plates, an open kitchen, a serious wine list. Elegant and warm, burgundy and cream.'],
        ['slug' => 'northgatedental', 'name' => 'Northgate Dental Studio', 'what' => 'Dental practice', 'where' => 'Leeds', 'seconds' => 22,
         'brief' => 'A private dental practice in Leeds. Invisalign, implants, hygiene, nervous-patient care. Calm and clinical, teal and white.'],
        ['slug' => 'lumiereclinic', 'name' => 'Lumiere Clinic', 'what' => 'Aesthetics clinic', 'where' => 'Manchester', 'seconds' => 29,
         'brief' => 'A nurse-led aesthetics and skin clinic in Manchester. Injectables, medical facials, laser. Premium and clinical, consultation first.'],
    ],
];
