<?php
$f = '/var/www/levelup-staging/app/Core/Brand/DesignLibraryService.php';
$s = file_get_contents($f);
$old = "'thumb' => \$r->thumb_path ? Storage::disk('public')->url(\$r->thumb_path) : null,";
$new = "'thumb' => \$r->thumb_path ? Storage::disk('public')->url(\$r->thumb_path) . '?v=' . strtotime((string) (\$r->updated_at ?? 'now')) : null,";
if (strpos($s, $old) === false) { echo "anchor not found\n"; exit(1); }
file_put_contents($f, str_replace($old, $new, $s));
echo "patched\n";
