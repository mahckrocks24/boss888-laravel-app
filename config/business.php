<?php

/*
|--------------------------------------------------------------------------
| Business profiles — RFC-0011 (2026-09-22): several businesses in ONE workspace
|--------------------------------------------------------------------------
| `profiles` is the kill-switch. OFF (the default): BusinessProfileResolver::profile() returns the workspace's default
| business, which mirrors workspaces.* — i.e. exactly today's behaviour — and Sarah's BusinessContext is always
| `single`. ON: the resolver honours every business and Sarah resolves which one a message is about.
| `qa_workspaces` turns the feature on for listed workspaces only (U3 proving ground) while `profiles` stays off.
*/

return [
    'profiles' => (bool) env('BUSINESS_PROFILES', false),
    'qa_workspaces' => array_values(array_filter(array_map('intval', explode(',', (string) env('BUSINESS_PROFILES_WORKSPACES', ''))))),
    'sticky_ttl_seconds' => 6 * 3600,
    'memory_prefix' => 'biz:',
];
