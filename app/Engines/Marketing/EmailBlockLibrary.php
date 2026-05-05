<?php

namespace App\Engines\Marketing;

/**
 * Email Block Library
 *
 * Stores the canonical HTML for every block_type the Email Builder supports.
 * Every block returns a <tr>...</tr> that sits inside the 600px container
 * table. Block content uses {{placeholder}} tokens that the render engine
 * substitutes from block.content_json.
 *
 * Brand tokens every block recognizes:
 *   {{brand_color}}        primary — defaults #5B5BD6
 *   {{brand_color_dark}}   10% darker — used for hover/shadow
 *   {{brand_color_tint}}   10% tint  — used for icon pill / pull-quote bg
 *
 * Quality baseline: /tmp/email-template-premium.html — all blocks must be:
 *   - Table-based (no divs for layout)
 *   - Outlook-compat (VML roundrects for every CTA button)
 *   - Responsive at 620px (hide-mob, feat-cell, body-pad, hero-pad classes)
 *   - Dark-mode aware (txt-h, txt-b, txt-m, card-w, hr classes)
 */
class EmailBlockLibrary
{
    /** Canonical block type list. Order matches the Email Builder sidebar groups. */
    public const TYPES = [
        'header','hero','features','body_text','testimonial','secondary_cta',
        'image','stats','countdown','product','divider','spacer','footer','custom_html',
    ];

    /** Default content_json per block type — used when creating blank blocks. */
    public static function defaultContent(string $type): array
    {
        return match ($type) {
            'header' => [
                'logo_url'        => '',
                'logo_alt'        => 'Logo',
                'brand_name'      => 'Your Brand',
                'logo_width'      => 140,
                'bg_color'        => '{{brand_color}}',
                'nav_link_1_text' => 'Features',
                'nav_link_1_url'  => '#',
                'nav_link_2_text' => 'Pricing',
                'nav_link_2_url'  => '#',
            ],
            'hero' => [
                'hero_image_url' => '',
                'eyebrow_text'   => "\u{2726} What's New",
                'headline'       => 'Headline goes here',
                'subheadline'    => 'Subheadline explains the value in one or two sentences.',
                'cta_text'       => 'Get Started',
                'cta_url'        => '#',
                'cta_note'       => 'No credit card required',
            ],
            'features' => [
                'section_label'   => 'Why teams choose us',
                'feature_1_icon'  => "\u{26A1}",
                'feature_1_title' => 'Fast',
                'feature_1_body'  => 'Short benefit statement.',
                'feature_2_icon'  => "\u{1F4CA}",
                'feature_2_title' => 'Smart',
                'feature_2_body'  => 'Short benefit statement.',
                'feature_3_icon'  => "\u{1F517}",
                'feature_3_title' => 'Connected',
                'feature_3_body'  => 'Short benefit statement.',
            ],
            'body_text' => [
                'body_html'                     => '<p>Your body copy goes here. Keep paragraphs short — 2-3 sentences each.</p>',
                'show_testimonial_pullquote'    => false,
                'testimonial_quote'             => '',
                'testimonial_name'              => '',
                'testimonial_role'              => '',
            ],
            'testimonial' => [
                'quote'            => 'A glowing quote from a happy customer goes here.',
                'name'             => 'Sarah Mitchell',
                'role'             => 'Head of Growth · Acme Corp',
                'avatar_initials'  => 'SM',
                'avatar_bg'        => '{{brand_color}}',
                'show_stars'       => true,
            ],
            'secondary_cta' => [
                'headline' => 'See it live in 15 minutes',
                'body'     => 'Book a personalised walkthrough.',
                'cta_text' => 'Book a Demo',
                'cta_url'  => '#',
                'bg_color' => '#0F172A',
            ],
            'image' => [
                'image_url'     => '',
                'alt_text'      => '',
                'link_url'      => '',
                'caption'       => '',
                'width'         => 'full',   // full|half|third
                'border_radius' => 8,
            ],
            'stats' => [
                'stat_1_value' => '99%',
                'stat_1_label' => 'Uptime',
                'stat_2_value' => '14K',
                'stat_2_label' => 'Users',
                'stat_3_value' => '10×',
                'stat_3_label' => 'Faster',
                'bg_color'     => '#F8FAFC',
            ],
            'countdown' => [
                'end_datetime' => '',
                'label'        => 'Offer ends',
                'bg_color'     => '{{brand_color}}',
                'text_color'   => '#FFFFFF',
            ],
            'product' => [
                'product_image_url'   => '',
                'product_name'        => 'Product Name',
                'product_description' => 'Short product description.',
                'price'               => '$49',
                'cta_text'            => 'Buy Now',
                'cta_url'             => '#',
            ],
            'divider' => [
                'style'          => 'line',       // line | dotted | space
                'color'          => '#E2E8F0',
                'padding_top'    => 0,
                'padding_bottom' => 0,
            ],
            'spacer' => [
                'height' => 24,  // 8|16|24|32|48 px
            ],
            'footer' => [
                'brand_name'            => 'Your Brand',
                'footer_text'           => 'Your company address · City, Country',
                'unsubscribe_url'       => '{{unsubscribe_url}}',
                'preferences_url'       => '#',
                'privacy_url'           => '#',
                'social_x_url'          => '',
                'social_linkedin_url'   => '',
                'social_instagram_url'  => '',
                'current_year'          => '{{current_year}}',
            ],
            'custom_html' => [
                'raw_html' => '<p>Custom HTML</p>',
            ],
            default => [],
        };
    }

