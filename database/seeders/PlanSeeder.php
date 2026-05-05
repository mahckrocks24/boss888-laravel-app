<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Plan;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free', 'slug' => 'free',
                'price' => 0, 'billing_period' => 'monthly',
                'credit_limit' => 0,
                'ai_access' => 'none', 'includes_dmm' => false,
                'agent_count' => 0, 'agent_level' => null, 'agent_addon_price' => null,
                'max_websites' => 1, 'max_team_members' => 1,
                'companion_app' => false, 'white_label' => false, 'priority_processing' => false,
                'features_json' => [
                    'website_builder' => true, 'custom_domain' => false,
                    'crm' => true, 'calendar' => true,
                    'marketing' => false, 'social' => false, 'automation' => false,
                    'ai_assistant' => false, 'ai_agents' => false,
                    'content_writing' => false, 'image_generation' => false, 'video_generation' => false,
                ],
            ],
            [
                'name' => 'Starter', 'slug' => 'starter',
                'price' => 19.00, 'billing_period' => 'monthly',
                'credit_limit' => 0,
                'ai_access' => 'none', 'includes_dmm' => false,
                'agent_count' => 0, 'agent_level' => null, 'agent_addon_price' => null,
                'max_websites' => 1, 'max_team_members' => 3,
                'companion_app' => false, 'white_label' => false, 'priority_processing' => false,
                'features_json' => [
                    'website_builder' => true, 'custom_domain' => true,
                    'crm' => true, 'calendar' => true,
                    'marketing' => true, 'social' => true, 'automation' => true,
                    'ai_assistant' => false, 'ai_agents' => false,
                    'content_writing' => false, 'image_generation' => false, 'video_generation' => false,
                ],
            ],
            [
                'name' => 'AI Lite', 'slug' => 'ai-lite',
                'price' => 49.00, 'billing_period' => 'monthly',
                'credit_limit' => 50,
                'ai_access' => 'research', 'includes_dmm' => false,
                'agent_count' => 0, 'agent_level' => null, 'agent_addon_price' => null,
                'max_websites' => 1, 'max_team_members' => 3,
                'companion_app' => false, 'white_label' => false, 'priority_processing' => false,
                'features_json' => [
                    'website_builder' => true, 'custom_domain' => true,
                    'crm' => true, 'calendar' => true,
                    'marketing' => true, 'social' => true, 'automation' => true,
                    'ai_assistant' => true, 'ai_agents' => false,
                    'content_writing' => false, 'image_generation' => false, 'video_generation' => false,
                ],
            ],
            [
                'name' => 'Growth', 'slug' => 'growth',
                'price' => 99.00, 'billing_period' => 'monthly',
                'credit_limit' => 300,
                'ai_access' => 'full', 'includes_dmm' => true,
                'agent_count' => 2, 'agent_level' => 'specialist', 'agent_addon_price' => 20.00,
                'max_websites' => 3, 'max_team_members' => 5,
                'companion_app' => false, 'white_label' => false, 'priority_processing' => false,
                'features_json' => [
                    'website_builder' => true, 'custom_domain' => true,
                    'crm' => true, 'calendar' => true,
                    'marketing' => true, 'social' => true, 'automation' => true,
                    'ai_assistant' => true, 'ai_agents' => true,
                    'content_writing' => true, 'image_generation' => true, 'video_generation' => false,
                ],
            ],
            [
                'name' => 'Pro', 'slug' => 'pro',
                'price' => 199.00, 'billing_period' => 'monthly',
                'credit_limit' => 900,
                'ai_access' => 'full', 'includes_dmm' => true,
                'agent_count' => 5, 'agent_level' => 'junior', 'agent_addon_price' => 20.00,
                'max_websites' => 10, 'max_team_members' => 10,
                'companion_app' => true, 'white_label' => false, 'priority_processing' => true,
                'features_json' => [
                    'website_builder' => true, 'custom_domain' => true,
                    'crm' => true, 'calendar' => true,
                    'marketing' => true, 'social' => true, 'automation' => true,
                    'ai_assistant' => true, 'ai_agents' => true,
                    'content_writing' => true, 'image_generation' => true, 'video_generation' => true,
                ],
            ],
            [
                'name' => 'Agency', 'slug' => 'agency',
                'price' => 399.00, 'billing_period' => 'monthly',
                'credit_limit' => 2500,
                'ai_access' => 'full', 'includes_dmm' => true,
                'agent_count' => 10, 'agent_level' => 'senior', 'agent_addon_price' => 10.00,
                'max_websites' => 50, 'max_team_members' => 999,
                'companion_app' => true, 'white_label' => true, 'priority_processing' => true,
                'features_json' => [
                    'website_builder' => true, 'custom_domain' => true,
                    'crm' => true, 'calendar' => true,
                    'marketing' => true, 'social' => true, 'automation' => true,
                    'ai_assistant' => true, 'ai_agents' => true,
                    'content_writing' => true, 'image_generation' => true, 'video_generation' => true,
                ],
            ],
        ];

        foreach ($plans as $data) {
            Plan::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
