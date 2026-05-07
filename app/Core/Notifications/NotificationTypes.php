<?php

namespace App\Core\Notifications;

class NotificationTypes
{
    // Leads
    public const LEAD_CONTACT_FORM      = 'lead.contact_form';
    public const LEAD_CHATBOT_CAPTURE   = 'lead.chatbot_capture';
    public const LEAD_DUPLICATE_FLAGGED = 'lead.duplicate_flagged';

    // Agent Activity
    public const AGENT_TASK_COMPLETED         = 'agent.task_completed';
    public const AGENT_TASK_FAILED            = 'agent.task_failed';
    public const AGENT_TASK_REQUIRES_APPROVAL = 'agent.task_requires_approval';
    public const AGENT_STRATEGY_READY         = 'agent.strategy_ready';
    public const AGENT_MEETING_SCHEDULED      = 'agent.meeting_scheduled';
    public const AGENT_PROACTIVE_TRIGGERED    = 'agent.proactive_triggered';

    // Builder / Website
    public const BUILDER_SITE_PUBLISHED   = 'builder.site_published';
    public const BUILDER_SITE_ERROR       = 'builder.site_error';
    public const BUILDER_CHATBOT_ENABLED  = 'builder.chatbot_enabled';
    public const BUILDER_CHATBOT_DISABLED = 'builder.chatbot_disabled';

    // SEO
    public const SEO_AUDIT_COMPLETE      = 'seo.audit_complete';
    public const SEO_KEYWORD_OPPORTUNITY = 'seo.keyword_opportunity';
    public const SEO_RANKING_CHANGE      = 'seo.ranking_change';

    // Billing
    public const BILLING_SUBSCRIPTION_CREATED   = 'billing.subscription_created';
    public const BILLING_SUBSCRIPTION_CANCELLED = 'billing.subscription_cancelled';
    public const BILLING_SUBSCRIPTION_RENEWED   = 'billing.subscription_renewed';
    public const BILLING_PAYMENT_FAILED         = 'billing.payment_failed';
    public const BILLING_PAYMENT_RETRY          = 'billing.payment_retry';
    public const BILLING_CHATBOT_ADDON_ADDED    = 'billing.chatbot_addon_added';
    public const BILLING_CHATBOT_ADDON_REMOVED  = 'billing.chatbot_addon_removed';
    public const BILLING_TRIAL_STARTED          = 'billing.trial_started';
    public const BILLING_TRIAL_ENDING           = 'billing.trial_ending';
    public const BILLING_TRIAL_EXPIRED          = 'billing.trial_expired';

    // System
    public const SYSTEM_WORKSPACE_CREATED = 'system.workspace_created';
    public const SYSTEM_USER_SIGNUP       = 'system.user_signup';
    public const SYSTEM_USER_LOGIN        = 'system.user_login';
    public const SYSTEM_PASSWORD_CHANGED  = 'system.password_changed';
    public const SYSTEM_API_ERROR         = 'system.api_error';
    public const SYSTEM_RUNTIME_DOWN      = 'system.runtime_down';
    public const SYSTEM_STORAGE_WARNING   = 'system.storage_warning';
    public const SYSTEM_BACKUP_FAILED     = 'system.backup_failed';
    public const SYSTEM_ADMIN_BROADCAST   = 'system.admin_broadcast';

    // Errors
    public const ERROR_ENGINE_FAILURE   = 'error.engine_failure';
    public const ERROR_CREDIT_EXHAUSTED = 'error.credit_exhausted';
    public const ERROR_RATE_LIMIT_HIT   = 'error.rate_limit_hit';
    public const ERROR_WEBHOOK_FAILED   = 'error.webhook_failed';

    // Onboarding
    public const ONBOARDING_STEP_COMPLETED = 'onboarding.step_completed';
    public const ONBOARDING_COMPLETED      = 'onboarding.completed';
    public const ONBOARDING_STALLED        = 'onboarding.stalled';

    /**
     * Category derived from type prefix. Matches the ENUM list in
     * notifications.severity… no wait, in the spec for the (separate)
     * frontend filter — keys: lead, activity, billing, system, error,
     * agent, onboarding. Returns "system" for unknown prefixes.
     */
    public static function category(string $type): string
    {
        $prefix = explode('.', $type)[0] ?? '';
        return match ($prefix) {
            'lead'       => 'lead',
            'agent'      => 'activity',
            'builder'    => 'activity',
            'seo'        => 'activity',
            'billing'    => 'billing',
            'system'     => 'system',
            'error'      => 'error',
            'onboarding' => 'onboarding',
            default      => 'system',
        };
    }

    /**
     * Types that must email the user regardless of their preferences
     * (security, billing-critical, system outages).
     */
    public static function emailRequired(string $type): bool
    {
        return in_array($type, [
            self::BILLING_PAYMENT_FAILED,
            self::BILLING_SUBSCRIPTION_CANCELLED,
            self::BILLING_TRIAL_EXPIRED,
            self::SYSTEM_PASSWORD_CHANGED,
            self::SYSTEM_RUNTIME_DOWN,
            self::ERROR_ENGINE_FAILURE,
        ], true);
    }
}
