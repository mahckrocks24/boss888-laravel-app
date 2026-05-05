<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ids = array_slice($argv, 1);
if (empty($ids)) { echo "usage: php redispatch.php <design_id> [<design_id>...]\n"; exit(1); }

foreach ($ids as $id) {
    $design = Illuminate\Support\Facades\DB::table('studio_designs')->where('id', $id)->first();
    if (!$design) { echo "not found: $id\n"; continue; }
    Illuminate\Support\Facades\DB::table('studio_designs')->where('id', $id)->update([
        'export_status' => 'pending',
        'export_progress_pct' => 0,
        'export_error' => null,
        'updated_at' => now(),
    ]);
    \App\Jobs\RenderStudioVideoJob::dispatch((int)$id)->onQueue('tasks');
    echo "dispatched design $id ({$design->name})\n";
}
