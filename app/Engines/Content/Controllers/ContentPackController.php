<?php

namespace App\Engines\Content\Controllers;

use App\Core\EngineKernel\EngineExecutionService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * HTTP entry point for content packs. Always routes through
 * EngineExecutionService so cap-map / plan-gating / credits / approvals
 * stay consistent with every other engine surface.
 */
class ContentPackController extends Controller
{
    public function __construct(private readonly EngineExecutionService $kernel) {}

    public function create(Request $r)
    {
        return response()->json($this->kernel->execute(
            (int) $r->attributes->get('workspace_id'),
            'content',
            'create_pack',
            $r->all() + ['created_by' => $r->user()?->id],
            ['source' => 'manual', 'user_id' => $r->user()?->id]
        ));
    }

    public function addAsset(Request $r, int $id)
    {
        return response()->json($this->kernel->execute(
            (int) $r->attributes->get('workspace_id'),
            'content',
            'add_asset',
            $r->all() + ['pack_id' => $id],
            ['source' => 'manual', 'user_id' => $r->user()?->id]
        ));
    }

    public function get(Request $r, int $id)
    {
        return response()->json($this->kernel->execute(
            (int) $r->attributes->get('workspace_id'),
            'content',
            'get_pack',
            ['pack_id' => $id],
            ['source' => 'manual', 'user_id' => $r->user()?->id]
        ));
    }

    public function list(Request $r)
    {
        return response()->json($this->kernel->execute(
            (int) $r->attributes->get('workspace_id'),
            'content',
            'list_packs',
            $r->query(),
            ['source' => 'manual', 'user_id' => $r->user()?->id]
        ));
    }

    public function publish(Request $r, int $id)
    {
        return response()->json($this->kernel->execute(
            (int) $r->attributes->get('workspace_id'),
            'content',
            'publish_pack',
            ['pack_id' => $id],
            ['source' => 'manual', 'user_id' => $r->user()?->id]
        ));
    }
}