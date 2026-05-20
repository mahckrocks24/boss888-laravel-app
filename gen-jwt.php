<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// 1. Find admin user
$user = DB::table('users')->where('email', 'admin@levelupgrowth.io')->first();
if (!$user) { echo "no admin user\n"; exit(1); }
echo "admin user id={$user->id} email={$user->email}\n";

// 2. Look up workspace 7 membership
$ws = DB::table('workspace_users')->where('user_id', $user->id)->where('workspace_id', 7)->first();
if (!$ws) { echo "  admin not in ws 7, finding alt workspace\n"; }
echo "membership ws=7 found={$ws->workspace_id}\n";

// 3. Generate a Laravel-style JWT via the existing JwtAuthMiddleware
$secret = config('app.key');
$payload = [
    'iss' => 'laravel',
    'iat' => time(),
    'exp' => time() + 3600,
    'sub' => $user->id,
    'workspace_id' => 7,
    'email' => $user->email,
];

// Manual HS256 JWT
$header = json_encode(['typ'=>'JWT','alg'=>'HS256']);
$b64u   = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$h = $b64u($header);
$p = $b64u(json_encode($payload));
$sig = $b64u(hash_hmac('sha256', "$h.$p", $secret, true));
$jwt = "$h.$p.$sig";
echo "jwt: $jwt\n";

// 4. Also try config('jwt.secret') if used
$jwtSecret = config('jwt.secret') ?? null;
if ($jwtSecret && $jwtSecret !== $secret) {
    echo "alt jwt secret found, generating second jwt\n";
    $sig2 = $b64u(hash_hmac('sha256', "$h.$p", $jwtSecret, true));
    echo "alt jwt: $h.$p.$sig2\n";
}