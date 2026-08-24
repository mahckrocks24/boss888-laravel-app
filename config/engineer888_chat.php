<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default project
    |--------------------------------------------------------------------------
    |
    | NULL BY DESIGN. With no value here, a new Engineer888 conversation has no
    | active project and task creation refuses with PROJECT_SELECTION_REQUIRED
    | until somebody chooses one.
    |
    | That is the safe state. Defaulting to "the first registered project" was
    | the defect this replaced: database ordering silently decided which
    | repository received engineering work, and a wrong choice is discovered by
    | finding code in the wrong codebase.
    |
    | Set this ONLY to a key that exists in engineering_projects, and only when
    | a default is genuinely intended — e.g. 'levelup-growth-platform'. A key
    | that does not resolve through the registry yields no project rather than
    | an arbitrary one.
    */
    'default_project_key' => env('E888_CHAT_DEFAULT_PROJECT'),

    /*
    |--------------------------------------------------------------------------
    | Secure action card lifetime (minutes)
    |--------------------------------------------------------------------------
    |
    | Long enough to read a diff properly, short enough that a tab left open
    | overnight cannot approve anything. Expiry is one of several bindings
    | revalidated at press time, never the only one.
    */
    'action_card_ttl_minutes' => (int) env('E888_CHAT_CARD_TTL', 30),

];
