<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $plans = [
            [
                'slug'           => 'wp_bundle',
                'name'           => 'WP SEO Bundle',
                'price'          => 69.00,
                'billing_period' => 'monthly',
                'credit_limit'   => 150,
                'ai_access'      => 'full_seo_and_content',
                'agent_count'    => 0,
                'max_websites'   => 1,
                'is_public'      => 1,
                'features_json'  => json_encode([
                    'seo' => true, 'chatbot' => true, 'crm' => true, 'calendar' => true,
                    'article_writer' => true, 'content_pipeline' => true, 'ai_assistant' => true,
                    'builder' => false, 'social' => false, 'agents' => false,
                    'email_campaigns' => true, 'funnel' => 'wordpress', 'trial' => false,
                ]),
            ],
            [
                'slug'           => 'wp_growth',
                'name'           => 'WP Growth',
                'price'          => 99.00,
                'billing_period' => 'monthly',
                'credit_limit'   => 300,
                'ai_access'      => 'full',
                'agent_count'    => 2,
                'max_websites'   => 3,
                'is_public'      => 1,
                'features_json'  => json_encode([
                    'seo' => true, 'chatbot' => true, 'crm' => true, 'calendar' => true,
                    'article_writer' => true, 'content_pipeline' => true, 'ai_assistant' => true,
                    'builder' => true, 'social' => true, 'agents' => true,
                    'email_campaigns' => true, 'funnel' => 'wordpress', 'trial' => false,
                ]),
            ],
            [
                'slug'           => 'wp_pro',
                'name'           => 'WP Pro',
                'price'          => 199.00,
                'billing_period' => 'monthly',
                'credit_limit'   => 900,
                'ai_access'      => 'full',
                'agent_count'    => 5,
                'max_websites'   => 10,
                'is_public'      => 1,
                'features_json'  => json_encode([
                    'seo' => true, 'chatbot' => true, 'crm' => true, 'calendar' => true,
                    'article_writer' => true, 'content_pipeline' => true, 'ai_assistant' => true,
                    'builder' => true, 'social' => true, 'agents' => true,
                    'email_campaigns' => true, 'app888' => true,
                    'funnel' => 'wordpress', 'trial' => false,
                ]),
            ],
            [
                'slug'           => 'wp_agency',
                'name'           => 'WP Agency',
                'price'          => 399.00,
                'billing_period' => 'monthly',
                'credit_limit'   => 2500,
                'ai_access'      => 'full',
                'agent_count'    => 10,
                'max_websites'   => 999,
                'is_public'      => 1,
                'features_json'  => json_encode([
                    'seo' => true, 'chatbot' => true, 'crm' => true, 'calendar' => true,
                    'article_writer' => true, 'content_pipeline' => true, 'ai_assistant' => true,
                    'builder' => true, 'social' => true, 'agents' => true,
                    'email_campaigns' => true, 'app888' => true, 'white_label' => true,
                    'funnel' => 'wordpress', 'trial' => false,
                ]),
            ],
        ];

        foreach ($plans as $p) {
            $exists = DB::table('plans')->where('slug', $p['slug'])->exists();
            if (!$exists) {
                $p['media_library_limit'] = $p['credit_limit'] * 10;
                $p['includes_dmm']        = 0;
                $p['agent_level']         = null;
                $p['agent_addon_price']   = null;
                $p['max_team_members']    = $p['agent_count'] + 1;
                $p['companion_app']       = isset(json_decode($p['features_json'], true)['app888']) ? 1 : 0;
                $p['white_label']         = isset(json_decode($p['features_json'], true)['white_label']) ? 1 : 0;
                $p['priority_processing'] = 0;
                $p['created_at']          = now();
                $p['updated_at']          = now();
                DB::table('plans')->insert($p);
            }
        }
    }

    public function down(): void
    {
        DB::table('plans')->whereIn('slug', ['wp_bundle', 'wp_growth', 'wp_pro', 'wp_agency'])->delete();
    }
};