    /**
     * Render the HTML for a single block with its content substituted.
     *
     * @param string $type       one of self::TYPES
     * @param array  $content    block.content_json
     * @param int    $blockId    DB id, used for iframe bridge data-attrs
     * @return string            <tr>...</tr> ready to insert in container
     */
    public static function render(string $type, array $content, int $blockId = 0): string
    {
        $content = array_merge(self::defaultContent($type), $content);
        $html    = self::template($type);
        $html    = self::substitute($html, $content);
        // Wrap in a marker tr so the iframe bridge can detect hover/click
        return '<tr data-block-id="' . $blockId . '" data-block-type="' . $type . '"><td>' . $html . '</td></tr>';
    }

    /** Substitute {{keys}} in HTML with content values. Unknown keys left intact. */
    private static function substitute(string $html, array $content): string
    {
        foreach ($content as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $html = str_replace('{{' . $k . '}}', (string) $v, $html);
            }
        }
        return $html;
    }

    /** ────────────────────────────────────────────────────────────────
     * Raw HTML templates — one per block_type.
     * ────────────────────────────────────────────────────────────────*/
    private static function template(string $type): string
    {
        return match ($type) {
            'header'        => self::HEADER,
            'hero'          => self::HERO,
            'features'      => self::FEATURES,
            'body_text'     => self::BODY_TEXT,
            'testimonial'   => self::TESTIMONIAL,
            'secondary_cta' => self::SECONDARY_CTA,
            'image'         => self::IMAGE,
            'stats'         => self::STATS,
            'countdown'     => self::COUNTDOWN,
            'product'       => self::PRODUCT,
            'divider'       => self::DIVIDER,
            'spacer'        => self::SPACER,
            'footer'        => self::FOOTER,
            'custom_html'   => self::CUSTOM_HTML,
            default         => '<p>Unknown block type: ' . htmlspecialchars($type) . '</p>',
        };
    }

    // ═══════════════════════════════════════════════════════════════════
    // BLOCK TEMPLATES — production-grade, table-based, Outlook-compatible
    // ═══════════════════════════════════════════════════════════════════

    private const HEADER = <<<'HTML'
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
       style="border-collapse:collapse;">
  <tr>
    <td align="center" bgcolor="{{brand_color}}"
        style="background-color:{{brand_color}};border-radius:12px 12px 0 0;padding:24px 40px;">
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td align="left" valign="middle">
            <a href="{{nav_link_1_url}}" target="_blank" style="text-decoration:none;">
              <img src="{{logo_url}}" alt="{{logo_alt}}" width="{{logo_width}}" height="32"
                   style="display:block;border:0;height:auto;max-width:{{logo_width}}px;"/>
              <span style="display:block;font-family:'Inter',Arial,sans-serif;font-size:20px;font-weight:700;color:#FFFFFF;text-decoration:none;letter-spacing:-0.3px;">
                {{brand_name}}
              </span>
            </a>
          </td>
          <td align="right" valign="middle" class="hide-mob">
            <a href="{{nav_link_1_url}}" target="_blank"
               style="font-family:'Inter',Arial,sans-serif;font-size:13px;font-weight:500;color:rgba(255,255,255,0.78);text-decoration:none;padding-right:20px;">{{nav_link_1_text}}</a>
            <a href="{{nav_link_2_url}}" target="_blank"
               style="font-family:'Inter',Arial,sans-serif;font-size:13px;font-weight:500;color:rgba(255,255,255,0.78);text-decoration:none;">{{nav_link_2_text}}</a>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
