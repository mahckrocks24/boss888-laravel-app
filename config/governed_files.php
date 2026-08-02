<?php

/**
 * Governed shared files — INC-2026-006 coordination hardening, 2026-08-01.
 *
 * A file belongs here when a change to it affects engineers who did not make the
 * change. The list is deliberately short: governance that covers everything is
 * ignored, and an ignored control is worse than none because it looks like
 * protection.
 *
 * Adding an entry costs other engineers nothing until they need to edit the
 * file, at which point it costs them a manifest declaration.
 */
return [

    'files' => [

        'tests/TestCase.php' => [
            'classification'  => 'ANNOUNCE BEFORE EDIT',
            'blast_radius'    => 'HIGH',
            'ownership'       => 'SHARED',
            'affects'         => 'every test in the platform — 126 classes use RefreshDatabase through it',
            'why_governed'    => 'On 2026-07-30 the production database guard in this file was found to run '
                               . 'AFTER RefreshDatabase had already dropped the target. Fixing it changed the '
                               . 'behaviour of every engineer\'s test run with no announcement.',
        ],

        'bootstrap/app.php' => [
            'classification'  => 'ANNOUNCE BEFORE EDIT',
            'blast_radius'    => 'HIGH',
            'ownership'       => 'SHARED',
            'affects'         => 'application boot, middleware stack and all 35 scheduled commands',
            'why_governed'    => 'Every engineer adds scheduler entries here. A broad rewrite would silently '
                               . 'drop another engineer\'s scheduled work.',
        ],

        'phpunit.xml' => [
            'classification'  => 'ANNOUNCE BEFORE EDIT',
            'blast_radius'    => 'HIGH',
            'ownership'       => 'SHARED',
            'affects'         => 'the default test target for any engineer without their own config',
            'why_governed'    => 'It targets levelup_test, which is shared. Changing it redirects everyone.',
        ],

        'composer.json' => [
            'classification'  => 'ANNOUNCE BEFORE EDIT',
            'blast_radius'    => 'HIGH',
            'ownership'       => 'SHARED',
            'affects'         => 'autoloading and dependencies for every engineer',
            'why_governed'    => 'A dependency change forces a composer install on every other session.',
        ],

        '.env' => [
            'classification'  => 'NEVER EDIT WITHOUT EXPLICIT APPROVAL',
            'blast_radius'    => 'CRITICAL',
            'ownership'       => 'OPERATOR',
            'affects'         => 'production credentials and runtime configuration',
            'why_governed'    => 'This file is production configuration. Engineer888 has no authority here.',
        ],

    ],

];
