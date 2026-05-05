<?php

// Studio Engine — 20 hand-crafted seed templates.
// Each template has: distinct name, category, format, palette, layout,
// real media URL from the catalog, full 6-layer layers_json, and
// business-agnostic placeholder text that Arthur/users will customize.

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// ── Pick canonical URLs from the media catalog by category ─────────
$pickUrls = function (string $cat, int $n = 3): array {
    return DB::table('media')
        ->where('is_platform_asset', 1)
        ->where('category', $cat)
        ->whereNotNull('url')
        ->limit($n * 4) // over-fetch for variety
        ->get(['id', 'url', 'industry'])
        ->toArray();
};
$BG = $pickUrls('background', 12);
$HERO = $pickUrls('hero', 12);
$GALLERY = $pickUrls('gallery', 12);
$OBJECT = $pickUrls('object', 8);
$PEOPLE = $pickUrls('people', 10);
$SOCIAL = $pickUrls('social', 10);

$urlOf = function (array $bucket, int $idx) {
    $bucket = array_values($bucket);
    $n = count($bucket);
    if ($n === 0) return '';
    return $bucket[$idx % $n]->url;
};

// ── Canvas sizes per format ────────────────────────────────────────
$canvas = [
    'square'    => [1080, 1080],
    'portrait'  => [1080, 1350],
    'story'     => [1080, 1920],
    'landscape' => [1200, 628],
    'pinterest' => [1000, 1500],
];

// ── Helper: build a full 6-layer stack with overrides ──────────────
$mkLayers = function (array $o) use ($canvas) {
    $fmt = $o['format'];
    [$w, $h] = $canvas[$fmt];
    return [
        'format' => $fmt,
        'canvas_width'  => $w,
        'canvas_height' => $h,
        'layers' => [
            [
                'id' => 'background', 'type' => 'background', 'locked' => true, 'visible' => true,
                'data' => [
                    'mode'           => $o['bg_mode'] ?? 'image',
                    'image_url'      => $o['bg_url']  ?? null,
                    'color'          => $o['bg_color']    ?? '#1A1A1A',
                    'gradient'       => $o['bg_gradient'] ?? null,
                    'overlay_color'  => $o['bg_overlay_color']   ?? 'rgba(0,0,0,0.45)',
                    'overlay_opacity'=> $o['bg_overlay_opacity'] ?? 45,
                ],
            ],
            [
                'id' => 'overlay', 'type' => 'overlay', 'locked' => false, 'visible' => $o['overlay_visible'] ?? false,
                'data' => [
                    'mode'         => $o['overlay_mode'] ?? 'color',
                    'color'        => $o['overlay_color']   ?? '#000000',
                    'opacity'      => $o['overlay_opacity'] ?? 20,
                    'pattern'      => $o['overlay_pattern'] ?? 'none',
                    'pattern_size' => 24,
                ],
            ],
            [
                'id' => 'media', 'type' => 'media', 'locked' => false, 'visible' => $o['media_visible'] ?? false,
                'data' => [
                    'image_url' => $o['media_url']     ?? null,
                    'position'  => $o['media_position']?? ['x' => 50, 'y' => 50],
                    'size'      => $o['media_size']    ?? ['width' => 60, 'height' => 60],
                    'opacity'   => 100,
                    'removed_bg'=> false,
                ],
            ],
            [
                'id' => 'shapes', 'type' => 'shapes', 'locked' => false, 'visible' => !empty($o['shapes']),
                'data' => ['items' => $o['shapes'] ?? []],
            ],
            [
                'id' => 'text', 'type' => 'text', 'locked' => false, 'visible' => true,
                'data' => ['items' => $o['text_items'] ?? []],
            ],
            [
                'id' => 'effects', 'type' => 'effects', 'locked' => false, 'visible' => !empty($o['effects']),
                'data' => [
                    'vignette' => $o['effects']['vignette'] ?? ['enabled' => false, 'intensity' => 30],
                    'filter'   => $o['effects']['filter']   ?? 'none',
                    'filter_intensity' => $o['effects']['filter_intensity'] ?? 50,
                ],
            ],
        ],
    ];
};

// ── 10 Color Palettes ──────────────────────────────────────────────
// These inform the text/shape colors per template. See spec.
$paletteDarkBold    = ['bg'=>'#0A0A0A','accent'=>'#FF4D00','text'=>'#FFFFFF','muted'=>'rgba(255,255,255,0.65)'];
$paletteNavyGold    = ['bg'=>'#0F2044','accent'=>'#C9943A','text'=>'#FFFFFF','muted'=>'rgba(255,255,255,0.7)'];
$paletteMinimal     = ['bg'=>'#FFFFFF','accent'=>'#1A1A1A','text'=>'#333333','muted'=>'#666666'];
$paletteLuxuryDark  = ['bg'=>'#1C1C1E','accent'=>'#C9943A','text'=>'#FAF7F2','muted'=>'rgba(250,247,242,0.65)'];
$paletteFreshGreen  = ['bg'=>'#F0F7F0','accent'=>'#16A34A','text'=>'#1A1A1A','muted'=>'#546454'];
$paletteBlushRose   = ['bg'=>'#FDF0F3','accent'=>'#B76E79','text'=>'#2D1B1E','muted'=>'#7A5A5E'];
$paletteOceanBlue   = ['bg'=>'#EBF4FF','accent'=>'#2563EB','text'=>'#0F172A','muted'=>'#475569'];
$paletteWarmCream   = ['bg'=>'#FDF6EC','accent'=>'#D4622A','text'=>'#2C1810','muted'=>'#7A5A44'];
$paletteBoldRed     = ['bg'=>'#1A0000','accent'=>'#DC2626','text'=>'#FFFFFF','muted'=>'rgba(255,255,255,0.7)'];
$paletteSageNatural = ['bg'=>'#F2F7F2','accent'=>'#4A7C59','text'=>'#1A2A1A','muted'=>'#5C6E5F'];

// ── Template definitions ───────────────────────────────────────────
$templates = [];

