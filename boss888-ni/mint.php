<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$uid = (int) ($argv[1] ?? 2);
$wid = (int) ($argv[2] ?? 2);
$user = App\Models\User::find($uid);
$ws   = App\Models\Workspace::find($wid);
if (!$user) { fwrite(STDERR, "user $uid not found\n"); exit(1); }
$svc = app(App\Core\Auth\RefreshTokenService::class);
echo $svc->issueAccessToken($user, $ws);
