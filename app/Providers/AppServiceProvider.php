<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase 1 — Core services auto-resolved via constructor injection.
        // Engine providers registered explicitly.
        $this->app->register(\App\Engines\CRM\EngineServiceProvider::class);

        // Phase 2 — Connector system (singletons for shared state / health cache)
        $this->app->singleton(\App\Connectors\ConnectorResolver::class);
        $this->app->singleton(\App\Connectors\CreativeConnector::class);
        $this->app->singleton(\App\Connectors\EmailConnector::class);
        $this->app->singleton(\App\Connectors\SocialConnector::class);

        // Phase 2 — Parameter resolver
        $this->app->singleton(\App\Services\ParameterResolverService::class);

        // Phase 3 — Reliability services (singletons for shared state)
        $this->app->singleton(\App\Services\IdempotencyService::class);
        $this->app->singleton(\App\Services\ConnectorCircuitBreakerService::class);
        $this->app->singleton(\App\Services\TaskProgressService::class);
        $this->app->singleton(\App\Services\QueueControlService::class);
        $this->app->singleton(\App\Services\ExecutionRateLimiterService::class);

        // Phase 4 — Validation
        $this->app->singleton(\App\Services\ValidationReportService::class);

        // Phase 5 — Performance
        $this->app->singleton(\App\Services\PerformanceCollector::class);

        // Phase 1 Correction — Plan Gating
        $this->app->singleton(\App\Core\PlanGating\PlanGatingService::class);

        // Phase 5 — LLM Integration
        $this->app->singleton(\App\Connectors\DeepSeekConnector::class);
        $this->app->singleton(\App\Core\LLM\InstructionParser::class);
        $this->app->singleton(\App\Core\LLM\AgentReasoningService::class);
        // MultiStepPlanner removed 2026-04-12 (Phase 1.0.0 / doc 07) — was dead code

        // Engine Execution Service — THE central bridge
        $this->app->singleton(\App\Core\EngineKernel\EngineExecutionService::class);

        // Intelligence Layer — the AI OS brain
        $this->app->singleton(\App\Core\Intelligence\GlobalKnowledgeService::class);
        $this->app->singleton(\App\Core\Intelligence\AgentExperienceService::class);
        $this->app->singleton(\App\Core\Intelligence\EngineIntelligenceService::class);
        $this->app->singleton(\App\Core\Intelligence\Validation\IntelligenceValidator::class);
        $this->app->singleton(\App\Core\Intelligence\CampaignOptimizationEngine::class);
        // Intelligence Layer v2 — Sarah's brain (tool selection, cost, feedback)
        $this->app->singleton(\App\Core\Intelligence\ToolCostCalculatorService::class);
        $this->app->singleton(\App\Core\Intelligence\ToolSelectorService::class);
        $this->app->singleton(\App\Core\Intelligence\ToolFeedbackService::class);

        // Orchestration — Sarah DMM controller
        $this->app->singleton(\App\Core\Orchestration\SarahOrchestrator::class);
        $this->app->singleton(\App\Core\Orchestration\SarahStrategicLayer::class);
        $this->app->singleton(\App\Core\Orchestration\AgentMeetingEngine::class);
        $this->app->singleton(\App\Core\Orchestration\ProactiveStrategyEngine::class);

        // D1 — Creative Engine sub-services (CIMS, Blueprint, ScenePlanner, WhiteLabel)
        $this->app->singleton(\App\Engines\Creative\Services\WhiteLabelService::class);
        $this->app->singleton(\App\Engines\Creative\Services\CimsService::class);
        $this->app->singleton(\App\Engines\Creative\Services\BlueprintService::class);
        $this->app->singleton(\App\Engines\Creative\Services\ScenePlannerService::class);
        $this->app->singleton(\App\Engines\Creative\Services\CreativeService::class);

        // D4 — Feature Gating
        $this->app->singleton(\App\Core\Billing\FeatureGateService::class);

        // D5 — Trial System
        $this->app->singleton(\App\Core\Billing\TrialService::class);

        // D6 — Team Management
        $this->app->singleton(\App\Core\Workspaces\TeamService::class);

        // D7 — Admin Panel
        $this->app->singleton(\App\Core\Admin\SettingsService::class);

        // D10 — Stripe
        $this->app->singleton(\App\Core\Billing\StripeService::class);
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for("api", function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Register middleware alias
        $router = $this->app['router'];
        $router->aliasMiddleware('auth.jwt', \App\Http\Middleware\JwtAuthMiddleware::class);
    }
}
