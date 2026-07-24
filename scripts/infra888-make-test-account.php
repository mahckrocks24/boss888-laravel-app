<?php
/**
 * INFRA888 — create/refresh the dedicated STAGING test account.
 *
 * A purpose-built staging-only user so browser verification can use the REAL
 * login flow (directive 1C §2) instead of injecting a token. No real customer
 * account is touched and no existing password is read or changed.
 *
 * Prints the generated password ONCE to stdout for immediate use. It is never
 * written to a file or committed.
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const EMAIL = 'infra888-test@levelupgrowth.io';
const WS_SLUG = 'infra888-test';

$password = 'Inf!' . bin2hex(random_bytes(9));

DB::transaction(function () use ($password) {
    $user = DB::table('users')->where('email', EMAIL)->first();
    if ($user) {
        DB::table('users')->where('id', $user->id)->update([
            'password' => Hash::make($password), 'updated_at' => now(),
        ]);
        $userId = $user->id;
    } else {
        $userId = DB::table('users')->insertGetId([
            'name' => 'INFRA888 Test Owner', 'email' => EMAIL,
            'password' => Hash::make($password),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $ws = DB::table('workspaces')->where('slug', WS_SLUG)->first();
    if (!$ws) {
        $wsId = DB::table('workspaces')->insertGetId([
            'name' => 'INFRA888 Test Workspace', 'slug' => WS_SLUG,
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    } else { $wsId = $ws->id; }

    if (!DB::table('workspace_users')->where('workspace_id',$wsId)->where('user_id',$userId)->exists()) {
        DB::table('workspace_users')->insert([
            'workspace_id'=>$wsId,'user_id'=>$userId,'role'=>'owner',
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    // Entitled plan (pro) so Infrastructure is reachable for the workflow proof.
    $planId = DB::table('plans')->where('slug','pro')->value('id');
    if (!DB::table('subscriptions')->where('workspace_id',$wsId)->exists()) {
        DB::table('subscriptions')->insert([
            'workspace_id'=>$wsId,'plan_id'=>$planId,'status'=>'active',
            'starts_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
    } else {
        DB::table('subscriptions')->where('workspace_id',$wsId)
            ->update(['plan_id'=>$planId,'status'=>'active','updated_at'=>now()]);
    }

    // Mark the workspace onboarded: a brand-new workspace routes the SPA into
    // the onboarding wizard (core.js:5632), not the dashboard.
    DB::table('workspaces')->where('id', $wsId)->update([
        'onboarded' => 1, 'onboarded_at' => now(), 'updated_at' => now(),
    ]);

    if (Illuminate\Support\Facades\Schema::hasTable('credits')
        && !DB::table('credits')->where('workspace_id',$wsId)->exists()) {
        DB::table('credits')->insert([
            'workspace_id'=>$wsId,'balance'=>100,'reserved_balance'=>0,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    echo "user_id={$userId} workspace_id={$wsId}\n";
});

echo "email=" . EMAIL . "\n";
echo "password={$password}\n";
