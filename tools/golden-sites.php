<?php
// RFC-0021 wave 0: golden snapshot of every site's static export. Usage:
//   php tools/golden-sites.php snapshot [name]   -> tools/golden/<name>.json (default: timestamp)
//   php tools/golden-sites.php diff <base> [head] -> lists changed/added/removed files vs base (head = live tree if omitted)
$root = dirname(__DIR__) . '/storage/app/public/sites';
$out = __DIR__ . '/golden'; if (!is_dir($out)) mkdir($out, 0775, true);
$cmd = $argv[1] ?? 'snapshot';
$scan = function () use ($root) {
    $map = [];
    foreach (glob($root . '/*', GLOB_ONLYDIR) as $dir) {
        $id = basename($dir); if (!ctype_digit($id)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = $f->getPathname(); $rel = substr($p, strlen($root) + 1);
            if (strpos($rel, '/.history/') !== false) continue;
            $map[$rel] = ['sha' => sha1_file($p), 'size' => $f->getSize()];
        }
    }
    ksort($map); return $map;
};
if ($cmd === 'snapshot') {
    $name = $argv[2] ?? date('Ymd-His'); $map = $scan();
    file_put_contents("$out/$name.json", json_encode(['taken' => date('c'), 'files' => $map], JSON_PRETTY_PRINT));
    $sites = count(array_unique(array_map(fn($k) => explode('/', $k)[0], array_keys($map))));
    echo "snapshot $name: " . count($map) . " files across $sites sites\n"; exit(0);
}
if ($cmd === 'diff') {
    $base = json_decode(file_get_contents("$out/{$argv[2]}.json"), true)['files'];
    $head = isset($argv[3]) ? json_decode(file_get_contents("$out/{$argv[3]}.json"), true)['files'] : $scan();
    $changed = $added = $removed = [];
    foreach ($head as $k => $v) { if (!isset($base[$k])) $added[] = $k; elseif ($base[$k]['sha'] !== $v['sha']) $changed[] = $k; }
    foreach ($base as $k => $v) if (!isset($head[$k])) $removed[] = $k;
    foreach (['changed' => $changed, 'added' => $added, 'removed' => $removed] as $kind => $list) { echo strtoupper($kind) . ' ' . count($list) . "\n"; foreach ($list as $l) echo "  $l\n"; }
    $sites = array_unique(array_map(fn($k) => explode('/', $k)[0], array_merge($changed, $added, $removed)));
    echo "sites touched: " . count($sites) . (count($sites) ? ' (' . implode(', ', array_slice($sites, 0, 30)) . ')' : '') . "\n";
    exit(count($changed) + count($added) + count($removed) ? 1 : 0);
}