HTML;

    private const HERO = <<<'HTML'
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
  <tr>
    <td style="background-color:#FFFFFF;padding:0;" class="card-w">
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td style="padding:0;font-size:0;line-height:0;">
            <a href="{{cta_url}}" target="_blank" style="display:block;">
              <img src="{{hero_image_url}}" alt="{{headline}}" width="600"
                   style="display:block;width:100%;max-width:600px;height:auto;border:0;"/>
            </a>
          </td>
        </tr>
      </table>
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td align="center" style="padding:48px 48px 40px;" class="hero-pad">
            <p style="margin:0 0 22px 0;">
              <span style="display:inline-block;background-color:{{brand_color_tint}};color:{{brand_color}};font-family:'Inter',Arial,sans-serif;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;padding:6px 16px;border-radius:50px;">
                {{eyebrow_text}}
              </span>
            </p>
            <h1 class="hl txt-h"
                style="margin:0 0 16px 0;font-family:'Inter',Arial,Helvetica,sans-serif;font-size:34px;font-weight:700;line-height:1.22;letter-spacing:-0.5px;color:#0F172A;">
              {{headline}}
            </h1>
            <p class="sub txt-b"
               style="margin:0 0 34px 0;font-family:'Inter',Arial,Helvetica,sans-serif;font-size:17px;font-weight:400;line-height:1.68;color:#475569;">
              {{subheadline}}
            </p>
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center">
              <tr>
                <td align="center" bgcolor="{{brand_color}}"
                    style="background-color:{{brand_color}};border-radius:8px;">
                  <!--[if mso]>
                  <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                               href="{{cta_url}}" style="height:52px;v-text-anchor:middle;width:240px;"
                               arcsize="12%" stroke="f" fillcolor="{{brand_color}}"><w:anchorlock/><center>
                  <![endif]-->
                  <a href="{{cta_url}}" target="_blank" class="cta-btn"
                     style="display:inline-block;background-color:{{brand_color}};color:#FFFFFF;font-family:'Inter',Arial,Helvetica,sans-serif;font-size:15px;font-weight:600;letter-spacing:0.02em;text-decoration:none;padding:16px 36px;border-radius:8px;text-align:center;">
                    {{cta_text}} &rarr;
                  </a>
                  <!--[if mso]></center></v:roundrect><![endif]-->
                </td>
              </tr>
            </table>
            <p style="margin:14px 0 0 0;font-family:'Inter',Arial,sans-serif;font-size:12px;color:#94A3B8;text-align:center;">
              {{cta_note}}
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
HTML;

    private const FEATURES = <<<'HTML'
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
  <tr>
    <td style="background-color:#FFFFFF;padding:40px 32px 36px;" class="card-w sect-pad">
      <p style="margin:0 0 28px 0;font-family:'Inter',Arial,sans-serif;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#94A3B8;text-align:center;">
        {{section_label}}
      </p>
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td class="feat-cell" align="center" valign="top" width="175" style="width:175px;padding:8px 14px;vertical-align:top;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
              <tr><td align="center" style="padding-bottom:14px;">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr>
                  <td align="center" valign="middle" width="52" height="52" bgcolor="{{brand_color_tint}}"
                      style="width:52px;height:52px;background-color:{{brand_color_tint}};border-radius:14px;font-size:26px;line-height:52px;text-align:center;">
                    {{feature_1_icon}}
                  </td>
                </tr></table>
              </td></tr>
              <tr><td align="center">
                <p class="txt-h" style="margin:0 0 8px 0;font-family:'Inter',Arial,sans-serif;font-size:14px;font-weight:600;color:#0F172A;line-height:1.35;text-align:center;">{{feature_1_title}}</p>
                <p class="txt-m" style="margin:0;font-family:'Inter',Arial,sans-serif;font-size:13px;font-weight:400;color:#64748B;line-height:1.62;text-align:center;">{{feature_1_body}}</p>
              </td></tr>
            </table>
          </td>
          <td class="feat-div" width="1" style="width:1px;border-left:1px solid #E2E8F0;">&nbsp;</td>
          <td class="feat-cell" align="center" valign="top" width="175" style="width:175px;padding:8px 14px;vertical-align:top;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
              <tr><td align="center" style="padding-bottom:14px;">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr>
                  <td align="center" valign="middle" width="52" height="52" bgcolor="{{brand_color_tint}}"
                      style="width:52px;height:52px;background-color:{{brand_color_tint}};border-radius:14px;font-size:26px;line-height:52px;text-align:center;">
                    {{feature_2_icon}}
                  </td>
                </tr></table>
              </td></tr>
              <tr><td align="center">
                <p class="txt-h" style="margin:0 0 8px 0;font-family:'Inter',Arial,sans-serif;font-size:14px;font-weight:600;color:#0F172A;line-height:1.35;text-align:center;">{{feature_2_title}}</p>
                <p class="txt-m" style="margin:0;font-family:'Inter',Arial,sans-serif;font-size:13px;font-weight:400;color:#64748B;line-height:1.62;text-align:center;">{{feature_2_body}}</p>
              </td></tr>
            </table>
          </td>
          <td class="feat-div" width="1" style="width:1px;border-left:1px solid #E2E8F0;">&nbsp;</td>
          <td class="feat-cell" align="center" valign="top" width="175" style="width:175px;padding:8px 14px;vertical-align:top;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
              <tr><td align="center" style="padding-bottom:14px;">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr>
                  <td align="center" valign="middle" width="52" height="52" bgcolor="{{brand_color_tint}}"
                      style="width:52px;height:52px;background-color:{{brand_color_tint}};border-radius:14px;font-size:26px;line-height:52px;text-align:center;">
                    {{feature_3_icon}}
                  </td>
                </tr></table>
              </td></tr>
              <tr><td align="center">
                <p class="txt-h" style="margin:0 0 8px 0;font-family:'Inter',Arial,sans-serif;font-size:14px;font-weight:600;color:#0F172A;line-height:1.35;text-align:center;">{{feature_3_title}}</p>
                <p class="txt-m" style="margin:0;font-family:'Inter',Arial,sans-serif;font-size:13px;font-weight:400;color:#64748B;line-height:1.62;text-align:center;">{{feature_3_body}}</p>
              </td></tr>
            </table>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
