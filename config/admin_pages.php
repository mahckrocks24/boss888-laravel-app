<?php

/*
|--------------------------------------------------------------------------
| Admin page registry
|--------------------------------------------------------------------------
|
| THE single source of truth for the admin's navigation. The sidebar, the
| page titles and the routes are all generated from this file.
|
| Before this existed the same information was maintained by hand in three
| places inside resources/views/admin/app.blade.php - the sidebar markup, an
| inline title map, and the `pages` object. They drifted, and the 2026-07-29
| forensic audit found the results: a House Account menu item with no page
| behind it (clicking it did nothing, silently), and a title entry for a page
| that no longer existed. Keeping one list makes that class of defect
| structurally impossible.
|
| Keyed by the ORIGINAL page key so existing nav('key') call sites keep
| working unchanged.
|
|   slug   URL under /admin/ - also the view path under admin/pages/
|   group  sidebar heading; a null group continues the previous one
|   label  sidebar text
|   title  page heading
|   icon   HTML entity used in the sidebar
|   indent true for the Engineering hub's children
|
*/

return [

    // ── Overview ────────────────────────────────────────────────
    'dashboard' => [
        'slug'   => 'dashboard',
        'group'  => 'Overview',
        'label'  => 'Dashboard',
        'title'  => 'Dashboard',
        'icon'   => '&#9673;',
    ],

    // ── Users & Access ──────────────────────────────────────────
    'users' => [
        'slug'   => 'users',
        'group'  => 'Users & Access',
        'label'  => 'Users',
        'title'  => 'Users',
        'icon'   => '&#128101;',
    ],

    'workspaces' => [
        'slug'   => 'workspaces',
        'group'  => 'Users & Access',
        'label'  => 'Workspaces',
        'title'  => 'Workspaces',
        'icon'   => '&#127970;',
    ],

    'memberships' => [
        'slug'   => 'memberships',
        'group'  => 'Users & Access',
        'label'  => 'Memberships',
        'title'  => 'Memberships',
        'icon'   => '&#128101;',
    ],

    'sessions' => [
        'slug'   => 'sessions',
        'group'  => 'Users & Access',
        'label'  => 'Sessions',
        'title'  => 'Sessions',
        'icon'   => '&#128273;',
    ],

    // ── Billing ─────────────────────────────────────────────────
    'plans' => [
        'slug'   => 'plans',
        'group'  => 'Billing',
        'label'  => 'Plans',
        'title'  => 'Plans',
        'icon'   => '&#128179;',
    ],

    'subscriptions' => [
        'slug'   => 'subscriptions',
        'group'  => 'Billing',
        'label'  => 'Subscriptions',
        'title'  => 'Subscriptions',
        'icon'   => '&#128179;',
    ],

    'credits' => [
        'slug'   => 'credits',
        'group'  => 'Billing',
        'label'  => 'Credits',
        'title'  => 'Credits & Transactions',
        'icon'   => '&#128176;',
    ],

    // ── Agents & Tasks ──────────────────────────────────────────
    'agents' => [
        'slug'   => 'agents',
        'group'  => 'Agents & Tasks',
        'label'  => 'Agents',
        'title'  => 'Agents',
        'icon'   => '&#129302;',
    ],

    'webActivity' => [
        'slug'   => 'web-activity',
        'group'  => 'Agents & Tasks',
        'label'  => 'Agent Web Activity',
        'title'  => 'Agent Web Activity',
        'icon'   => '&#127760;',
    ],

    'tasks' => [
        'slug'   => 'tasks',
        'group'  => 'Agents & Tasks',
        'label'  => 'Task Monitor',
        'title'  => 'Task Monitor',
        'icon'   => '&#9889;',
    ],

    'orchestration' => [
        'slug'   => 'orchestration',
        'group'  => 'Agents & Tasks',
        'label'  => 'Orchestration',
        'title'  => 'Orchestration Health',
        'icon'   => '&#128279;',
    ],

    // ── Engineering ─────────────────────────────────────────────
    'engineering' => [
        'slug'   => 'engineering',
        'group'  => 'Platform Engineering',
        'label'  => 'Engineering Ops',
        'title'  => 'Engineering Operations',
        'icon'   => '&#128295;',
    ],

    'engOverview' => [
        'slug'   => 'engineering/overview',
        'group'  => 'Platform Engineering',
        'label'  => 'Overview',
        'title'  => 'Engineering — Overview',
        'icon'   => '&#9673;',
        'indent' => true,
    ],

    'engHealth' => [
        'slug'   => 'engineering/health',
        'group'  => 'Platform Engineering',
        'label'  => 'Health',
        'title'  => 'Engineering — Health',
        'icon'   => '&#128137;',
        'indent' => true,
    ],

    'engOwnership' => [
        'slug'   => 'engineering/ownership',
        'group'  => 'Platform Engineering',
        'label'  => 'Ownership',
        'title'  => 'Engineering — Source & Route Ownership',
        'icon'   => '&#128273;',
        'indent' => true,
    ],

    'engBackups' => [
        'slug'   => 'engineering/backups',
        'group'  => 'Platform Engineering',
        'label'  => 'Backups',
        'title'  => 'Engineering — Backups',
        'icon'   => '&#128190;',
        'indent' => true,
    ],

    'engAudit' => [
        'slug'   => 'engineering/audit',
        'group'  => 'Platform Engineering',
        'label'  => 'Audit Trail',
        'title'  => 'Engineering — Audit Trail',
        'icon'   => '&#128220;',
        'indent' => true,
    ],

    'engLogs' => [
        'slug'   => 'engineering/logs',
        'group'  => 'Platform Engineering',
        'label'  => 'Application Log',
        'title'  => 'Engineering — Application Log',
        'icon'   => '&#128203;',
        'indent' => true,
    ],

    'engTasks' => [
        'slug'   => 'engineering/tasks',
        'group'  => 'Platform Engineering',
        'label'  => 'Marketing Tasks',
        'title'  => 'Engineering — Marketing Tasks',
        'icon'   => '&#128203;',
        'indent' => true,
    ],

    'engIncidents' => [
        'slug'   => 'engineering/incidents',
        'group'  => 'Platform Engineering',
        'label'  => 'Incidents',
        'title'  => 'Engineering — Incidents',
        'icon'   => '&#128680;',
        'indent' => true,
    ],

    'engCosts' => [
        'slug'   => 'engineering/costs',
        'group'  => 'Platform Engineering',
        'label'  => 'Costs',
        'title'  => 'Engineering — Costs',
        'icon'   => '&#128176;',
        'indent' => true,
    ],

    'engDocs' => [
        'slug'   => 'engineering/docs',
        'group'  => 'Platform Engineering',
        'label'  => 'Documentation',
        'title'  => 'Engineering — Documentation',
        'icon'   => '&#128218;',
        'indent' => true,
    ],

    // ── AI Engines ──────────────────────────────────────────────
    // ── Engineer888 ─────────────────────────────────────────────
    // The engineering DEPARTMENT: tasks, reasoning candidates, exact-content
    // approval, workflow execution and evidence. Distinct from Platform
    // Engineering above, which observes the running platform and changes
    // nothing.
    // ── Engineer888 Chat (2026-08-05) ───────────────────────────
    // Declared FIRST so the group reads Chat / Tasks / Projects.
    //
    // Same capability as the other Engineer888 pages, so AdminRegistry
    // filters it server-side through Engineer888Access. Nothing is hidden
    // in the browser: an unauthorised administrator never receives this
    // slug at all, and /admin/engineer888/chat 404s for them.
    'e888Chat' => [
        'capability' => 'engineer888.discover',
        'slug'   => 'engineer888/chat',
        'group'  => 'Engineer888',
        'label'  => 'Chat',
        'title'  => 'Engineer888 — Chat',
        'icon'   => '&#128172;',
    ],

    'e888Tasks' => [
        // Server-side visibility. AdminRegistry filters on this and
        // AdminAccess routes engineer888.* to Engineer888Access, so the
        // page inherits the module's own policy rather than a copy of it.
        'capability' => 'engineer888.discover',
        'slug'   => 'engineer888',
        'group'  => 'Engineer888',
        'label'  => 'Engineering Tasks',
        'title'  => 'Engineer888 — Command Center',
        'icon'   => '&#129302;',
    ],

    'e888Projects' => [
        // Server-side visibility. AdminRegistry filters on this and
        // AdminAccess routes engineer888.* to Engineer888Access, so the
        // page inherits the module's own policy rather than a copy of it.
        'capability' => 'engineer888.discover',
        'slug'   => 'engineer888/projects',
        'group'  => 'Engineer888',
        'label'  => 'Projects',
        'title'  => 'Engineer888 — Projects',
        'icon'   => '&#128193;',
        'indent' => true,
    ],
    'engines' => [
        'slug'   => 'engines',
        'group'  => 'AI Engines',
        'label'  => 'Engine Registry',
        'title'  => 'Engine Registry',
        'icon'   => '&#129513;',
    ],

    'capabilities' => [
        'slug'   => 'capabilities',
        'group'  => 'AI Engines',
        'label'  => 'Capability Map',
        'title'  => 'Capability Map',
        'icon'   => '&#128506;',
    ],

    // ── Analytics ───────────────────────────────────────────────
    'analytics' => [
        'slug'   => 'analytics',
        'group'  => 'Analytics',
        'label'  => 'Analytics',
        'title'  => 'Platform Analytics',
        'icon'   => '&#128202;',
    ],

    // ── Content & Creative ──────────────────────────────────────
    'hostingAdmin' => [
        'slug'   => 'hosting',
        'group'  => 'Content & Creative',
        'label'  => 'Hosting',
        'title'  => 'Hosting',
        'icon'   => '&#127968;',
    ],

    // ── Email delivery (EMAIL888 EM-5) ──────────────────────────
    // NOT capability-gated like Business Email below: this is the operator's
    // only view of what the platform actually sent. Gating it off would leave
    // diagnosis where it was before EM-5 — in an SSH session.
    'emailDeliveries' => [
        'slug'  => 'email-deliveries',
        'group' => 'Platform',
        'label' => 'Email Delivery',
        'title' => 'Email Delivery Ledger',
        'icon'  => '&#128233;',
    ],

    // ── Webhook ingress (EMAIL888 EM-6) ─────────────────────────
    // Sits beside the ledger deliberately. The ledger says what happened to a
    // message; this says whether the channel that TELLS us what happened is
    // working. Ungated for the same reason as the ledger: if webhook health is
    // only visible over SSH, it is only checked after something has gone wrong.
    'emailWebhooks' => [
        'slug'  => 'email-webhooks',
        'group' => null,
        'label' => 'Webhook Ingress',
        'title' => 'Email Webhook Ingress',
        'icon'  => '&#128225;',
    ],

    // ── Business Email (INFRA888 E3) ────────────────────────────
    // Every entry is capability-gated. While BusinessEmailAdminGate is
    // closed — which it is on every installation today — AdminRegistry
    // filters this whole group out before the sidebar is built, so the
    // browser never receives these slugs at all.

    'businessEmailOverview' => [
        'slug'       => 'business-email/overview',
        'group'      => 'Business Email',
        'label'      => 'Overview',
        'title'      => 'Business Email',
        'icon'       => '&#9993;',
        'capability' => 'business_email.read',
    ],

    'businessEmailDomains' => [
        'slug'       => 'business-email/domains',
        'group'      => null,
        'label'      => 'Domains',
        'title'      => 'Business Email — Domains',
        'icon'       => '&#9993;',
        'capability' => 'business_email.read',
    ],

    'businessEmailMailboxes' => [
        'slug'       => 'business-email/mailboxes',
        'group'      => null,
        'label'      => 'Mailboxes',
        'title'      => 'Business Email — Mailboxes',
        'icon'       => '&#9993;',
        'capability' => 'business_email.read',
    ],

    'businessEmailRouting' => [
        'slug'       => 'business-email/routing',
        'group'      => null,
        'label'      => 'Aliases & Forwarding',
        'title'      => 'Business Email — Aliases & Forwarding',
        'icon'       => '&#9993;',
        'capability' => 'business_email.read',
    ],

    'businessEmailOperations' => [
        'slug'       => 'business-email/operations',
        'group'      => null,
        'label'      => 'Operations',
        'title'      => 'Business Email — Operations',
        'icon'       => '&#9993;',
        'capability' => 'business_email.read',
    ],

    'businessEmailReconciliation' => [
        'slug'       => 'business-email/reconciliation',
        'group'      => null,
        'label'      => 'Reconciliation',
        'title'      => 'Business Email — Reconciliation',
        'icon'       => '&#9993;',
        'capability' => 'business_email.read',
    ],

    'businessEmailProviders' => [
        'slug'       => 'business-email/providers',
        'group'      => null,
        'label'      => 'Providers',
        'title'      => 'Business Email — Providers',
        'icon'       => '&#9993;',
        'capability' => 'business_email.read',
    ],

    'businessEmailHealth' => [
        'slug'       => 'business-email/health',
        'group'      => null,
        'label'      => 'Health',
        'title'      => 'Business Email — Health',
        'icon'       => '&#9993;',
        'capability' => 'business_email.read',
    ],


    'websitesAdmin' => [
        'slug'   => 'websites',
        'group'  => 'Content & Creative',
        'label'  => 'Websites',
        'title'  => 'Websites',
        'icon'   => '&#127760;',
    ],

    'templatesAdmin' => [
        'slug'   => 'templates',
        'group'  => 'Content & Creative',
        'label'  => 'Templates',
        'title'  => 'Template Library',
        'icon'   => '&#128196;',
    ],

    'emailTemplatesAdmin' => [
        'slug'   => 'email-templates',
        'group'  => 'Content & Creative',
        'label'  => 'Email Templates',
        'title'  => 'Email Templates',
        'icon'   => '&#128231;',
    ],

    'campaignsAdmin' => [
        'slug'   => 'campaigns',
        'group'  => 'Content & Creative',
        'label'  => 'Campaigns',
        'title'  => 'Campaigns',
        'icon'   => '&#128640;',
    ],

    'assets' => [
        'slug'   => 'media',
        'group'  => 'Content & Creative',
        'label'  => 'Media Library',
        'title'  => 'Media Library',
        'icon'   => '&#127912;',
    ],

    'articles' => [
        'slug'   => 'articles',
        'group'  => 'Content & Creative',
        'label'  => 'Articles',
        'title'  => 'Articles',
        'icon'   => '&#128221;',
    ],

    // ── Advertising ─────────────────────────────────────────────
    'adsStatus' => [
        'slug'   => 'ads/status',
        'group'  => 'Advertising',
        'label'  => 'Ads Status',
        'title'  => 'Advertising — Status',
        'icon'   => '&#128226;',
    ],

    'adsDashboard' => [
        'slug'   => 'ads/dashboard',
        'group'  => 'Advertising',
        'label'  => 'Ads Delivery',
        'title'  => 'Advertising — Delivery',
        'icon'   => '&#128200;',
    ],

    'adsInventory' => [
        'slug'   => 'ads/inventory',
        'group'  => 'Advertising',
        'label'  => 'Ads Inventory',
        'title'  => 'Advertising — Inventory',
        'icon'   => '&#127760;',
    ],

    'adsCampaigns' => [
        'slug'   => 'ads/campaigns',
        'group'  => 'Advertising',
        'label'  => 'Ads Campaigns',
        'title'  => 'Advertising — Campaigns',
        'icon'   => '&#128188;',
    ],

    'adsCreatives' => [
        'slug'   => 'ads/creatives',
        'group'  => 'Advertising',
        'label'  => 'Ads Creatives',
        'title'  => 'Advertising — Creative Review',
        'icon'   => '&#127917;',
    ],

    'adsSettings' => [
        'slug'   => 'ads/settings',
        'group'  => 'Advertising',
        'label'  => 'Ads Settings',
        'title'  => 'Advertising — Settings',
        'icon'   => '&#9881;',
    ],

    // ── Business Data ───────────────────────────────────────────
    'crmAdmin' => [
        'slug'   => 'crm',
        'group'  => 'Business Data',
        'label'  => 'CRM Overview',
        'title'  => 'CRM Overview',
        'icon'   => '&#128100;',
    ],

    'seoAdmin' => [
        'slug'   => 'seo',
        'group'  => 'Business Data',
        'label'  => 'SEO Overview',
        'title'  => 'SEO Overview',
        'icon'   => '&#128269;',
    ],

    'revenue' => [
        'slug'   => 'revenue',
        'group'  => 'Business Data',
        'label'  => 'Revenue',
        'title'  => 'Revenue',
        'icon'   => '&#128176;',
    ],

    // ── Intelligence ────────────────────────────────────────────
    'meetingsAdmin' => [
        'slug'   => 'meetings',
        'group'  => 'Intelligence',
        'label'  => 'Meetings',
        'title'  => 'Meetings',
        'icon'   => '&#127963;',
    ],

    'proposals' => [
        'slug'   => 'proposals',
        'group'  => 'Intelligence',
        'label'  => 'Proposals',
        'title'  => 'Strategy Proposals',
        'icon'   => '&#128161;',
    ],

    'knowledge' => [
        'slug'   => 'knowledge',
        'group'  => 'Intelligence',
        'label'  => 'Knowledge',
        'title'  => 'Global Knowledge',
        'icon'   => '&#129504;',
    ],

    'memory' => [
        'slug'   => 'memory',
        'group'  => 'Intelligence',
        'label'  => 'Memory',
        'title'  => 'Workspace Memory',
        'icon'   => '&#128190;',
    ],

    'experimentsAdmin' => [
        'slug'   => 'experiments',
        'group'  => 'Intelligence',
        'label'  => 'Experiments',
        'title'  => 'Experiments',
        'icon'   => '&#129514;',
    ],

    'notificationsAdmin' => [
        'slug'   => 'notifications',
        'group'  => 'Intelligence',
        'label'  => 'Notifications',
        'title'  => 'Notifications',
        'icon'   => '&#128276;',
    ],

    // ── System ──────────────────────────────────────────────────
    'apiUsage' => [
        'slug'   => 'api-usage',
        'group'  => 'System',
        'label'  => 'API Usage',
        'title'  => 'API Usage & Costs',
        'icon'   => '&#128176;',
    ],

    'houseAccount' => [
        'slug'   => 'house-account',
        'group'  => 'System',
        'label'  => 'House Account',
        'title'  => 'House Account',
        'icon'   => '&#127968;',
    ],

    'health' => [
        'slug'   => 'health',
        'group'  => 'System',
        'label'  => 'System Health',
        'title'  => 'System Health',
        'icon'   => '&#128154;',
    ],

    'queue' => [
        'slug'   => 'queue',
        'group'  => 'System',
        'label'  => 'Queue',
        'title'  => 'Queue Monitor',
        'icon'   => '&#128230;',
    ],

    'audit' => [
        'slug'   => 'audit',
        'group'  => 'System',
        'label'  => 'Audit Logs',
        'title'  => 'Audit Logs',
        'icon'   => '&#128203;',
    ],

    'settings' => [
        'slug'   => 'settings',
        'group'  => 'System',
        'label'  => 'Settings',
        'title'  => 'Settings',
        'icon'   => '&#9881;&#65039;',
    ],

];
