<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Build semantic topic clusters using TF-IDF + cosine similarity.
 * No external API — pure PHP. Reads seo_content_index, writes
 * seo_clusters + seo_cluster_members + seo_content_index.cluster_id.
 */
class SeoSemanticClusterCommand extends Command
{
    protected $signature   = 'seo:cluster {workspace_id? : Workspace ID (all if omitted)}';
    protected $description = 'Build semantic topic clusters using TF-IDF (no external API).';

    /** @var string[] */
    private array $stopwords = [
        'the','a','an','and','or','but','in','on','at','to','for','of','with',
        'by','from','as','is','was','are','were','be','been','have','has','had',
        'this','that','these','those','it','its','we','our','you','your','they',
        'about','dubai','uae','also','more','will','can','get','use','one','all',
        'some','any','each','when','what','how','who','which','than','then',
        'into','over','after','here','there','just','like','make','time','well',
        'way','even','know','take','year','good','them','see','only','best',
    ];

    public function handle(): int
    {
        $wsArg = $this->argument('workspace_id');
        $ids = $wsArg
            ? [(int) $wsArg]
            : DB::table('workspaces')->pluck('id')->toArray();
        foreach ($ids as $id) { $this->clusterWorkspace((int) $id); }
        return 0;
    }

    private function clusterWorkspace(int $wsId): void
    {
        $pages = DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->where('word_count', '>', 100)
            ->get(['url', 'title', 'h1', 'meta_description', 'intent',
                   'authority_score', 'content_score']);

        if ($pages->count() < 3) {
            $this->info("ws={$wsId}: Too few pages to cluster ({$pages->count()})");
            return;
        }

        // Step 1: tokenize each page
        $docs = [];
        foreach ($pages as $page) {
            $text = ($page->title ?? '') . ' ' . ($page->h1 ?? '')
                  . ' ' . ($page->meta_description ?? '');
            $docs[$page->url] = $this->tokenize($text);
        }

        // Step 2: document frequency
        $total = count($docs);
        $df = [];
        foreach ($docs as $tokens) {
            foreach (array_unique($tokens) as $term) {
                $df[$term] = ($df[$term] ?? 0) + 1;
            }
        }
        // Step 3: IDF
        $idf = [];
        foreach ($df as $term => $freq) {
            $idf[$term] = log(($total + 1) / ($freq + 1)) + 1;
        }

        // Step 4: TF-IDF vectors
        $vectors = [];
        foreach ($docs as $url => $tokens) {
            $tf = array_count_values($tokens);
            $n  = max(1, count($tokens));
            $vec = [];
            foreach ($tf as $term => $count) {
                $vec[$term] = ($count / $n) * ($idf[$term] ?? 1);
            }
            $vectors[$url] = $vec;
        }

        // Step 5: greedy pairwise clustering at threshold 0.25
        $threshold = 0.25;
        $clusters  = [];
        $assigned  = [];
        $urls      = array_keys($vectors);
        foreach ($urls as $i => $urlA) {
            if (isset($assigned[$urlA])) { continue; }
            $cluster = [$urlA => 1.0];
            $assigned[$urlA] = true;
            foreach ($urls as $j => $urlB) {
                if ($i >= $j) { continue; }
                if (isset($assigned[$urlB])) { continue; }
                $sim = $this->cosineSimilarity($vectors[$urlA], $vectors[$urlB]);
                if ($sim >= $threshold) {
                    $cluster[$urlB] = $sim;
                    $assigned[$urlB] = true;
                }
            }
            if (count($cluster) >= 2) { $clusters[] = $cluster; }
        }
        // Singletons for unclustered pages
        foreach ($urls as $url) {
            if (!isset($assigned[$url])) { $clusters[] = [$url => 1.0]; }
        }

        // Step 6: persist — wipe prior cluster state for this workspace
        DB::table('seo_cluster_members')->where('workspace_id', $wsId)->delete();
        DB::table('seo_clusters')->where('workspace_id', $wsId)->delete();
        DB::table('seo_content_index')->where('workspace_id', $wsId)->update(['cluster_id' => null]);

        $pageMap = $pages->keyBy('url');

        foreach ($clusters as $clusterUrls) {
            arsort($clusterUrls);

            // Pillar = highest-authority member
            $pillarUrl = null;
            $maxAuth   = -1.0;
            foreach (array_keys($clusterUrls) as $url) {
                $auth = (float) ($pageMap[$url]->authority_score ?? 0);
                if ($auth > $maxAuth) { $maxAuth = $auth; $pillarUrl = $url; }
            }

            // Top terms from pillar
            $pillarTokens = $docs[$pillarUrl] ?? [];
            $tf = array_count_values($pillarTokens);
            arsort($tf);
            $topTerms = array_slice(array_keys($tf), 0, 5);

            $label = $pageMap[$pillarUrl]->title
                ?? basename(rtrim((string) $pillarUrl, '/'))
                ?: 'Untitled cluster';

            $avgScore = collect(array_keys($clusterUrls))
                ->map(fn ($u) => (float) ($pageMap[$u]->content_score ?? 0))
                ->avg() ?: 0.0;
            $avgAuth = collect(array_keys($clusterUrls))
                ->map(fn ($u) => (float) ($pageMap[$u]->authority_score ?? 0))
                ->avg() ?: 0.0;

            $clusterId = DB::table('seo_clusters')->insertGetId([
                'workspace_id'  => $wsId,
                'label'         => mb_substr($label, 0, 255),
                'pillar_url'    => $pillarUrl,
                'page_count'    => count($clusterUrls),
                'avg_score'     => round($avgScore, 2),
                'avg_authority' => round($avgAuth, 4),
                'top_terms'     => json_encode($topTerms),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            foreach ($clusterUrls as $url => $sim) {
                DB::table('seo_cluster_members')->insert([
                    'cluster_id'   => $clusterId,
                    'workspace_id' => $wsId,
                    'url'          => $url,
                    'similarity'   => round($sim, 4),
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
                DB::table('seo_content_index')
                    ->where('workspace_id', $wsId)
                    ->where('url', $url)
                    ->update(['cluster_id' => $clusterId]);
            }
        }

        $this->info("ws={$wsId}: Built " . count($clusters) . " clusters from " . $pages->count() . " pages");
    }

    private function tokenize(string $text): array
    {
        $clean = strtolower(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $text));
        $words = preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($words,
            fn ($w) => strlen($w) > 3 && !in_array($w, $this->stopwords, true)));
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $terms = array_unique(array_merge(array_keys($a), array_keys($b)));
        $dot = $magA = $magB = 0.0;
        foreach ($terms as $t) {
            $va = $a[$t] ?? 0.0;
            $vb = $b[$t] ?? 0.0;
            $dot  += $va * $vb;
            $magA += $va * $va;
            $magB += $vb * $vb;
        }
        $denom = sqrt($magA) * sqrt($magB);
        return $denom > 0 ? $dot / $denom : 0.0;
    }
}