HTML;

    private const BODY_TEXT = <<<'HTML'
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
  <tr>
    <td style="background-color:#FFFFFF;padding:40px 48px;" class="card-w body-pad">
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr><td class="txt-b" style="font-family:'Inter',Arial,Helvetica,sans-serif;font-size:16px;font-weight:400;line-height:1.75;color:#334155;">
          {{body_html}}
        </td></tr>
      </table>
    </td>
  </tr>
</table>
HTML;

    private const TESTIMONIAL = <<<'HTML'
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
  <tr>
    <td align="center" style="background-color:#F8FAFC;padding:44px 48px;" class="card-l sect-pad">
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr><td align="center">
          <p aria-hidden="true" style="margin:0 0 4px 0;font-family:Georgia,serif;font-size:80px;line-height:1;color:{{brand_color}};opacity:0.18;text-align:center;">&ldquo;</p>
          <p class="txt-b" style="margin:0 auto 28px;max-width:440px;font-family:'Inter',Arial,Helvetica,sans-serif;font-size:17px;font-weight:400;line-height:1.72;color:#334155;font-style:italic;text-align:center;">
            {{quote}}
          </p>
          <table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center">
            <tr>
              <td valign="middle" style="padding-right:12px;">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr>
                  <td width="46" height="46" align="center" valign="middle" bgcolor="{{avatar_bg}}"
                      style="width:46px;height:46px;background-color:{{avatar_bg}};border-radius:50%;font-family:'Inter',Arial,sans-serif;font-size:16px;font-weight:700;color:#FFFFFF;text-align:center;line-height:46px;">
                    {{avatar_initials}}
                  </td>
                </tr></table>
              </td>
              <td valign="middle" align="left">
                <p class="txt-h" style="margin:0;font-family:'Inter',Arial,sans-serif;font-size:14px;font-weight:600;color:#0F172A;line-height:1.3;">{{name}}</p>
                <p class="txt-m" style="margin:3px 0 0 0;font-family:'Inter',Arial,sans-serif;font-size:12px;font-weight:400;color:#64748B;">{{role}}</p>
              </td>
            </tr>
          </table>
          <p aria-label="5 stars" style="margin:18px 0 0 0;font-size:22px;letter-spacing:3px;color:#F59E0B;text-align:center;">&#9733;&#9733;&#9733;&#9733;&#9733;</p>
        </td></tr>
      </table>
    </td>
  </tr>
