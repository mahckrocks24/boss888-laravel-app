<?php
/**
 * Seed 10 system email templates into the email_templates table.
 * Idempotent: skips rows whose (name, is_system=1) already exist.
 */

$root = '/var/www/levelup-staging';
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// workspace_id must be non-null per schema — use 0 to mark as a platform-wide
// system template (system templates surface via orWhere('is_system', true)).
$WS = 1;

$templates = [
    [
        'name'      => 'Welcome Email',
        'category'  => 'onboarding',
        'subject'   => 'Welcome to {{brand_name}}!',
        'body_html' => "<p>Hi {{first_name}},</p><p>Welcome to {{brand_name}} — we're glad you're here.</p><p>You can get started in three quick steps:</p><ol><li>Complete your profile</li><li>Explore the dashboard</li><li>Reach out if you have questions</li></ol><p>Talk soon,<br>The {{brand_name}} Team</p><p style=\"font-size:12px;color:#888\">{{unsubscribe}}</p>",
        'variables' => ['{{first_name}}', '{{brand_name}}', '{{unsubscribe}}'],
    ],
    [
        'name'      => 'Newsletter — Monthly',
        'category'  => 'newsletter',
        'subject'   => '{{month}} Updates from {{brand_name}}',
        'body_html' => "<p>Hi {{first_name}},</p><p>Here's what's new at {{brand_name}} this {{month}}:</p><ul><li>Highlight one</li><li>Highlight two</li><li>Highlight three</li></ul><p><a href=\"{{cta_url}}\">{{cta_label}}</a></p><p>— {{brand_name}}</p><p style=\"font-size:12px;color:#888\">{{unsubscribe}}</p>",
        'variables' => ['{{first_name}}', '{{month}}', '{{brand_name}}', '{{cta_url}}', '{{cta_label}}', '{{unsubscribe}}'],
    ],
    [
        'name'      => 'Promotional Offer',
        'category'  => 'promotional',
        'subject'   => 'Exclusive Offer — {{discount}}% Off',
        'body_html' => "<p>Hi {{first_name}},</p><p>For a limited time, enjoy <strong>{{discount}}% off</strong> {{product_name}} with code <strong>{{promo_code}}</strong>.</p><p>Offer ends {{expiry_date}}.</p><p><a href=\"{{cta_url}}\">Claim your discount</a></p><p style=\"font-size:12px;color:#888\">{{unsubscribe}}</p>",
        'variables' => ['{{first_name}}', '{{discount}}', '{{product_name}}', '{{promo_code}}', '{{expiry_date}}', '{{cta_url}}', '{{unsubscribe}}'],
    ],
    [
        'name'      => 'Product Launch',
        'category'  => 'announcement',
        'subject'   => 'Introducing {{product_name}}',
        'body_html' => "<p>Hi {{first_name}},</p><p>We're excited to introduce <strong>{{product_name}}</strong> — {{short_description}}.</p><p>{{long_description}}</p><p><a href=\"{{cta_url}}\">See it in action</a></p><p>— {{brand_name}}</p><p style=\"font-size:12px;color:#888\">{{unsubscribe}}</p>",
        'variables' => ['{{first_name}}', '{{product_name}}', '{{short_description}}', '{{long_description}}', '{{cta_url}}', '{{brand_name}}', '{{unsubscribe}}'],
    ],
    [
        'name'      => 'Abandoned Cart',
        'category'  => 'ecommerce',
        'subject'   => 'You left something behind...',
        'body_html' => "<p>Hi {{first_name}},</p><p>You left {{item_name}} in your cart. It's still waiting for you.</p><p><strong>{{item_price}}</strong></p><p><a href=\"{{cart_url}}\">Return to your cart</a></p><p>Questions? Reply to this email.</p><p>— {{brand_name}}</p><p style=\"font-size:12px;color:#888\">{{unsubscribe}}</p>",
        'variables' => ['{{first_name}}', '{{item_name}}', '{{item_price}}', '{{cart_url}}', '{{brand_name}}', '{{unsubscribe}}'],
    ],
    [
        'name'      => 'Re-engagement',
        'category'  => 'retention',
        'subject'   => 'We miss you, {{first_name}}',
        'body_html' => "<p>Hi {{first_name}},</p><p>It's been a while. Here's what's new at {{brand_name}} since we last saw you:</p><ul><li>{{update_one}}</li><li>{{update_two}}</li><li>{{update_three}}</li></ul><p><a href=\"{{cta_url}}\">Come back and explore</a></p><p>— {{brand_name}}</p><p style=\"font-size:12px;color:#888\">{{unsubscribe}}</p>",
        'variables' => ['{{first_name}}', '{{brand_name}}', '{{update_one}}', '{{update_two}}', '{{update_three}}', '{{cta_url}}', '{{unsubscribe}}'],
    ],
    [
        'name'      => 'Event Invitation',
        'category'  => 'event',
        'subject'   => "You're invited — {{event_name}}",
        'body_html' => "<p>Hi {{first_name}},</p><p>You're invited to <strong>{{event_name}}</strong>.</p><p><strong>When:</strong> {{event_date}}<br><strong>Where:</strong> {{event_location}}</p><p>{{event_description}}</p><p><a href=\"{{rsvp_url}}\">RSVP now</a></p><p>— {{brand_name}}</p><p style=\"font-size:12px;color:#888\">{{unsubscribe}}</p>",
        'variables' => ['{{first_name}}', '{{event_name}}', '{{event_date}}', '{{event_location}}', '{{event_description}}', '{{rsvp_url}}', '{{brand_name}}', '{{unsubscribe}}'],
    ],
    [
        'name'      => 'Feedback Request',
        'category'  => 'feedback',
        'subject'   => 'How was your experience?',
        'body_html' => "<p>Hi {{first_name}},</p><p>Thanks for choosing {{brand_name}}. We'd love to hear what you thought of {{product_or_service}}.</p><p>It takes under 60 seconds and helps us improve.</p><p><a href=\"{{feedback_url}}\">Share your feedback</a></p><p>— {{brand_name}}</p><p style=\"font-size:12px;color:#888\">{{unsubscribe}}</p>",
        'variables' => ['{{first_name}}', '{{brand_name}}', '{{product_or_service}}', '{{feedback_url}}', '{{unsubscribe}}'],
    ],
    [
        'name'      => 'Order Confirmation',
        'category'  => 'transactional',
        'subject'   => 'Your order #{{order_id}} is confirmed',
        'body_html' => "<p>Hi {{first_name}},</p><p>Thanks for your order. Here are the details:</p><p><strong>Order #:</strong> {{order_id}}<br><strong>Total:</strong> {{order_total}}<br><strong>Estimated delivery:</strong> {{delivery_date}}</p><p><a href=\"{{order_url}}\">View order</a></p><p>— {{brand_name}}</p>",
        'variables' => ['{{first_name}}', '{{order_id}}', '{{order_total}}', '{{delivery_date}}', '{{order_url}}', '{{brand_name}}'],
    ],
    [
        'name'      => 'Appointment Reminder',
        'category'  => 'reminder',
        'subject'   => 'Reminder: {{appointment_type}} on {{appointment_date}}',
        'body_html' => "<p>Hi {{first_name}},</p><p>This is a reminder about your upcoming {{appointment_type}}.</p><p><strong>When:</strong> {{appointment_date}} at {{appointment_time}}<br><strong>Where:</strong> {{appointment_location}}</p><p>Need to reschedule? <a href=\"{{reschedule_url}}\">Click here</a>.</p><p>— {{brand_name}}</p>",
        'variables' => ['{{first_name}}', '{{appointment_type}}', '{{appointment_date}}', '{{appointment_time}}', '{{appointment_location}}', '{{reschedule_url}}', '{{brand_name}}'],
    ],
];

$inserted = 1;
$skipped  = 1;

foreach ($templates as $t) {
    $exists = DB::table('email_templates')
        ->where('name', $t['name'])
        ->where('is_system', 1)
        ->exists();
    if ($exists) { $skipped++; continue; }

    DB::table('email_templates')->insert([
        'workspace_id'    => $WS,
        'name'            => $t['name'],
        'category'        => $t['category'],
        'subject'         => $t['subject'],
        'body_html'       => $t['body_html'],
        'variables_json'  => json_encode($t['variables']),
        'is_system'       => 1,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
    $inserted++;
}

echo "seeded: inserted=$inserted skipped=$skipped\n";
