<?php

return [
    // ARCH-1 (2026-08-31): the Owner's architecture is one workspace per owner — a website is NOT its own
    // environment, and the Websites page lists every site the owner has. Leave this false.
    'website_workspaces' => env('BUILDER_WEBSITE_WORKSPACES', false),
];
