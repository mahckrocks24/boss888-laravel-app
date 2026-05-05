<?php

namespace App\Engines\TrafficDefense\Services;

use Illuminate\Support\Facades\DB;

class TrafficDefenseService
{
    public function createRule(int $wsId, array $data): int
    {
        return DB::table('traffic_rules')->insertGetId([
            'workspace_id' => $wsId,
            'name' => $data['name'] ?? 'New Rule',
            'type' => $data['type'] ?? 'rate_limit',
            'config_json' => json_encode($data['config'] ?? []),
            'enabled' => true, 'hits' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function listRules(int $wsId): array
    {
        return DB::table('traffic_rules')->where('workspace_id', $wsId)->orderByDesc('created_at')->get()->toArray();
    }

    public function toggleRule(int $ruleId, bool $enabled): void
    {
        DB::table('traffic_rules')->where('id', $ruleId)->update(['enabled' => $enabled, 'updated_at' => now()]);
    }

    public function deleteRule(int $ruleId): void
    {
        DB::table('traffic_rules')->where('id', $ruleId)->delete();
    }

    /**
     * Evaluate incoming traffic against rules. Returns quality score 0-100.
     */
    public function evaluateTraffic(int $wsId, array $request): array
    {
        $ip = $request['ip'] ?? '';
        $ua = $request['user_agent'] ?? '';
        $referrer = $request['referrer'] ?? '';
        $country = $request['country'] ?? '';

        $score = 100;
        $flags = [];
        $action = 'allowed';

        // Known bot user agents
        $botPatterns = ['bot', 'crawler', 'spider', 'scraper', 'curl', 'wget', 'python-requests', 'headless'];
        foreach ($botPatterns as $pattern) {
            if (stripos($ua, $pattern) !== false) {
                $score -= 40;
                $flags[] = "bot_ua:{$pattern}";
            }
        }

        // Empty user agent
        if (empty($ua)) { $score -= 30; $flags[] = 'empty_ua'; }

        // Suspicious referrer
        $badReferrers = ['semalt', 'buttons-for-website', 'social-buttons'];
        foreach ($badReferrers as $bad) {
            if (stripos($referrer, $bad) !== false) {
                $score -= 25;
                $flags[] = "bad_referrer:{$bad}";
            }
        }

        // Check custom rules
        $rules = DB::table('traffic_rules')->where('workspace_id', $wsId)->where('enabled', true)->get();
        foreach ($rules as $rule) {
            $config = json_decode($rule->config_json ?? '{}', true);
            $matched = false;

            if ($rule->type === 'ip_block' && in_array($ip, $config['ips'] ?? [])) $matched = true;
            if ($rule->type === 'country_block' && in_array($country, $config['countries'] ?? [])) $matched = true;
            if ($rule->type === 'ua_block' && !empty($config['pattern']) && stripos($ua, $config['pattern']) !== false) $matched = true;
            if ($rule->type === 'referrer_block' && !empty($config['pattern']) && stripos($referrer, $config['pattern']) !== false) $matched = true;

            if ($matched) {
                $score = 0;
                $action = 'blocked';
                $flags[] = "rule:{$rule->name}";
                DB::table('traffic_rules')->where('id', $rule->id)->increment('hits');
                break;
            }
        }

        // Rate limiting check
        if ($action !== 'blocked') {
            $recentHits = DB::table('traffic_logs')->where('workspace_id', $wsId)
                ->where('ip', $ip)->where('created_at', '>=', now()->subMinutes(1))->count();
            if ($recentHits > 60) { $score = max(0, $score - 50); $flags[] = 'rate_limit'; $action = 'flagged'; }
        }

        $score = max(0, min(100, $score));
        if ($score < 30) $action = 'blocked';
        elseif ($score < 60) $action = 'flagged';

        // Log the visit
        $this->logTraffic($wsId, [
            'ip' => $ip, 'user_agent' => $ua, 'referrer' => $referrer,
            'country' => $country, 'action' => $action,
            'quality_score' => $score, 'rule_name' => implode(', ', $flags) ?: null,
        ]);

        return ['score' => $score, 'action' => $action, 'flags' => $flags];
    }

    public function logTraffic(int $wsId, array $data): void
    {
        DB::table('traffic_logs')->insert([
            'workspace_id' => $wsId,
            'ip' => $data['ip'] ?? '', 'user_agent' => $data['user_agent'] ?? null,
            'referrer' => $data['referrer'] ?? null, 'country' => $data['country'] ?? null,
            'action' => $data['action'] ?? 'allowed', 'rule_name' => $data['rule_name'] ?? null,
            'quality_score' => $data['quality_score'] ?? null,
            'created_at' => now(),
        ]);
    }

    public function getStats(int $wsId, int $days = 7): array
    {
        $since = now()->subDays($days);
        $logs = DB::table('traffic_logs')->where('workspace_id', $wsId)->where('created_at', '>=', $since);

        $total = (clone $logs)->count();
        $blocked = (clone $logs)->where('action', 'blocked')->count();
        $flagged = (clone $logs)->where('action', 'flagged')->count();
        $allowed = (clone $logs)->where('action', 'allowed')->count();
        $avgScore = (clone $logs)->avg('quality_score') ?? 0;

        // Top blocked IPs
        $topBlocked = (clone $logs)->where('action', 'blocked')
            ->selectRaw('ip, COUNT(*) as hits')
            ->groupBy('ip')->orderByDesc('hits')->limit(10)->get()->toArray();

        // Daily breakdown
        $daily = (clone $logs)->selectRaw('DATE(created_at) as date, action, COUNT(*) as count')
            ->groupBy('date', 'action')->orderBy('date')->get()->toArray();

        return [
            'total' => $total, 'blocked' => $blocked, 'flagged' => $flagged, 'allowed' => $allowed,
            'avg_quality_score' => round($avgScore, 1),
            'block_rate' => $total > 0 ? round(($blocked / $total) * 100, 1) : 0,
            'top_blocked_ips' => $topBlocked,
            'daily' => $daily,
            'active_rules' => DB::table('traffic_rules')->where('workspace_id', $wsId)->where('enabled', true)->count(),
        ];
    }
}
