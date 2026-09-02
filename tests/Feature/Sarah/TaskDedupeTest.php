<?php

namespace Tests\Feature\Sarah;

use App\Core\TaskSystem\TaskService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F-U12-DUPE (2026-09-03): concurrent identical credit-spending requests must
 * dedupe to one task/one charge; genuinely different requests must not.
 */
class TaskDedupeTest extends TestCase
{
    private function freshWs(): int
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'dup-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ws = (int) DB::table('workspaces')->insertGetId([
            'name' => 'Business', 'slug' => 'dup-' . uniqid(), 'created_by' => $uid,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('credits')->insert(['workspace_id' => $ws, 'balance' => 100, 'reserved_balance' => 0,
            'created_at' => now(), 'updated_at' => now()]);
        return $ws;
    }

    public function test_identical_billable_request_dedupes(): void
    {
        $ws = $this->freshWs();
        $d = ['action'=>'serp_analysis','engine'=>'seo','credit_cost'=>1,'source'=>'manual',
              'payload'=>['user_request'=>'Analyse SERP for sourdough classes','title'=>'Title A '.uniqid()]];
        $t1 = app(TaskService::class)->create($ws, $d);
        $d2 = $d; $d2['payload']['title'] = 'Title B '.uniqid(); // different model-generated title, same request
        $t2 = app(TaskService::class)->create($ws, $d2);
        $this->assertSame($t1->id, $t2->id, 'identical billable user_request must dedupe to one task');
        $this->assertSame(1, DB::table('tasks')->where('workspace_id',$ws)->where('action','serp_analysis')->count());
    }

    public function test_different_billable_requests_do_not_dedupe(): void
    {
        $ws = $this->freshWs();
        $t1 = app(TaskService::class)->create($ws, ['action'=>'serp_analysis','engine'=>'seo','credit_cost'=>1,'source'=>'manual',
              'payload'=>['user_request'=>'SERP for pretzels']]);
        $t2 = app(TaskService::class)->create($ws, ['action'=>'serp_analysis','engine'=>'seo','credit_cost'=>1,'source'=>'manual',
              'payload'=>['user_request'=>'SERP for bagels']]);
        $this->assertNotSame($t1->id, $t2->id, 'different requests must create distinct tasks');
    }

    public function test_nonbillable_keeps_payload_keying(): void
    {
        $ws = $this->freshWs();
        // credit_cost 0 → payload-based key; different payloads → distinct tasks (backward compat)
        $t1 = app(TaskService::class)->create($ws, ['action'=>'add_keyword','engine'=>'seo','credit_cost'=>0,'source'=>'manual',
              'payload'=>['user_request'=>'same req','keyword'=>'alpha']]);
        $t2 = app(TaskService::class)->create($ws, ['action'=>'add_keyword','engine'=>'seo','credit_cost'=>0,'source'=>'manual',
              'payload'=>['user_request'=>'same req','keyword'=>'beta']]);
        $this->assertNotSame($t1->id, $t2->id, 'non-billable distinct payloads must remain distinct');
    }
}
