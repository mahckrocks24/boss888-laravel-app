<?php
/**
 * Seed all converted JSX-email HTML files into email_templates as system rows.
 * Idempotent — skips a slug whose name already exists with is_system=1.
 */

require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$WS = 1;
$srcDir = '/tmp/jsx-html';
if (!is_dir($srcDir)) { echo "src missing: $srcDir\n"; exit(1); }

$svc = app(\App\Engines\Marketing\Services\EmailBuilderService::class);

// Slug → category mapping based on the email's intent
function categoryFor(string $slug): string {
    $map = [
        // onboarding / lifecycle
        'welcome'             => 'onboarding',
        'goodbye'             => 'retention',
        'team-invite'         => 'onboarding',
        // ecommerce
        'abandoned-cart'      => 'ecommerce',
        'back-in-stock'       => 'ecommerce',
        'wishlist-sale'       => 'ecommerce',
        'summer-drop'         => 'ecommerce',
        'shipping'            => 'transactional',
        'price-drop'          => 'ecommerce',
        // newsletter
        'newsletter'          => 'newsletter',
        'community-digest'    => 'newsletter',
        'team-digest'         => 'newsletter',
        'usage-digest'        => 'newsletter',
        // announcement
        'announcement'        => 'announcement',
        'podcast'             => 'announcement',
        'webinar'             => 'event',
        'collab'              => 'announcement',
        // retention
        'birthday'            => 'retention',
        'loyalty'             => 'retention',
        'referral'            => 'retention',
        'reengagement'        => 'retention',
        'renewal'             => 'retention',
        'membership-renewal'  => 'retention',
        'trial-ending'        => 'retention',
        // promotional
        'bogo'                => 'promotional',
        'flash-sale'          => 'promotional',
        'weekend-promo'       => 'promotional',
        'student-discount'    => 'promotional',
        // transactional
        'receipt'             => 'transactional',
        'invoice'             => 'transactional',
        'payment-failed'      => 'transactional',
        'transactional'       => 'transactional',
        'password-reset'      => 'transactional',
        'data-export'         => 'transactional',
        // reminders
        'appointment'         => 'reminder',
        'appointment-reminder'=> 'reminder',
        'hotel-reservation'   => 'reminder',
        'course-lesson'       => 'reminder',
        'event'               => 'event',
        // feedback
        'feedback-followup'   => 'feedback',
        'review-request'      => 'feedback',
        'survey'              => 'feedback',
        'comment'             => 'feedback',
        // alerts / utility
        'incident'            => 'transactional',
        'job-status'          => 'transactional',
        'seat-limit'          => 'transactional',
        'donation'            => 'announcement',
        'waitlist'            => 'announcement',
    ];
    return $map[$slug] ?? 'general';
}

function nameFor(string $slug): string {
    return ucwords(str_replace('-', ' ', $slug));
}

$inserted = 0; $skipped = 0; $failed = 0;

foreach (glob($srcDir . '/*.html') as $file) {
    $slug = basename($file, '.html');
    $name = nameFor($slug);
    $cat  = categoryFor($slug);
    $html = file_get_contents($file);

    // Idempotent: skip if a system template with this exact name already exists
    $exists = DB::table('email_templates')
        ->where('name', $name)
        ->where('is_system', 1)
        ->exists();
    if ($exists) { echo "  - skip $slug (already seeded)\n"; $skipped++; continue; }

    try {
        $tpl = $svc->createTemplate($WS, [
            'name'        => $name,
            'category'    => $cat,
            'source'      => 'html',
            'html_content'=> $html,
            'subject'     => $name,
        ]);
        $tplId = $tpl['template']['id'];
        // Mark as system so it surfaces under templates for any workspace
        DB::table('email_templates')->where('id', $tplId)->update([
            'is_system'  => 1,
            'is_active'  => 1,
            'updated_at' => now(),
        ]);
        echo "  + $slug → id=$tplId category=$cat\n";
        $inserted++;
    } catch (\Throwable $e) {
        echo "  ! $slug FAILED: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\nDONE inserted=$inserted skipped=$skipped failed=$failed\n";
echo "Total system templates now: " . DB::table('email_templates')->where('is_system', 1)->count() . "\n";
