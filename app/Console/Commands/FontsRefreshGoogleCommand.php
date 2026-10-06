<?php

namespace App\Console\Commands;

use App\Engines\Builder\Support\FontLibrary;
use Illuminate\Console\Command;

/**
 * FONTS-8 (2026-10-06): refresh the Google Fonts catalogue the editor's Fonts panel lists
 * (storage/app/google-fonts.json + cache). Weekly from the scheduler; run by hand any time:
 *   sudo -u www-data php artisan fonts:refresh-google
 * A failed fetch keeps the previous file.
 */
class FontsRefreshGoogleCommand extends Command
{
    protected $signature = 'fonts:refresh-google';
    protected $description = 'FONTS-8: fetch Google Fonts metadata into storage/app/google-fonts.json';

    public function handle(): int
    {
        $d = FontLibrary::refresh();
        if ($d === null) { $this->error('Fetch failed; the previous catalogue stays.'); return self::FAILURE; }
        @chown(storage_path(FontLibrary::FILE), 'www-data'); @chgrp(storage_path(FontLibrary::FILE), 'www-data');   // the scheduler runs as root
        $this->info('Google Fonts catalogue: ' . $d['count'] . ' families.');
        return self::SUCCESS;
    }
}