</table>
HTML;

    private const SECONDARY_CTA = <<<'HTML'
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
  <tr>
    <td align="center" bgcolor="{{bg_color}}" style="background-color:{{bg_color}};padding:44px 48px;" class="sect-pad">
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr><td align="center">
          <table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr>
            <td width="36" height="3" bgcolor="{{brand_color}}" style="width:36px;height:3px;background-color:{{brand_color}};border-radius:2px;font-size:0;line-height:0;">&nbsp;</td>
          </tr></table>
          <h2 style="margin:20px 0 12px 0;font-family:'Inter',Arial,Helvetica,sans-serif;font-size:24px;font-weight:700;line-height:1.26;letter-spacing:-0.3px;color:#FFFFFF;">{{headline}}</h2>
          <p style="margin:0 0 30px 0;font-family:'Inter',Arial,Helvetica,sans-serif;font-size:15px;font-weight:400;line-height:1.65;color:#94A3B8;">{{body}}</p>
          <table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center"><tr>
            <td align="center" style="border-radius:8px;border:1.5px solid rgba(255,255,255,0.30);">
              <!--[if mso]>
              <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                           href="{{cta_url}}" style="height:52px;v-text-anchor:middle;width:220px;"
                           arcsize="12%" stroke="t" strokecolor="#FFFFFF" strokeweight="1.5pt" fillcolor="{{bg_color}}">
                <w:anchorlock/><center>
              <![endif]-->
              <a href="{{cta_url}}" target="_blank" class="ghost"
                 style="display:inline-block;background-color:{{bg_color}};color:#FFFFFF;font-family:'Inter',Arial,Helvetica,sans-serif;font-size:15px;font-weight:600;letter-spacing:0.02em;text-decoration:none;padding:15px 36px;border-radius:8px;border:1.5px solid rgba(255,255,255,0.30);text-align:center;">
                {{cta_text}} &rarr;
              </a>
              <!--[if mso]></center></v:roundrect><![endif]-->
            </td>
          </tr></table>
        </td></tr>
      </table>
    </td>
  </tr>
</table>
HTML;

    private const IMAGE = <<<'HTML'
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
  <tr>
    <td style="background-color:#FFFFFF;padding:20px 48px;" class="card-w">
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr><td align="center" style="font-size:0;line-height:0;">
          <a href="{{link_url}}" target="_blank" style="display:inline-block;text-decoration:none;">
            <img src="{{image_url}}" alt="{{alt_text}}" style="display:block;max-width:100%;height:auto;border:0;border-radius:{{border_radius}}px;"/>
          </a>
        </td></tr>
        <tr><td align="center" style="padding-top:10px;">
          <p class="txt-m" style="margin:0;font-family:'Inter',Arial,sans-serif;font-size:12px;font-weight:400;color:#94A3B8;text-align:center;">{{caption}}</p>
        </td></tr>
      </table>
    </td>
  </tr>
</table>
HTML;

    private const STATS = <<<'HTML'
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
  <tr>
    <td align="center" bgcolor="{{bg_color}}" style="background-color:{{bg_color}};padding:40px 24px;" class="card-l sect-pad">
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td class="feat-cell" align="center" valign="top" width="175" style="width:175px;padding:8px 14px;">
            <p class="txt-h" style="margin:0;font-family:'Inter',Arial,sans-serif;font-size:38px;font-weight:800;color:{{brand_color}};line-height:1.1;text-align:center;letter-spacing:-0.5px;">{{stat_1_value}}</p>
            <p class="txt-m" style="margin:6px 0 0 0;font-family:'Inter',Arial,sans-serif;font-size:12px;font-weight:500;color:#64748B;text-transform:uppercase;letter-spacing:0.08em;text-align:center;">{{stat_1_label}}</p>
          </td>
          <td class="feat-cell" align="center" valign="top" width="175" style="width:175px;padding:8px 14px;">
            <p class="txt-h" style="margin:0;font-family:'Inter',Arial,sans-serif;font-size:38px;font-weight:800;color:{{brand_color}};line-height:1.1;text-align:center;letter-spacing:-0.5px;">{{stat_2_value}}</p>
            <p class="txt-m" style="margin:6px 0 0 0;font-family:'Inter',Arial,sans-serif;font-size:12px;font-weight:500;color:#64748B;text-transform:uppercase;letter-spacing:0.08em;text-align:center;">{{stat_2_label}}</p>
          </td>
          <td class="feat-cell" align="center" valign="top" width="175" style="width:175px;padding:8px 14px;">
            <p class="txt-h" style="margin:0;font-family:'Inter',Arial,sans-serif;font-size:38px;font-weight:800;color:{{brand_color}};line-height:1.1;text-align:center;letter-spacing:-0.5px;">{{stat_3_value}}</p>
            <p class="txt-m" style="margin:6px 0 0 0;font-family:'Inter',Arial,sans-serif;font-size:12px;font-weight:500;color:#64748B;text-transform:uppercase;letter-spacing:0.08em;text-align:center;">{{stat_3_label}}</p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
