<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$a = require '/var/www/levelup-staging/bootstrap/app.php';
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$email = $argv[1] ?? '';
$u = App\Models\User::where('email', $email)->first();
$w = $u ? App\Models\Workspace::where('created_by', $u->id)->first() : null;
$facts = ($w && is_array($w->onboarding_data)) ? ($w->onboarding_data['interview']['facts'] ?? []) : [];
echo count($facts);
