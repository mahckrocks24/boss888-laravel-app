<?php

namespace Tests\Feature\Studio\Projection\Html;

use App\Engines\Studio\Projection\Html\HtmlProjectionAdapter;
use App\Engines\Studio\Projection\ProjectionRequest;

/** Pure builders for Phase-4B tests. No DB/browser/routes/Runtime/provider. */
final class HtmlProjectionFixture
{
    public static function rawHtml(): string
    {
        return "<!doctype html>\n"
            . "<html><head><style>:root{--primary:#FFD60A;--accent:#FFD60A;--bg:#111111}</style></head>\n"
            . "<body class=\"lu-studio-edit\">\n"
            . "<div class=\"hero\">\n"
            . "<span data-field=\"img_stat_val\" style=\"color:var(--primary);font-size:72px\" contenteditable=\"true\">98%</span>\n"
            . "<span data-field=\"cta_label\" style=\"color:#FFD60A\">Buy now</span>\n"
            . "<h1 data-field=\"headline\" style=\"color:#111111\">Big Sale</h1>\n"
            . "<div data-field=\"wrapper\"><img src=\"x.png\"/></div>\n"
            . "<span data-field=\"note\" onclick=\"alert(1)\">note</span>\n"
            . "</div>\n"
            . "<script>evil()</script>\n"
            . "</body></html>";
    }

    public static function duplicateHtml(): string
    {
        return "<div><span data-field=\"dup\">a</span><span data-field=\"dup\">b</span></div>";
    }

    public static function structuredPayload(): array
    {
        return [
            'template_slug' => 'promo_a',
            'fields'        => ['headline' => 'Big Sale', 'sub' => 'Today', 'primary' => '#FFD60A'],
            'meta'          => ['author' => 'system', 'rev' => 3],
        ];
    }

    public static function rawAdapter(): HtmlProjectionAdapter
    {
        return HtmlProjectionAdapter::forRawHtml(self::rawHtml());
    }

    public static function structuredAdapter(): HtmlProjectionAdapter
    {
        return HtmlProjectionAdapter::forStructured(self::structuredPayload());
    }

    public static function styleReq(string $target, string $prop, string $value, array $o = []): ProjectionRequest
    {
        $path = 'style.' . $prop;

        return self::req($target, [$path], [$path => $value], $o);
    }

    public static function textReq(string $target, string $value, array $o = []): ProjectionRequest
    {
        return self::req($target, ['text'], ['text' => $value], $o);
    }

    public static function visibleReq(string $target, bool $value, array $o = []): ProjectionRequest
    {
        return self::req($target, ['visible'], ['visible' => $value], $o);
    }

    public static function req(string $target, array $fields, array $desired, array $o = []): ProjectionRequest
    {
        return new ProjectionRequest(
            operationId: $o['op'] ?? 'o1',
            documentId: 'd1',
            targetId: $target,
            changedFields: $fields,
            desiredAfterState: $desired,
            expectedDocumentVersion: $o['ver'] ?? null,
            beforeSnapshotHash: $o['hash'] ?? null,
            correlationId: $o['corr'] ?? 'c1',
            idempotencyKey: $o['key'] ?? null,
        );
    }
}
