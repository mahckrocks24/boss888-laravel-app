<?php

namespace App\Console\Commands;

use App\Engines\Resume\Services\ResumeService;
use Illuminate\Console\Command;

/** RESUME888 — daily retention sweep: expired sessions lose PII and files (30 days anonymous, 12 months saved). */
class ResumePurge extends Command
{
    protected $signature = 'resume:purge';
    protected $description = 'Purge expired resume-builder sessions, uploads and PDFs';

    public function handle(ResumeService $svc): int
    {
        $r = $svc->purge();
        \Illuminate\Support\Facades\Cache::put('resume:purge:last_run', now()->toIso8601String(), 172800);
        $this->line(json_encode($r));
        return self::SUCCESS;
    }
}
