<?php
$live = fn() => (string) @file_get_contents("https://fable-consistency-l.levelupgrowth.io/?cb=" . microtime(true), false, stream_context_create(["http" => ["header" => "User-Agent: Mozilla/5.0 Chrome/140\r\n", "timeout" => 20]]));
$p = DB::table("pages")->where("id", 772)->first(); $orig = (string) $p->sections_json; $doc = json_decode($orig, true); $secs = $doc["sections"] ?? $doc;
$h0 = $live(); echo "live before has proof: " . (int) str_contains($h0, "DRAFT PROOF") . "  served-by: " . (preg_match("/stone-baked since dawn/i", $h0) ? "hero ok" : "hero?") . PHP_EOL;
foreach ($secs as &$s) { if (($s["type"] ?? "") === "hero") $s["heading"] = "Stone-Baked Since Dawn — DRAFT PROOF"; } unset($s);
app(\App\Engines\Builder\Services\BuilderService::class)->updatePage(772, ["sections" => $secs], 999994);
$p1 = DB::table("pages")->where("id", 772)->first();
echo "after edit: live column changed=" . (int) ($p1->sections_json !== $orig) . " draft set=" . (int) ($p1->draft_sections_json !== null) . " draft has proof=" . (int) str_contains((string) $p1->draft_sections_json, "DRAFT PROOF") . PHP_EOL;
$ch = \App\Engines\Builder\Support\DraftEdits::changes(504); echo "changes: " . json_encode(["has_live" => $ch["has_live"], "renderer" => $ch["renderer"] ?? null, "count" => $ch["count"], "first" => $ch["fields"][0] ?? null], JSON_UNESCAPED_UNICODE) . PHP_EOL;
$gp = app(\App\Engines\Builder\Services\BuilderService::class)->getPage(772, 999994); echo "getPage shows draft=" . (int) str_contains((string) $gp->sections_json, "DRAFT PROOF") . " has_draft=" . json_encode($gp->has_draft ?? null) . PHP_EOL;
$h1 = $live(); echo "live after edit has proof: " . (int) str_contains($h1, "DRAFT PROOF") . PHP_EOL;
$pr = \App\Engines\Builder\Support\DraftEdits::promote(504); echo "promote: " . json_encode($pr) . PHP_EOL;
$p2 = DB::table("pages")->where("id", 772)->first(); echo "after promote: live has proof=" . (int) str_contains((string) $p2->sections_json, "DRAFT PROOF") . " draft cleared=" . (int) ($p2->draft_sections_json === null) . PHP_EOL;
$h2 = $live(); echo "live after promote has proof: " . (int) str_contains($h2, "DRAFT PROOF") . PHP_EOL;
// put it back the same way the owner would: edit (draft) then publish
foreach ($secs as &$s) { if (($s["type"] ?? "") === "hero") $s["heading"] = "Stone-Baked Since Dawn"; } unset($s);
app(\App\Engines\Builder\Services\BuilderService::class)->updatePage(772, ["sections" => $secs], 999994);
$pr2 = \App\Engines\Builder\Support\DraftEdits::promote(504); $h3 = $live();
echo "restored: promote=" . json_encode($pr2) . " live has proof=" . (int) str_contains($h3, "DRAFT PROOF") . " heading back=" . (int) (bool) preg_match("/Stone-Baked Since Dawn/", $h3) . PHP_EOL;
echo "changes now: " . \App\Engines\Builder\Support\DraftEdits::changes(504)["count"] . PHP_EOL;

