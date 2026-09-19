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
        // MISSION-018 WS-0 (2026-08-24). Refuses db:wipe / migrate:fresh /
        // migrate:reset / config:cache — each has already caused a production
        // incident here (2026-07-30 wipe; 2026-07-21 config:cache outage,
        // RISK-0054). Registered FIRST so no later boot failure can leave the
        // guard unarmed. Follows the registrar precedent below rather than a
        // new ServiceProvider (bootstrap/providers.php does not exist in this
        // app). Fails CLOSED, no config kill switch — see the class docblock.
        \App\Core\Safety\DestructiveArtisanGuard::register();

        // RISK-0192 (2026-09-19) — who a request belongs to, decided BEFORE route middleware runs (throttle:api is in
        // the api group; JwtAuthMiddleware sets the user later, so $request->user() is always null here and the old
        // closure keyed everyone by IP — the Cloudflare edge's, until bootstrap/app.php trusted the proxies).
        //   authenticated (a bearer that decodes)  → per user,   240/min: the shell's own polling is 46/min on the
        //                                             Sarah view + 19 per boot; three tabs fit, a script does not
        //   API key (embeds, WordPress bridge)       → per key,    240/min
        //   anonymous                                → per real IP, 60/min (unchanged)
        // The bearer is only DECODED (HS256 signature + expiry, no database); an invalid or expired one is anonymous.
        \Illuminate\Support\Facades\RateLimiter::for("api", function (\Illuminate\Http\Request $request) {
            $bearer = $request->bearerToken();
            if (is_string($bearer) && $bearer !== '') {
                try {
                    $sub = app(\App\Core\Auth\RefreshTokenService::class)->decodeAccessToken($bearer)->sub ?? null;
                    if ($sub !== null && $sub !== '') {
                        return \Illuminate\Cache\RateLimiting\Limit::perMinute(240)->by('u:' . $sub);
                    }
                } catch (\Throwable $e) { /* not ours, or expired: anonymous */ }
            }
            $apiKey = (string) $request->header('X-API-KEY', '');
            if ($apiKey !== '') {
                return \Illuminate\Cache\RateLimiting\Limit::perMinute(240)->by('k:' . substr(hash('sha256', $apiKey), 0, 32));
            }
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by('ip:' . $request->ip());
        });

        // RISK-0192 — POST /api/auth/refresh has its own budget, outside the general bucket, so a throttled minute
        // of polling can never make the session renewal itself fail: 20/min per refresh token (one boot = one
        // refresh; a token is single-use, so this is the replay window's own headroom) and 120/min per real IP
        // (an office of twenty customers booting six times a minute). Measured: 5 × F5 in 68 s = 5, 6 tabs = 6.
        \Illuminate\Support\Facades\RateLimiter::for("refresh", function (\Illuminate\Http\Request $request) {
            $token = (string) $request->input('refresh_token', '');
            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by('rip:' . $request->ip()),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(20)->by('rt:' . substr(hash('sha256', $token), 0, 32)),
            ];
        });

        // Register middleware alias
        $router = $this->app['router'];
        $router->aliasMiddleware('auth.jwt', \App\Http\Middleware\JwtAuthMiddleware::class);
        // MISSION-018 WS-2 (2026-08-24): money-path email-verification gate.
        $router->aliasMiddleware('verified.email', \App\Http\Middleware\EnsureEmailVerified::class);

        // INFRA888 - explicit policy registration. Engine-namespaced models are
        // NOT discovered by Laravel's App\Policies naming convention, so without
        // this every infrastructure policy would silently fail open.
        \App\Engines\Infrastructure\Policies\InfrastructurePolicyRegistrar::register();

        // P0-B GOVERNANCE INTEGRATION (2026-07-26). Registers one Gate per
        // capability in PermissionRegistry plus the two model-scoped Policies,
        // following the InfrastructurePolicyRegistrar precedent above rather
        // than introducing a new ServiceProvider (bootstrap/providers.php does
        // not exist in this app; see P0-B-IMPACT-MATRIX.md §1).
        //
        // SHADOW MODE: gates evaluate and record, but always allow. The
        // registrar fails OPEN — any exception is logged and swallowed so a
        // governance defect can never take the platform down.
        // Kill switch: GOVERNANCE_MODE=off
        \App\Core\Governance\GovernanceGateRegistrar::register();

        // INFRA888 - engine-namespaced console command (not auto-discovered).
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Engines\Infrastructure\Console\ReapStuckInfraOperations::class,
                \App\Engines\Infrastructure\Console\SweepCredentialExpiry::class,
                \App\Engines\Infrastructure\Console\RunMonitorChecks::class,
                \App\Engines\Infrastructure\Console\RollupMonitorDaily::class,
            ]);
        }
    }
}