// ── PROMOTIONAL × 3 ────────────────────────────────────────────────
// 1. Dark full-bleed with bottom-left stack (warm cream palette).
$templates[] = [
    'name' => 'Dark Promo — Bottom Stack',
    'category' => 'promotional', 'sub_category' => 'bold_dark',
    'industry_tags' => ['retail','fitness','events','any'], 'demographic' => 'all',
    'format' => 'square',
    '_layers' => $mkLayers([
        'format' => 'square',
        'bg_mode' => 'image', 'bg_url' => $urlOf($BG, 0),
        'bg_overlay_color' => 'rgba(0,0,0,0.55)', 'bg_overlay_opacity' => 55,
        'shapes' => [
            ['type'=>'rectangle','x'=>0,'y'=>0,'width'=>1,'height'=>100,'color'=>$paletteDarkBold['accent'],'opacity'=>100,'border_radius'=>0,'border_color'=>null,'border_width'=>0],
        ],
        'text_items' => [
            ['id'=>'badge','role'=>'badge','content'=>'LIMITED OFFER','x'=>5,'y'=>60,'font_family'=>'Inter','font_size'=>12,'font_weight'=>'600','color'=>$paletteDarkBold['accent'],'text_transform'=>'uppercase','letter_spacing'=>2.0,'alignment'=>'left','editable'=>true],
            ['id'=>'headline','role'=>'headline','content'=>'Your Bold Headline','x'=>5,'y'=>68,'font_family'=>'Bebas Neue','font_size'=>72,'font_weight'=>'400','color'=>$paletteDarkBold['text'],'alignment'=>'left','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'Supporting line explains the offer in one clear sentence.','x'=>5,'y'=>85,'font_family'=>'Inter','font_size'=>16,'font_weight'=>'400','color'=>$paletteDarkBold['muted'],'alignment'=>'left','editable'=>true],
            ['id'=>'cta','role'=>'cta','content'=>'Shop Now  →','x'=>5,'y'=>92,'font_family'=>'Inter','font_size'=>14,'font_weight'=>'600','color'=>$paletteDarkBold['text'],'text_transform'=>'uppercase','letter_spacing'=>1.5,'alignment'=>'left','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>95,'y'=>96,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'400','color'=>$paletteDarkBold['muted'],'alignment'=>'right','editable'=>false],
        ],
    ]),
];

// 2. Light/minimal split (white bg, accent panel right).
$templates[] = [
    'name' => 'Minimal Promo — Clean Split',
    'category' => 'promotional', 'sub_category' => 'minimal_light',
    'industry_tags' => ['beauty','wellness','fashion','any'], 'demographic' => 'professional',
    'format' => 'square',
    '_layers' => $mkLayers([
        'format' => 'square',
        'bg_mode' => 'color', 'bg_color' => $paletteMinimal['bg'], 'bg_overlay_opacity' => 0, 'bg_overlay_color' => 'rgba(0,0,0,0)',
        'media_visible' => true, 'media_url' => $urlOf($HERO, 2),
        'media_position' => ['x' => 0, 'y' => 0], 'media_size' => ['width' => 60, 'height' => 100],
        'shapes' => [
            ['type'=>'rectangle','x'=>60,'y'=>0,'width'=>40,'height'=>100,'color'=>$paletteMinimal['bg'],'opacity'=>100,'border_radius'=>0,'border_color'=>null,'border_width'=>0],
        ],
        'text_items' => [
            ['id'=>'badge','role'=>'badge','content'=>'NEW COLLECTION','x'=>64,'y'=>35,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'500','color'=>$paletteMinimal['accent'],'text_transform'=>'uppercase','letter_spacing'=>3.0,'alignment'=>'left','editable'=>true],
            ['id'=>'headline','role'=>'headline','content'=>'Your Signature Launch','x'=>64,'y'=>45,'font_family'=>'Playfair Display','font_size'=>40,'font_weight'=>'400','color'=>$paletteMinimal['accent'],'alignment'=>'left','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'A short supporting line goes on this second text row.','x'=>64,'y'=>62,'font_family'=>'Inter','font_size'=>14,'font_weight'=>'400','color'=>$paletteMinimal['text'],'alignment'=>'left','editable'=>true],
            ['id'=>'cta','role'=>'cta','content'=>'Discover  →','x'=>64,'y'=>75,'font_family'=>'Inter','font_size'=>13,'font_weight'=>'600','color'=>$paletteMinimal['accent'],'alignment'=>'left','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>64,'y'=>90,'font_family'=>'Inter','font_size'=>10,'font_weight'=>'400','color'=>$paletteMinimal['muted'],'text_transform'=>'uppercase','letter_spacing'=>2.0,'alignment'=>'left','editable'=>false],
        ],
    ]),
];

// 3. Ocean portrait, centered badge layout.
$templates[] = [
    'name' => 'Ocean Blue — Centered Announce',
    'category' => 'promotional', 'sub_category' => 'centered_portrait',
    'industry_tags' => ['tech','saas','finance','any'], 'demographic' => 'professional',
    'format' => 'portrait',
    '_layers' => $mkLayers([
        'format' => 'portrait',
        'bg_mode' => 'gradient', 'bg_gradient' => ['from'=>'#EBF4FF','to'=>'#DBEAFE','direction'=>'160deg'],
        'bg_overlay_opacity' => 0, 'bg_overlay_color' => 'rgba(0,0,0,0)',
        'shapes' => [
            ['type'=>'circle','x'=>42,'y'=>18,'width'=>16,'height'=>13,'color'=>$paletteOceanBlue['accent'],'opacity'=>100,'border_radius'=>999,'border_color'=>null,'border_width'=>0],
        ],
        'text_items' => [
            ['id'=>'badge','role'=>'badge','content'=>'★','x'=>50,'y'=>24,'font_family'=>'Inter','font_size'=>26,'font_weight'=>'400','color'=>'#FFFFFF','alignment'=>'center','editable'=>false],
            ['id'=>'headline','role'=>'headline','content'=>'Announcing Our Next Release','x'=>50,'y'=>42,'font_family'=>'Space Grotesk','font_size'=>44,'font_weight'=>'600','color'=>$paletteOceanBlue['text'],'alignment'=>'center','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'A one-line promise that sets the context and invites the reader to click through for more.','x'=>50,'y'=>60,'font_family'=>'Inter','font_size'=>15,'font_weight'=>'400','color'=>$paletteOceanBlue['muted'],'alignment'=>'center','editable'=>true],
            ['id'=>'cta','role'=>'cta','content'=>'READ MORE','x'=>50,'y'=>75,'font_family'=>'Inter','font_size'=>12,'font_weight'=>'700','color'=>$paletteOceanBlue['accent'],'text_transform'=>'uppercase','letter_spacing'=>2.5,'alignment'=>'center','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>50,'y'=>94,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'400','color'=>$paletteOceanBlue['muted'],'alignment'=>'center','editable'=>false],
        ],
    ]),
];

// ── QUOTE × 2 ─────────────────────────────────────────────────────
// 4. Dark luxury quote.
$templates[] = [
    'name' => 'Luxury Quote — Editorial Dark',
    'category' => 'quote', 'sub_category' => 'dark_editorial',
    'industry_tags' => ['luxury','hospitality','real_estate','any'], 'demographic' => 'professional',
    'format' => 'square',
    '_layers' => $mkLayers([
        'format' => 'square',
        'bg_mode' => 'color', 'bg_color' => $paletteLuxuryDark['bg'], 'bg_overlay_opacity' => 0, 'bg_overlay_color' => 'rgba(0,0,0,0)',
        'text_items' => [
            ['id'=>'mark','role'=>'badge','content'=>'“','x'=>10,'y'=>28,'font_family'=>'Playfair Display','font_size'=>130,'font_weight'=>'700','color'=>$paletteLuxuryDark['accent'],'alignment'=>'left','editable'=>false],
            ['id'=>'headline','role'=>'headline','content'=>'A short quote that captures the idea in under twenty words.','x'=>10,'y'=>50,'font_family'=>'Cormorant Garamond','font_size'=>36,'font_weight'=>'500','color'=>$paletteLuxuryDark['text'],'alignment'=>'left','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'— Attribution, Role','x'=>10,'y'=>85,'font_family'=>'Inter','font_size'=>13,'font_weight'=>'500','color'=>$paletteLuxuryDark['accent'],'text_transform'=>'uppercase','letter_spacing'=>2.0,'alignment'=>'left','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>90,'y'=>94,'font_family'=>'Inter','font_size'=>10,'font_weight'=>'400','color'=>$paletteLuxuryDark['muted'],'alignment'=>'right','editable'=>false],
        ],
    ]),
];

// 5. Minimal white quote.
$templates[] = [
    'name' => 'Minimal Quote — White Serif',
    'category' => 'quote', 'sub_category' => 'light_serif',
    'industry_tags' => ['consulting','editorial','wellness','any'], 'demographic' => 'all',
    'format' => 'square',
    '_layers' => $mkLayers([
        'format' => 'square',
        'bg_mode' => 'color', 'bg_color' => '#FFFFFF', 'bg_overlay_opacity' => 0, 'bg_overlay_color' => 'rgba(0,0,0,0)',
        'shapes' => [
            ['type'=>'line','x'=>44,'y'=>78,'width'=>12,'height'=>0.4,'color'=>'#1A1A1A','opacity'=>100,'border_radius'=>0,'border_color'=>null,'border_width'=>0],
        ],
        'text_items' => [
            ['id'=>'mark','role'=>'badge','content'=>'“','x'=>50,'y'=>25,'font_family'=>'Playfair Display','font_size'=>90,'font_weight'=>'700','color'=>'#1A1A1A','alignment'=>'center','editable'=>false],
            ['id'=>'headline','role'=>'headline','content'=>'Great work is done by people who believe in what they are building.','x'=>50,'y'=>48,'font_family'=>'Playfair Display','font_size'=>32,'font_weight'=>'500','color'=>'#1A1A1A','alignment'=>'center','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'— Attribution','x'=>50,'y'=>84,'font_family'=>'Inter','font_size'=>13,'font_weight'=>'400','color'=>'#666666','alignment'=>'center','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>50,'y'=>95,'font_family'=>'Inter','font_size'=>10,'font_weight'=>'400','color'=>'#999999','text_transform'=>'uppercase','letter_spacing'=>2.0,'alignment'=>'center','editable'=>false],
        ],
    ]),
];

// ── PRODUCT/SERVICE × 2 ───────────────────────────────────────────
// 6. Product feature — tech object.
$templates[] = [
    'name' => 'Product Feature — Object Spotlight',
    'category' => 'product', 'sub_category' => 'object_spotlight',
    'industry_tags' => ['technology','ecommerce','saas','retail'], 'demographic' => 'professional',
    'format' => 'square',
    '_layers' => $mkLayers([
        'format' => 'square',
        'bg_mode' => 'gradient', 'bg_gradient' => ['from'=>'#0F172A','to'=>'#1E293B','direction'=>'135deg'],
        'bg_overlay_opacity' => 0, 'bg_overlay_color' => 'rgba(0,0,0,0)',
        'media_visible' => true, 'media_url' => $urlOf($OBJECT, 0),
        'media_position' => ['x' => 10, 'y' => 25], 'media_size' => ['width' => 42, 'height' => 50],
        'text_items' => [
            ['id'=>'badge','role'=>'badge','content'=>'NEW PRODUCT','x'=>55,'y'=>30,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'600','color'=>'#60A5FA','text_transform'=>'uppercase','letter_spacing'=>2.5,'alignment'=>'left','editable'=>true],
            ['id'=>'headline','role'=>'headline','content'=>'Meet The New Flagship','x'=>55,'y'=>40,'font_family'=>'Space Grotesk','font_size'=>36,'font_weight'=>'600','color'=>'#FFFFFF','alignment'=>'left','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'A fast summary of what makes this product different — one sentence or two.','x'=>55,'y'=>57,'font_family'=>'Inter','font_size'=>14,'font_weight'=>'400','color'=>'rgba(255,255,255,0.75)','alignment'=>'left','editable'=>true],
            ['id'=>'cta','role'=>'cta','content'=>'LEARN MORE  →','x'=>55,'y'=>78,'font_family'=>'Inter','font_size'=>12,'font_weight'=>'600','color'=>'#60A5FA','text_transform'=>'uppercase','letter_spacing'=>2.0,'alignment'=>'left','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>55,'y'=>92,'font_family'=>'Inter','font_size'=>10,'font_weight'=>'400','color'=>'rgba(255,255,255,0.45)','alignment'=>'left','editable'=>false],
        ],
    ]),
];

// 7. Service spotlight — wellness sage.
$templates[] = [
    'name' => 'Service Spotlight — Sage Portrait',
    'category' => 'product', 'sub_category' => 'service_portrait',
    'industry_tags' => ['wellness','beauty','healthcare','hospitality'], 'demographic' => 'female',
    'format' => 'portrait',
    '_layers' => $mkLayers([
        'format' => 'portrait',
        'bg_mode' => 'image', 'bg_url' => $urlOf($HERO, 5),
        'bg_overlay_color' => 'rgba(74,124,89,0.55)', 'bg_overlay_opacity' => 55,
        'shapes' => [
            ['type'=>'rectangle','x'=>8,'y'=>56,'width'=>84,'height'=>34,'color'=>'#FFFFFF','opacity'=>95,'border_radius'=>12,'border_color'=>null,'border_width'=>0],
        ],
        'text_items' => [
            ['id'=>'badge','role'=>'badge','content'=>'SIGNATURE SERVICE','x'=>50,'y'=>61,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'600','color'=>$paletteSageNatural['accent'],'text_transform'=>'uppercase','letter_spacing'=>3.0,'alignment'=>'center','editable'=>true],
            ['id'=>'headline','role'=>'headline','content'=>'Ninety Minutes, Fully Yours','x'=>50,'y'=>68,'font_family'=>'Cormorant Garamond','font_size'=>38,'font_weight'=>'500','color'=>$paletteSageNatural['text'],'alignment'=>'center','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'What it includes, in one line.','x'=>50,'y'=>80,'font_family'=>'Inter','font_size'=>14,'font_weight'=>'400','color'=>$paletteSageNatural['muted'],'alignment'=>'center','editable'=>true],
            ['id'=>'cta','role'=>'cta','content'=>'BOOK NOW','x'=>50,'y'=>87,'font_family'=>'Inter','font_size'=>12,'font_weight'=>'700','color'=>$paletteSageNatural['accent'],'text_transform'=>'uppercase','letter_spacing'=>2.5,'alignment'=>'center','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>50,'y'=>96,'font_family'=>'Inter','font_size'=>10,'font_weight'=>'400','color'=>'#FFFFFF','alignment'=>'center','editable'=>false],
        ],
    ]),
];

// ── EVENT/ANNOUNCEMENT × 2 ────────────────────────────────────────
// 8. Event announcement — bold date.
$templates[] = [
    'name' => 'Event Announce — Bold Date',
    'category' => 'event', 'sub_category' => 'date_prominent',
    'industry_tags' => ['events','hospitality','conference','any'], 'demographic' => 'all',
    'format' => 'square',
    '_layers' => $mkLayers([
        'format' => 'square',
        'bg_mode' => 'color', 'bg_color' => $paletteNavyGold['bg'], 'bg_overlay_opacity' => 0, 'bg_overlay_color' => 'rgba(0,0,0,0)',
        'shapes' => [
            ['type'=>'rectangle','x'=>10,'y'=>10,'width'=>80,'height'=>0.4,'color'=>$paletteNavyGold['accent'],'opacity'=>100,'border_radius'=>0,'border_color'=>null,'border_width'=>0],
            ['type'=>'rectangle','x'=>10,'y'=>89.6,'width'=>80,'height'=>0.4,'color'=>$paletteNavyGold['accent'],'opacity'=>100,'border_radius'=>0,'border_color'=>null,'border_width'=>0],
        ],
        'text_items' => [
            ['id'=>'badge','role'=>'badge','content'=>'SAVE THE DATE','x'=>50,'y'=>18,'font_family'=>'Inter','font_size'=>12,'font_weight'=>'600','color'=>$paletteNavyGold['accent'],'text_transform'=>'uppercase','letter_spacing'=>3.5,'alignment'=>'center','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'JUN','x'=>50,'y'=>30,'font_family'=>'Bebas Neue','font_size'=>28,'font_weight'=>'400','color'=>$paletteNavyGold['accent'],'alignment'=>'center','editable'=>true],
            ['id'=>'headline','role'=>'headline','content'=>'18','x'=>50,'y'=>50,'font_family'=>'Bebas Neue','font_size'=>150,'font_weight'=>'700','color'=>$paletteNavyGold['text'],'alignment'=>'center','editable'=>true],
            ['id'=>'cta','role'=>'cta','content'=>'Annual Gathering 2026','x'=>50,'y'=>78,'font_family'=>'Playfair Display','font_size'=>22,'font_weight'=>'400','color'=>$paletteNavyGold['text'],'alignment'=>'center','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>50,'y'=>94,'font_family'=>'Inter','font_size'=>10,'font_weight'=>'400','color'=>$paletteNavyGold['muted'],'text_transform'=>'uppercase','letter_spacing'=>2.5,'alignment'=>'center','editable'=>false],
        ],
    ]),
];

// 9. Hiring announcement — fresh green.
$templates[] = [
    'name' => 'Hiring — Fresh Green',
    'category' => 'event', 'sub_category' => 'hiring',
    'industry_tags' => ['tech','startup','any'], 'demographic' => 'professional',
    'format' => 'square',
    '_layers' => $mkLayers([
        'format' => 'square',
        'bg_mode' => 'color', 'bg_color' => $paletteFreshGreen['bg'], 'bg_overlay_opacity' => 0, 'bg_overlay_color' => 'rgba(0,0,0,0)',
        'shapes' => [
            ['type'=>'rectangle','x'=>10,'y'=>20,'width'=>80,'height'=>60,'color'=>'#FFFFFF','opacity'=>100,'border_radius'=>16,'border_color'=>$paletteFreshGreen['accent'],'border_width'=>2],
            ['type'=>'circle','x'=>40,'y'=>27,'width'=>20,'height'=>12,'color'=>$paletteFreshGreen['accent'],'opacity'=>100,'border_radius'=>999,'border_color'=>null,'border_width'=>0],
        ],
        'text_items' => [
            ['id'=>'badge','role'=>'badge','content'=>"WE'RE HIRING",'x'=>50,'y'=>31,'font_family'=>'Inter','font_size'=>13,'font_weight'=>'700','color'=>'#FFFFFF','text_transform'=>'uppercase','letter_spacing'=>2.0,'alignment'=>'center','editable'=>true],
            ['id'=>'headline','role'=>'headline','content'=>'Senior Product Designer','x'=>50,'y'=>48,'font_family'=>'DM Sans','font_size'=>30,'font_weight'=>'700','color'=>$paletteFreshGreen['text'],'alignment'=>'center','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'Full-time · Remote / Dubai · Apply by May 15','x'=>50,'y'=>60,'font_family'=>'Inter','font_size'=>13,'font_weight'=>'500','color'=>$paletteFreshGreen['muted'],'alignment'=>'center','editable'=>true],
            ['id'=>'cta','role'=>'cta','content'=>'APPLY AT OUR CAREERS PAGE','x'=>50,'y'=>72,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'600','color'=>$paletteFreshGreen['accent'],'text_transform'=>'uppercase','letter_spacing'=>2.5,'alignment'=>'center','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>50,'y'=>92,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'600','color'=>$paletteFreshGreen['text'],'text_transform'=>'uppercase','letter_spacing'=>2.0,'alignment'=>'center','editable'=>false],
        ],
    ]),
];

// ── EDUCATIONAL × 2 ───────────────────────────────────────────────
// 10. Numbered steps — clean light.
$templates[] = [
    'name' => 'Tips Card — 3 Numbered Steps',
    'category' => 'educational', 'sub_category' => 'steps',
    'industry_tags' => ['any','consulting','wellness','finance'], 'demographic' => 'all',
    'format' => 'portrait',
    '_layers' => $mkLayers([
        'format' => 'portrait',
        'bg_mode' => 'color', 'bg_color' => $paletteWarmCream['bg'], 'bg_overlay_opacity' => 0, 'bg_overlay_color' => 'rgba(0,0,0,0)',
        'text_items' => [
            ['id'=>'badge','role'=>'badge','content'=>'3 TIPS TO START','x'=>8,'y'=>8,'font_family'=>'Inter','font_size'=>12,'font_weight'=>'700','color'=>$paletteWarmCream['accent'],'text_transform'=>'uppercase','letter_spacing'=>2.5,'alignment'=>'left','editable'=>true],
            ['id'=>'headline','role'=>'headline','content'=>'Three Ways To Get Started Today','x'=>8,'y'=>15,'font_family'=>'Playfair Display','font_size'=>36,'font_weight'=>'500','color'=>$paletteWarmCream['text'],'alignment'=>'left','editable'=>true],
            ['id'=>'step1_num','role'=>'subheading','content'=>'01','x'=>8,'y'=>36,'font_family'=>'Bebas Neue','font_size'=>48,'font_weight'=>'400','color'=>$paletteWarmCream['accent'],'alignment'=>'left','editable'=>false],
            ['id'=>'step1','role'=>'subheading','content'=>'Start with a short audit — know the gap before you build.','x'=>22,'y'=>39,'font_family'=>'Inter','font_size'=>15,'font_weight'=>'500','color'=>$paletteWarmCream['text'],'alignment'=>'left','editable'=>true],
            ['id'=>'step2_num','role'=>'subheading','content'=>'02','x'=>8,'y'=>54,'font_family'=>'Bebas Neue','font_size'=>48,'font_weight'=>'400','color'=>$paletteWarmCream['accent'],'alignment'=>'left','editable'=>false],
            ['id'=>'step2','role'=>'subheading','content'=>'Pick one metric that matters and watch it weekly.','x'=>22,'y'=>57,'font_family'=>'Inter','font_size'=>15,'font_weight'=>'500','color'=>$paletteWarmCream['text'],'alignment'=>'left','editable'=>true],
            ['id'=>'step3_num','role'=>'subheading','content'=>'03','x'=>8,'y'=>72,'font_family'=>'Bebas Neue','font_size'=>48,'font_weight'=>'400','color'=>$paletteWarmCream['accent'],'alignment'=>'left','editable'=>false],
            ['id'=>'step3','role'=>'subheading','content'=>'Ship the smallest version that teaches you something.','x'=>22,'y'=>75,'font_family'=>'Inter','font_size'=>15,'font_weight'=>'500','color'=>$paletteWarmCream['text'],'alignment'=>'left','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>50,'y'=>96,'font_family'=>'Inter','font_size'=>10,'font_weight'=>'500','color'=>$paletteWarmCream['muted'],'text_transform'=>'uppercase','letter_spacing'=>2.5,'alignment'=>'center','editable'=>false],
        ],
    ]),
];

// 11. Big stat/number.
$templates[] = [
    'name' => 'Stats Headline — Huge Number',
    'category' => 'educational', 'sub_category' => 'big_number',
    'industry_tags' => ['finance','tech','consulting','any'], 'demographic' => 'professional',
    'format' => 'square',
    '_layers' => $mkLayers([
        'format' => 'square',
        'bg_mode' => 'color', 'bg_color' => $paletteBoldRed['bg'], 'bg_overlay_opacity' => 0, 'bg_overlay_color' => 'rgba(0,0,0,0)',
        'text_items' => [
            ['id'=>'badge','role'=>'badge','content'=>'BY THE NUMBERS','x'=>50,'y'=>12,'font_family'=>'Inter','font_size'=>12,'font_weight'=>'700','color'=>$paletteBoldRed['accent'],'text_transform'=>'uppercase','letter_spacing'=>3.0,'alignment'=>'center','editable'=>true],
            ['id'=>'headline','role'=>'headline','content'=>'87%','x'=>50,'y'=>50,'font_family'=>'Bebas Neue','font_size'=>220,'font_weight'=>'700','color'=>$paletteBoldRed['accent'],'alignment'=>'center','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'of customers say this matters most','x'=>50,'y'=>78,'font_family'=>'Inter','font_size'=>17,'font_weight'=>'500','color'=>$paletteBoldRed['text'],'alignment'=>'center','editable'=>true],
            ['id'=>'cta','role'=>'cta','content'=>'SOURCE: OUR 2026 SURVEY','x'=>50,'y'=>87,'font_family'=>'Inter','font_size'=>10,'font_weight'=>'500','color'=>$paletteBoldRed['muted'],'text_transform'=>'uppercase','letter_spacing'=>2.0,'alignment'=>'center','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>50,'y'=>94,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'600','color'=>'#FFFFFF','alignment'=>'center','editable'=>false],
        ],
    ]),
];

// ── BRAND × 2 ─────────────────────────────────────────────────────
// 12. Team member spotlight.
$templates[] = [
    'name' => 'Team Spotlight — Portrait',
    'category' => 'brand', 'sub_category' => 'team',
    'industry_tags' => ['any','consulting','agency','professional'], 'demographic' => 'professional',
    'format' => 'portrait',
    '_layers' => $mkLayers([
        'format' => 'portrait',
        'bg_mode' => 'color', 'bg_color' => $paletteLuxuryDark['bg'], 'bg_overlay_opacity' => 0, 'bg_overlay_color' => 'rgba(0,0,0,0)',
        'media_visible' => true, 'media_url' => $urlOf($PEOPLE, 0),
        'media_position' => ['x' => 0, 'y' => 0], 'media_size' => ['width' => 100, 'height' => 60],
        'text_items' => [
            ['id'=>'badge','role'=>'badge','content'=>'MEET THE TEAM','x'=>8,'y'=>65,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'600','color'=>$paletteLuxuryDark['accent'],'text_transform'=>'uppercase','letter_spacing'=>3.0,'alignment'=>'left','editable'=>true],
            ['id'=>'headline','role'=>'headline','content'=>'Layla Al-Hashimi','x'=>8,'y'=>72,'font_family'=>'Playfair Display','font_size'=>36,'font_weight'=>'500','color'=>$paletteLuxuryDark['text'],'alignment'=>'left','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'Head of Client Services · With us for 6 years','x'=>8,'y'=>82,'font_family'=>'Inter','font_size'=>13,'font_weight'=>'400','color'=>$paletteLuxuryDark['muted'],'alignment'=>'left','editable'=>true],
            ['id'=>'cta','role'=>'cta','content'=>'“What I love most is the craft of small details.”','x'=>8,'y'=>90,'font_family'=>'Cormorant Garamond','font_size'=>16,'font_weight'=>'400','color'=>$paletteLuxuryDark['text'],'alignment'=>'left','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>92,'y'=>96,'font_family'=>'Inter','font_size'=>10,'font_weight'=>'400','color'=>$paletteLuxuryDark['muted'],'alignment'=>'right','editable'=>false],
        ],
    ]),
];

// 13. Milestone — blush rose.
$templates[] = [
    'name' => 'Milestone — Blush Celebration',
    'category' => 'brand', 'sub_category' => 'milestone',
    'industry_tags' => ['beauty','wellness','retail','any'], 'demographic' => 'female',
    'format' => 'square',
    '_layers' => $mkLayers([
        'format' => 'square',
        'bg_mode' => 'color', 'bg_color' => $paletteBlushRose['bg'], 'bg_overlay_opacity' => 0, 'bg_overlay_color' => 'rgba(0,0,0,0)',
        'shapes' => [
            ['type'=>'circle','x'=>30,'y'=>20,'width'=>40,'height'=>30,'color'=>$paletteBlushRose['accent'],'opacity'=>100,'border_radius'=>999,'border_color'=>null,'border_width'=>0],
        ],
        'text_items' => [
            ['id'=>'headline','role'=>'headline','content'=>'5','x'=>50,'y'=>36,'font_family'=>'Bebas Neue','font_size'=>120,'font_weight'=>'700','color'=>'#FFFFFF','alignment'=>'center','editable'=>true],
            ['id'=>'badge','role'=>'badge','content'=>'YEARS','x'=>50,'y'=>55,'font_family'=>'Inter','font_size'=>14,'font_weight'=>'600','color'=>$paletteBlushRose['accent'],'text_transform'=>'uppercase','letter_spacing'=>4.0,'alignment'=>'center','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'Thank you to every customer who has walked through our door.','x'=>50,'y'=>70,'font_family'=>'Playfair Display','font_size'=>22,'font_weight'=>'400','color'=>$paletteBlushRose['text'],'alignment'=>'center','editable'=>true],
            ['id'=>'cta','role'=>'cta','content'=>'HERE IS TO THE NEXT FIVE','x'=>50,'y'=>85,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'600','color'=>$paletteBlushRose['accent'],'text_transform'=>'uppercase','letter_spacing'=>2.5,'alignment'=>'center','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>50,'y'=>94,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'600','color'=>$paletteBlushRose['text'],'alignment'=>'center','editable'=>false],
        ],
    ]),
];

// ── STORY 9:16 × 3 ────────────────────────────────────────────────
// 14. Story promo — dark bold vertical.
$templates[] = [
    'name' => 'Story Promo — Bold Vertical',
    'category' => 'story', 'sub_category' => 'promo_vertical',
    'industry_tags' => ['fashion','fitness','events','any'], 'demographic' => 'all',
    'format' => 'story',
    '_layers' => $mkLayers([
        'format' => 'story',
        'bg_mode' => 'image', 'bg_url' => $urlOf($HERO, 7),
        'bg_overlay_color' => 'rgba(0,0,0,0.45)', 'bg_overlay_opacity' => 45,
        'text_items' => [
            ['id'=>'badge','role'=>'badge','content'=>'SWIPE UP','x'=>50,'y'=>90,'font_family'=>'Inter','font_size'=>13,'font_weight'=>'600','color'=>'#FFFFFF','text_transform'=>'uppercase','letter_spacing'=>3.0,'alignment'=>'center','editable'=>true],
            ['id'=>'headline','role'=>'headline','content'=>'Only This Weekend','x'=>50,'y'=>40,'font_family'=>'Bebas Neue','font_size'=>96,'font_weight'=>'700','color'=>'#FFFFFF','alignment'=>'center','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'A short second line — keep it under twelve words.','x'=>50,'y'=>55,'font_family'=>'Inter','font_size'=>18,'font_weight'=>'500','color'=>'rgba(255,255,255,0.85)','alignment'=>'center','editable'=>true],
            ['id'=>'cta','role'=>'cta','content'=>'Link in bio  ↑','x'=>50,'y'=>85,'font_family'=>'Inter','font_size'=>14,'font_weight'=>'500','color'=>'rgba(255,255,255,0.7)','alignment'=>'center','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>50,'y'=>96,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'500','color'=>'rgba(255,255,255,0.7)','text_transform'=>'uppercase','letter_spacing'=>2.5,'alignment'=>'center','editable'=>false],
        ],
    ]),
];

// 15. Story — engagement question.
$templates[] = [
    'name' => 'Story — Poll Question',
    'category' => 'story', 'sub_category' => 'engagement',
    'industry_tags' => ['any','retail','wellness','community'], 'demographic' => 'all',
    'format' => 'story',
    '_layers' => $mkLayers([
        'format' => 'story',
        'bg_mode' => 'gradient', 'bg_gradient' => ['from'=>'#FDF0F3','to'=>'#F3D4DA','direction'=>'180deg'],
        'bg_overlay_opacity' => 0, 'bg_overlay_color' => 'rgba(0,0,0,0)',
        'shapes' => [
            ['type'=>'rectangle','x'=>8,'y'=>58,'width'=>84,'height'=>8,'color'=>'#FFFFFF','opacity'=>100,'border_radius'=>999,'border_color'=>null,'border_width'=>0],
            ['type'=>'rectangle','x'=>8,'y'=>70,'width'=>84,'height'=>8,'color'=>'#FFFFFF','opacity'=>100,'border_radius'=>999,'border_color'=>null,'border_width'=>0],
        ],
        'text_items' => [
            ['id'=>'badge','role'=>'badge','content'=>'QUICK QUESTION','x'=>50,'y'=>20,'font_family'=>'Inter','font_size'=>12,'font_weight'=>'600','color'=>$paletteBlushRose['accent'],'text_transform'=>'uppercase','letter_spacing'=>3.0,'alignment'=>'center','editable'=>true],
            ['id'=>'headline','role'=>'headline','content'=>'Which are you right now?','x'=>50,'y'=>40,'font_family'=>'Playfair Display','font_size'=>52,'font_weight'=>'500','color'=>$paletteBlushRose['text'],'alignment'=>'center','editable'=>true],
            ['id'=>'poll1','role'=>'subheading','content'=>'Morning person ☀','x'=>50,'y'=>61,'font_family'=>'Inter','font_size'=>18,'font_weight'=>'600','color'=>$paletteBlushRose['text'],'alignment'=>'center','editable'=>true],
            ['id'=>'poll2','role'=>'subheading','content'=>'Night owl 🌙','x'=>50,'y'=>73,'font_family'=>'Inter','font_size'=>18,'font_weight'=>'600','color'=>$paletteBlushRose['text'],'alignment'=>'center','editable'=>true],
            ['id'=>'cta','role'=>'cta','content'=>'Tap to vote','x'=>50,'y'=>84,'font_family'=>'Inter','font_size'=>13,'font_weight'=>'400','color'=>$paletteBlushRose['muted'],'alignment'=>'center','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>50,'y'=>96,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'500','color'=>$paletteBlushRose['muted'],'text_transform'=>'uppercase','letter_spacing'=>2.5,'alignment'=>'center','editable'=>false],
        ],
    ]),
];

// 16. Story — personal/brand.
$templates[] = [
    'name' => 'Story — Personal Behind The Scenes',
    'category' => 'story', 'sub_category' => 'personal',
    'industry_tags' => ['any','agency','creator','consulting'], 'demographic' => 'all',
    'format' => 'story',
    '_layers' => $mkLayers([
        'format' => 'story',
        'bg_mode' => 'image', 'bg_url' => $urlOf($GALLERY, 4),
        'bg_overlay_color' => 'rgba(15,32,68,0.6)', 'bg_overlay_opacity' => 60,
        'text_items' => [
            ['id'=>'badge','role'=>'badge','content'=>'BEHIND THE SCENES','x'=>50,'y'=>12,'font_family'=>'Inter','font_size'=>12,'font_weight'=>'600','color'=>'#FFFFFF','text_transform'=>'uppercase','letter_spacing'=>3.5,'alignment'=>'center','editable'=>true],
            ['id'=>'headline','role'=>'headline','content'=>'“A day in the life”','x'=>50,'y'=>45,'font_family'=>'Cormorant Garamond','font_size'=>64,'font_weight'=>'400','color'=>'#FFFFFF','alignment'=>'center','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'Three things we do before any client call.','x'=>50,'y'=>62,'font_family'=>'Inter','font_size'=>16,'font_weight'=>'400','color'=>'rgba(255,255,255,0.85)','alignment'=>'center','editable'=>true],
            ['id'=>'cta','role'=>'cta','content'=>'WATCH THE REEL  →','x'=>50,'y'=>82,'font_family'=>'Inter','font_size'=>12,'font_weight'=>'700','color'=>'#FFFFFF','text_transform'=>'uppercase','letter_spacing'=>2.5,'alignment'=>'center','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>50,'y'=>96,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'500','color'=>'rgba(255,255,255,0.7)','text_transform'=>'uppercase','letter_spacing'=>2.5,'alignment'=>'center','editable'=>false],
        ],
    ]),
];

// ── CAROUSEL × 2 ──────────────────────────────────────────────────
// 17. Carousel slide 1 — series header.
$templates[] = [
    'name' => 'Carousel Slide 1 — Series Header',
    'category' => 'carousel', 'sub_category' => 'slide_one',
    'industry_tags' => ['any','consulting','agency','saas'], 'demographic' => 'professional',
    'format' => 'square',
    '_layers' => $mkLayers([
        'format' => 'square',
        'bg_mode' => 'color', 'bg_color' => '#111827', 'bg_overlay_opacity' => 0, 'bg_overlay_color' => 'rgba(0,0,0,0)',
        'shapes' => [
            ['type'=>'rectangle','x'=>8,'y'=>8,'width'=>12,'height'=>4,'color'=>'#F59E0B','opacity'=>100,'border_radius'=>4,'border_color'=>null,'border_width'=>0],
        ],
        'text_items' => [
            ['id'=>'badge','role'=>'badge','content'=>'SERIES · 5 PARTS','x'=>50,'y'=>22,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'600','color'=>'#F59E0B','text_transform'=>'uppercase','letter_spacing'=>3.5,'alignment'=>'center','editable'=>true],
            ['id'=>'headline','role'=>'headline','content'=>'Everything I learned running my first ten campaigns','x'=>50,'y'=>48,'font_family'=>'Space Grotesk','font_size'=>38,'font_weight'=>'600','color'=>'#FFFFFF','alignment'=>'center','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'A 5-slide thread. Save this.','x'=>50,'y'=>68,'font_family'=>'Inter','font_size'=>14,'font_weight'=>'400','color'=>'rgba(255,255,255,0.7)','alignment'=>'center','editable'=>true],
            ['id'=>'cta','role'=>'cta','content'=>'SLIDE 1 / 5 →','x'=>50,'y'=>87,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'600','color'=>'#F59E0B','text_transform'=>'uppercase','letter_spacing'=>2.0,'alignment'=>'center','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>50,'y'=>95,'font_family'=>'Inter','font_size'=>10,'font_weight'=>'400','color'=>'rgba(255,255,255,0.45)','alignment'=>'center','editable'=>false],
        ],
    ]),
];

// 18. Carousel slide — tip number.
$templates[] = [
    'name' => 'Carousel Tip — Numbered Middle',
    'category' => 'carousel', 'sub_category' => 'slide_middle',
    'industry_tags' => ['any','education','consulting','saas'], 'demographic' => 'all',
    'format' => 'square',
    '_layers' => $mkLayers([
        'format' => 'square',
        'bg_mode' => 'color', 'bg_color' => '#F8FAFC', 'bg_overlay_opacity' => 0, 'bg_overlay_color' => 'rgba(0,0,0,0)',
        'shapes' => [
            ['type'=>'rectangle','x'=>8,'y'=>92,'width'=>6,'height'=>1,'color'=>'#2563EB','opacity'=>100,'border_radius'=>999,'border_color'=>null,'border_width'=>0],
        ],
        'text_items' => [
            ['id'=>'badge','role'=>'badge','content'=>'TIP #03','x'=>8,'y'=>10,'font_family'=>'Inter','font_size'=>12,'font_weight'=>'700','color'=>'#2563EB','text_transform'=>'uppercase','letter_spacing'=>3.0,'alignment'=>'left','editable'=>true],
            ['id'=>'headline','role'=>'headline','content'=>'Always send the first message yourself','x'=>8,'y'=>32,'font_family'=>'Space Grotesk','font_size'=>38,'font_weight'=>'600','color'=>'#0F172A','alignment'=>'left','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'Your first 100 customers should recognise your voice. Automate later, not now.','x'=>8,'y'=>54,'font_family'=>'Inter','font_size'=>15,'font_weight'=>'400','color'=>'#475569','alignment'=>'left','editable'=>true],
            ['id'=>'cta','role'=>'cta','content'=>'SLIDE 3 / 5','x'=>8,'y'=>88,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'600','color'=>'#2563EB','text_transform'=>'uppercase','letter_spacing'=>2.5,'alignment'=>'left','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>92,'y'=>95,'font_family'=>'Inter','font_size'=>10,'font_weight'=>'400','color'=>'#94A3B8','alignment'=>'right','editable'=>false],
        ],
    ]),
];

// ── SEASONAL × 2 ──────────────────────────────────────────────────
// 19. Ramadan gold.
$templates[] = [
    'name' => 'Ramadan Kareem — Gold Dark',
    'category' => 'seasonal', 'sub_category' => 'ramadan',
    'industry_tags' => ['retail','food','any','mena'], 'demographic' => 'arab',
    'format' => 'square',
    '_layers' => $mkLayers([
        'format' => 'square',
        'bg_mode' => 'color', 'bg_color' => '#0B0A06', 'bg_overlay_opacity' => 0, 'bg_overlay_color' => 'rgba(0,0,0,0)',
        'shapes' => [
            ['type'=>'circle','x'=>40,'y'=>18,'width'=>20,'height'=>15,'color'=>'transparent','opacity'=>100,'border_radius'=>999,'border_color'=>'#D4A84E','border_width'=>2],
        ],
        'text_items' => [
            ['id'=>'badge','role'=>'badge','content'=>'☾','x'=>50,'y'=>22,'font_family'=>'Inter','font_size'=>48,'font_weight'=>'400','color'=>'#D4A84E','alignment'=>'center','editable'=>false],
            ['id'=>'headline','role'=>'headline','content'=>'Ramadan Kareem','x'=>50,'y'=>50,'font_family'=>'Playfair Display','font_size'=>56,'font_weight'=>'500','color'=>'#D4A84E','alignment'=>'center','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'Wishing you a blessed month of reflection, gratitude, and generosity.','x'=>50,'y'=>68,'font_family'=>'Cormorant Garamond','font_size'=>20,'font_weight'=>'400','color'=>'rgba(212,168,78,0.75)','alignment'=>'center','editable'=>true],
            ['id'=>'cta','role'=>'cta','content'=>'— FROM ALL OF US —','x'=>50,'y'=>85,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'500','color'=>'rgba(212,168,78,0.6)','text_transform'=>'uppercase','letter_spacing'=>3.5,'alignment'=>'center','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>50,'y'=>94,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'500','color'=>'rgba(212,168,78,0.85)','text_transform'=>'uppercase','letter_spacing'=>2.5,'alignment'=>'center','editable'=>false],
        ],
    ]),
];

// 20. New Year celebration.
$templates[] = [
    'name' => 'New Year — Confetti Celebration',
    'category' => 'seasonal', 'sub_category' => 'new_year',
    'industry_tags' => ['any','retail','events','restaurant'], 'demographic' => 'all',
    'format' => 'square',
    '_layers' => $mkLayers([
        'format' => 'square',
        'bg_mode' => 'gradient', 'bg_gradient' => ['from'=>'#0F172A','to'=>'#1E1B4B','direction'=>'135deg'],
        'bg_overlay_opacity' => 0, 'bg_overlay_color' => 'rgba(0,0,0,0)',
        'shapes' => [
            ['type'=>'circle','x'=>14,'y'=>22,'width'=>3,'height'=>2,'color'=>'#FCD34D','opacity'=>85,'border_radius'=>999,'border_color'=>null,'border_width'=>0],
            ['type'=>'circle','x'=>82,'y'=>18,'width'=>2,'height'=>1.5,'color'=>'#EC4899','opacity'=>85,'border_radius'=>999,'border_color'=>null,'border_width'=>0],
            ['type'=>'circle','x'=>88,'y'=>74,'width'=>3,'height'=>2,'color'=>'#60A5FA','opacity'=>85,'border_radius'=>999,'border_color'=>null,'border_width'=>0],
            ['type'=>'circle','x'=>12,'y'=>82,'width'=>2.5,'height'=>1.8,'color'=>'#34D399','opacity'=>85,'border_radius'=>999,'border_color'=>null,'border_width'=>0],
        ],
        'text_items' => [
            ['id'=>'badge','role'=>'badge','content'=>'2026','x'=>50,'y'=>22,'font_family'=>'Bebas Neue','font_size'=>28,'font_weight'=>'400','color'=>'#FCD34D','text_transform'=>'uppercase','letter_spacing'=>6.0,'alignment'=>'center','editable'=>true],
            ['id'=>'headline','role'=>'headline','content'=>'Happy New Year','x'=>50,'y'=>48,'font_family'=>'Playfair Display','font_size'=>52,'font_weight'=>'500','color'=>'#FFFFFF','alignment'=>'center','editable'=>true],
            ['id'=>'subheading','role'=>'subheading','content'=>'Thank you for being part of our year.','x'=>50,'y'=>68,'font_family'=>'Inter','font_size'=>16,'font_weight'=>'400','color'=>'rgba(255,255,255,0.75)','alignment'=>'center','editable'=>true],
            ['id'=>'cta','role'=>'cta','content'=>'— CHEERS TO WHAT IS NEXT —','x'=>50,'y'=>82,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'500','color'=>'#FCD34D','text_transform'=>'uppercase','letter_spacing'=>3.0,'alignment'=>'center','editable'=>true],
            ['id'=>'brand','role'=>'brand','content'=>'{{business_name}}','x'=>50,'y'=>94,'font_family'=>'Inter','font_size'=>11,'font_weight'=>'500','color'=>'rgba(255,255,255,0.5)','text_transform'=>'uppercase','letter_spacing'=>2.5,'alignment'=>'center','editable'=>false],
        ],
    ]),
];

// ── Insert ─────────────────────────────────────────────────────────
DB::table('studio_templates')->delete(); // clean slate for MVP seed

$now = now();
$rowsInserted = 0;
foreach ($templates as $t) {
    $layers = $t['_layers'];
    DB::table('studio_templates')->insert([
        'name' => $t['name'],
        'category' => $t['category'],
        'sub_category' => $t['sub_category'] ?? null,
        'industry_tags' => json_encode($t['industry_tags'] ?? []),
        'demographic' => $t['demographic'] ?? null,
        'format' => $t['format'],
        'canvas_width'  => $layers['canvas_width'],
        'canvas_height' => $layers['canvas_height'],
        'layers_json'   => json_encode($layers),
        'is_active' => true,
        'use_count' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $rowsInserted++;
}

echo "inserted=$rowsInserted\n";
$byCat = DB::table('studio_templates')->select('category')->selectRaw('count(*) c')->groupBy('category')->get();
foreach ($byCat as $r) echo "  {$r->category}: {$r->c}\n";
