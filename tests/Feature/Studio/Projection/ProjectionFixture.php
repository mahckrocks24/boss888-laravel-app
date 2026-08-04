<?php

namespace Tests\Feature\Studio\Projection;

use App\Engines\Studio\Projection\InMemoryProjectionAdapter;
use App\Engines\Studio\Projection\ProjectionRequest;

/** Pure builders for Phase-3B tests. No DB/Runtime/browser/routes. */
final class ProjectionFixture
{
    public static function state(): array
    {
        return [
            'stat'     => ['style.color' => '#ffd60a', 'text' => '98%'],
            'headline' => ['style.color' => '#111111', 'text' => 'Big Sale', 'visible' => true],
        ];
    }

    public static function adapter(): InMemoryProjectionAdapter
    {
        return new InMemoryProjectionAdapter(self::state(), null, 1);
    }

    /**
     * @param array{op?:string,ver?:?string,hash?:?string,corr?:string,key?:?string,fields?:array,desired?:array} $o
     */
    public static function request(string $target, array $fields, array $desired, array $o = []): ProjectionRequest
    {
        return new ProjectionRequest(
            operationId: $o['op'] ?? 'o1',
            documentId: 'doc1',
            targetId: $target,
            changedFields: $fields,
            desiredAfterState: $desired,
            expectedDocumentVersion: $o['ver'] ?? null,
            beforeSnapshotHash: $o['hash'] ?? null,
            correlationId: $o['corr'] ?? 'corr-1',
            idempotencyKey: $o['key'] ?? null,
        );
    }

    public static function color(string $target, string $color, array $o = []): ProjectionRequest
    {
        return self::request($target, ['style.color'], ['style.color' => $color], $o);
    }
}