HTML;

    private const COUNTDOWN = <<<'HTML'
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
  <tr>
    <td align="center" bgcolor="{{bg_color}}" style="background-color:{{bg_color}};padding:28px 48px;" class="sect-pad">
      <p style="margin:0;font-family:'Inter',Arial,sans-serif;font-size:13px;font-weight:600;color:{{text_color}};text-transform:uppercase;letter-spacing:0.1em;opacity:0.75;text-align:center;">{{label}}</p>
      <p style="margin:6px 0 0 0;font-family:'Inter',Arial,sans-serif;font-size:26px;font-weight:700;color:{{text_color}};text-align:center;letter-spacing:-0.3px;">{{end_datetime}}</p>
    </td>
  </tr>
</table>
HTML;

    private const PRODUCT = <<<'HTML'
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
  <tr>
    <td style="background-color:#FFFFFF;padding:32px 48px;" class="card-w body-pad">
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#F8FAFC;border-radius:12px;overflow:hidden;" class="card-l">
        <tr>
          <td align="center" style="padding:0;font-size:0;line-height:0;">
            <img src="{{product_image_url}}" alt="{{product_name}}" width="504" style="display:block;width:100%;max-width:504px;height:auto;border:0;"/>
          </td>
        </tr>
        <tr>
          <td align="left" style="padding:24px 28px;">
            <p class="txt-h" style="margin:0 0 6px 0;font-family:'Inter',Arial,sans-serif;font-size:18px;font-weight:700;color:#0F172A;">{{product_name}}</p>
            <p class="txt-b" style="margin:0 0 16px 0;font-family:'Inter',Arial,sans-serif;font-size:14px;font-weight:400;color:#475569;line-height:1.6;">{{product_description}}</p>
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr>
              <td align="left" valign="middle">
                <p class="txt-h" style="margin:0;font-family:'Inter',Arial,sans-serif;font-size:22px;font-weight:800;color:{{brand_color}};letter-spacing:-0.3px;">{{price}}</p>
              </td>
              <td align="right" valign="middle">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr>
                  <td align="center" bgcolor="{{brand_color}}" style="background-color:{{brand_color}};border-radius:8px;">
                    <!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="{{cta_url}}" style="height:40px;v-text-anchor:middle;width:140px;" arcsize="20%" stroke="f" fillcolor="{{brand_color}}"><w:anchorlock/><center><![endif]-->
                    <a href="{{cta_url}}" target="_blank" style="display:inline-block;background-color:{{brand_color}};color:#FFFFFF;font-family:'Inter',Arial,sans-serif;font-size:13px;font-weight:600;text-decoration:none;padding:10px 22px;border-radius:8px;">
                      {{cta_text}} &rarr;
                    </a>
                    <!--[if mso]></center></v:roundrect><![endif]-->
                  </td>
                </tr></table>
              </td>
            </tr></table>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
HTML;

    private const DIVIDER = <<<'HTML'
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
  <tr>
    <td style="background-color:#FFFFFF;padding:{{padding_top}}px 40px {{padding_bottom}}px;" class="card-w">
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr><td style="border-top:1px solid {{color}};height:0;font-size:0;line-height:0;" class="hr">&nbsp;</td></tr>
      </table>
    </td>
  </tr>
</table>
HTML;

    private const SPACER = <<<'HTML'
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
  <tr>
    <td style="background-color:#FFFFFF;height:{{height}}px;font-size:0;line-height:0;" class="card-w">&nbsp;</td>
  </tr>
