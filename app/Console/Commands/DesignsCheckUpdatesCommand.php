<?php

namespace App\Console\Commands;

use App\Engines\Builder\Services\DesignUpdateService;
use Illuminate\Console\Command;

/** DESIGN-UPDATES-1: find websites whose design has a newer version, build each candidate beside the site, probe it. Nothing is applied. */
class DesignsCheckUpdatesCommand extends Command
{
    protected $signature = 'designs:check-updates {--site= : one website only (the switch still decides its workspace)} {--no-probe : build and report, skip the browser probe}';
    protected $description = 'DESIGN-UPDATES-1: prepare design updates for the owner to preview (never applies one)';

    public function handle(DesignUpdateService $svc): int
    {
        $site = $this->option('site') !== null ? (int) $this->option('site') : null;
        foreach ($svc->check($site, ! $this->option('no-probe')) as $line) $this->line($line);
        return self::SUCCESS;
    }
}
