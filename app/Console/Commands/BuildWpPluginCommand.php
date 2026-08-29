<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * WP-1 (2026-08-29) — build the customer-downloadable WordPress plugin package from
 * resources/wp-plugin/level-up-growth-seo-connector into storage/app/plugins (git-ignored build
 * artefact) and write manifest.json {version, sha256, built_at}. Served by
 * GET /api/settings/connector-plugin/{info,download} (Websites → Connect WordPress).
 *
 *   php artisan connector:build-plugin
 */
class BuildWpPluginCommand extends Command
{
    protected $signature   = 'connector:build-plugin {--source=resources/wp-plugin/level-up-growth-seo-connector} {--out=storage/app/plugins}';
    protected $description = 'Zip the WordPress connector plugin into storage/app/plugins and write manifest.json';

    public function handle(): int
    {
        $src  = base_path($this->option('source'));
        $out  = base_path($this->option('out'));
        $slug = basename($src);
        if (!is_dir($src) || !is_file($src . '/' . $slug . '.php')) {
            $this->error("Plugin source not found: {$src}");
            return self::FAILURE;
        }
        if (!preg_match('/^\s*\*\s*Version:\s*([0-9][0-9.]*)/m', (string) file_get_contents($src . '/' . $slug . '.php'), $m)) {
            $this->error('Could not read the plugin Version header.');
            return self::FAILURE;
        }
        $version = $m[1];
        if (!is_dir($out) && !mkdir($out, 0775, true) && !is_dir($out)) {
            $this->error("Cannot create {$out}");
            return self::FAILURE;
        }
        $zipPath = $out . '/' . $slug . '.zip';
        $tmp     = $zipPath . '.tmp';
        @unlink($tmp);

        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->error("Cannot open {$tmp} for writing");
            return self::FAILURE;
        }
        $files = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) {
            $rel = $slug . '/' . ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($src))), '/');
            if ($file->isDir()) {
                $zip->addEmptyDir($rel);
                continue;
            }
            if (preg_match('#(^|/)(\.git|\.DS_Store|node_modules)(/|$)#', $rel)) {
                continue;
            }
            $zip->addFile($file->getPathname(), $rel);
            $files++;
        }
        $zip->close();
        rename($tmp, $zipPath);

        $manifest = [
            'version'  => $version,
            'sha256'   => hash_file('sha256', $zipPath),
            'bytes'    => filesize($zipPath),
            'files'    => $files,
            'built_at' => gmdate('c'),
        ];
        file_put_contents($out . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->info("Built {$zipPath} v{$version} ({$files} files, {$manifest['bytes']} bytes) sha256 {$manifest['sha256']}");
        return self::SUCCESS;
    }
}