</table>
HTML;

    private const FOOTER = <<<'HTML'
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
  <tr>
    <td align="center" style="background-color:#FFFFFF;border-radius:0 0 12px 12px;padding:36px 40px 32px;" class="card-w">
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr><td align="center" style="padding-bottom:18px;">
          <p class="txt-h" style="margin:0;font-family:'Inter',Arial,sans-serif;font-size:15px;font-weight:700;color:#0F172A;">{{brand_name}}</p>
        </td></tr>
        <tr><td style="border-top:1px solid #E2E8F0;height:0;font-size:0;line-height:0;padding-bottom:20px;" class="hr">&nbsp;</td></tr>
        <tr><td align="center" style="padding-bottom:20px;">
          <table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr>
            <td style="padding:0 6px;"><a href="{{social_x_url}}" target="_blank" class="fl" style="display:inline-block;width:36px;height:36px;background-color:#F1F5F9;border-radius:8px;font-family:'Inter',Arial,sans-serif;font-size:14px;font-weight:700;color:#334155;text-align:center;line-height:36px;text-decoration:none;">X</a></td>
            <td style="padding:0 6px;"><a href="{{social_linkedin_url}}" target="_blank" class="fl" style="display:inline-block;width:36px;height:36px;background-color:#F1F5F9;border-radius:8px;font-family:'Inter',Arial,sans-serif;font-size:14px;font-weight:700;color:#0077B5;text-align:center;line-height:36px;text-decoration:none;">in</a></td>
            <td style="padding:0 6px;"><a href="{{social_instagram_url}}" target="_blank" class="fl" style="display:inline-block;width:36px;height:36px;background-color:#F1F5F9;border-radius:8px;font-size:18px;text-align:center;line-height:36px;text-decoration:none;">&#9673;</a></td>
          </tr></table>
        </td></tr>
        <tr><td align="center" style="padding-bottom:14px;">
          <p class="txt-m" style="margin:0;font-family:'Inter',Arial,sans-serif;font-size:12px;font-weight:400;line-height:1.75;color:#94A3B8;text-align:center;">{{footer_text}}</p>
        </td></tr>
        <tr><td align="center" style="padding-bottom:14px;">
          <p style="margin:0;font-family:'Inter',Arial,sans-serif;font-size:12px;color:#94A3B8;text-align:center;line-height:1.75;">
            <a href="{{unsubscribe_url}}" target="_blank" class="fl" style="color:#64748B;text-decoration:underline;">Unsubscribe</a>
            &nbsp;&middot;&nbsp;
            <a href="{{preferences_url}}" target="_blank" class="fl" style="color:#64748B;text-decoration:underline;">Preferences</a>
            &nbsp;&middot;&nbsp;
            <a href="{{privacy_url}}" target="_blank" class="fl" style="color:#64748B;text-decoration:underline;">Privacy</a>
          </p>
        </td></tr>
        <tr><td align="center" style="padding-top:8px;">
          <p class="txt-m" style="margin:0;font-family:'Inter',Arial,sans-serif;font-size:11px;color:#CBD5E1;text-align:center;">&copy; {{current_year}} {{brand_name}}. All rights reserved.</p>
        </td></tr>
      </table>
    </td>
  </tr>
</table>
HTML;

    private const CUSTOM_HTML = <<<'HTML'
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
  <tr><td style="background-color:#FFFFFF;padding:20px 40px;" class="card-w">{{raw_html}}</td></tr>
