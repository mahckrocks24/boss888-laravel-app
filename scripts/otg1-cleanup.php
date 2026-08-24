<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$a = require '/var/www/levelup-staging/bootstrap/app.php';
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$u = App\Models\User::where('email', $argv[1] ?? '')->first();
if ($u) {
    $w = App\Models\Workspace::where('created_by', $u->id)->value('id');
    if ($w) {
        foreach (['credit_transactions','credits','subscriptions','workspace_agents','audit_logs','workspace_users','workspace_memory'] as $t) {
            try { DB::table($t)->where('workspace_id', $w)->delete(); } catch (\Throwable $e) {}
        }
        DB::table('workspaces')->where('id', $w)->delete();
    }
    DB::table('users')->where('id', $u->id)->delete();
}
echo 'cleaned';
