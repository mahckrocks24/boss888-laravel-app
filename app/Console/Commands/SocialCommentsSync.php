<?php

namespace App\Console\Commands;

use App\Engines\Social\Services\CommentInboxService;
use Illuminate\Console\Command;

/** COMMENTS-1: read new Facebook Page comments, let Sarah triage them, queue drafted replies for approval. */
class SocialCommentsSync extends Command
{
    protected $signature = 'social:comments-sync {--ws= : only this workspace}';
    protected $description = 'Read new Page comments; Sarah drafts replies that wait for the Owner\'s approval';

    public function handle(CommentInboxService $inbox): int
    {
        $ws = $this->option('ws') ? (int) $this->option('ws') : null;
        foreach ($inbox->syncAll($ws) as $r) {
            $this->line(json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        return self::SUCCESS;
    }
}