</table>
HTML;

    // ═══════════════════════════════════════════════════════════════════
    // OUTER SHELL — wraps the concatenated block HTML with full email boilerplate
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Wrap the rendered blocks HTML into a complete email document with <html>,
     * <head> (CSS + VML namespace), preheader, outer wrapper tables, and closing
     * boilerplate. Takes the inner blocks concatenation as $innerHtml.
     *
     * @param string $innerHtml  concatenation of rendered block <tr>s
     * @param array  $meta       [brand_color, preview_text, title]
     */
    public static function wrap(string $innerHtml, array $meta = []): string
    {
        $brand       = $meta['brand_color']  ?? '#5B5BD6';
        $brandTint   = $meta['brand_color_tint']  ?? self::tint($brand);
        $brandDark   = $meta['brand_color_dark']  ?? self::darken($brand);
        $previewText = $meta['preview_text'] ?? '';
        $title       = $meta['title']        ?? '';

        $shell = str_replace(
            ['{{TITLE}}', '{{PREVIEW_TEXT}}', '{{INNER}}'],
            [htmlspecialchars($title), htmlspecialchars($previewText), $innerHtml],
            self::SHELL
        );
        // Apply brand color tokens LAST so they propagate into inner HTML too
        $shell = str_replace('{{brand_color_tint}}', $brandTint, $shell);
        $shell = str_replace('{{brand_color_dark}}', $brandDark, $shell);
        $shell = str_replace('{{brand_color}}',      $brand,     $shell);
        return $shell;
    }

    /** 10% light tint — rough approximation, good enough for email backgrounds */
    private static function tint(string $hex): string
    {
        $rgb = self::hexToRgb($hex);
        if (!$rgb) return '#EEF2FF';
        [$r,$g,$b] = $rgb;
        $mix = fn(int $c) => (int) round($c * 0.10 + 255 * 0.90);
        return sprintf('#%02X%02X%02X', $mix($r), $mix($g), $mix($b));
    }

    /** 15% darker — for hover/shadow states */
    private static function darken(string $hex): string
    {
        $rgb = self::hexToRgb($hex);
        if (!$rgb) return '#4747C2';
        [$r,$g,$b] = $rgb;
        $mul = fn(int $c) => (int) round($c * 0.85);
        return sprintf('#%02X%02X%02X', $mul($r), $mul($g), $mul($b));
    }

    private static function hexToRgb(string $hex): ?array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return null;
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private const SHELL = <<<'HTML'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="en">
<head>
<meta charset="utf-8"/>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<!--[if !mso]><!--><meta http-equiv="X-UA-Compatible" content="IE=edge"/><!--<![endif]-->
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="format-detection" content="telephone=no,address=no,email=no,date=no"/>
<meta name="color-scheme" content="light dark"/>
<meta name="supported-color-schemes" content="light dark"/>
<title>{{TITLE}}</title>
<style type="text/css">
body, table, td, a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
table, td { mso-table-lspace:0pt; mso-table-rspace:0pt; border-collapse:collapse; }
img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; }
body { margin:0!important; padding:0!important; width:100%!important; background-color:#F2F4F8; }
.preheader { display:none!important; visibility:hidden; opacity:0; color:transparent; height:0; width:0; max-height:0; overflow:hidden; mso-hide:all; }
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
@media only screen and (max-width:620px) {
  .container { width:100%!important; }
  .hero-pad  { padding:36px 24px 32px!important; }
  .body-pad  { padding:32px 24px!important; }
  .sect-pad  { padding:32px 24px!important; }
  .feat-cell { display:block!important; width:100%!important; padding:0 0 28px!important; }
  .feat-div  { display:none!important; }
  .hide-mob  { display:none!important; max-height:0!important; overflow:hidden!important; mso-hide:all; }
  h1.hl      { font-size:26px!important; line-height:1.28!important; }
  .sub       { font-size:16px!important; }
}
@media (prefers-color-scheme:dark) {
  body,.wrap { background-color:#111827!important; }
  .card-w    { background-color:#1F2937!important; }
  .card-l    { background-color:#1A2234!important; }
  .card-d    { background-color:#0B1220!important; }
  .txt-h     { color:#F1F5F9!important; }
  .txt-b     { color:#CBD5E1!important; }
  .txt-m     { color:#64748B!important; }
  .hr        { border-color:#374151!important; }
}
.cta-btn:hover { opacity:.88!important; }
.ghost:hover   { background-color:rgba(255,255,255,.10)!important; }
a.fl:hover     { text-decoration:underline!important; }
v\:* { behavior:url(#default#VML); display:inline-block; }
o\:* { behavior:url(#default#VML); }
</style>
<!--[if mso]>
<xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
<style>body,td{font-family:Arial,Helvetica,sans-serif!important;}</style>
<![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#F2F4F8;font-family:'Inter',Arial,Helvetica,sans-serif;" class="wrap">
<div class="preheader" style="display:none;visibility:hidden;opacity:0;color:transparent;height:0;width:0;overflow:hidden;mso-hide:all;">{{PREVIEW_TEXT}}&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;background-color:#F2F4F8;" class="wrap">
<tr><td align="center" style="padding:28px 16px;">
<!--[if mso]><table role="presentation" align="center" border="0" cellpadding="0" cellspacing="0" width="600"><tr><td><![endif]-->
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;" class="container">
{{INNER}}
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>
HTML;
}
