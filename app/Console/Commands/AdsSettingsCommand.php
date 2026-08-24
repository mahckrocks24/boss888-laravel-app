<?php

namespace App\Console\Commands;

use App\Engines\Ads\Services\AdAuditService;
use App\Engines\Ads\Services\AdSettingsService;
use App\Engines\Ads\Support\AdSettings;
use Illuminate\Console\Command;

/**
 * ADS888 — read and change every operational setting.
 *
 * WHY A COMMAND AND NOT AN ADMIN SCREEN (yet)
 * The admin HTTP surface needs routes, and `routes/api.php` is an active
 * concurrency hotspot shared with another workstream. This gives an operator
 * complete control today without touching it; the console screen will call the
 * same AdSettingsService when it is built.
 *
 * EVERY CHANGE IS AUDITED — actor, before, after — via AdSettingsService::set().
 *
 *   php artisan ads:settings                       list everything
 *   php artisan ads:settings --key=refresh_interval_ms
 *   php artisan ads:settings --key=refresh_interval_ms --value=45000
 *   php artisan ads:settings --key=eligible_plan_slugs --value='["free","starter"]'
 *   php artisan ads:settings --key=modal_enabled --value=true
 *   php artisan ads:settings --key=refresh_interval_ms --reset
 *   php artisan ads:settings --fixed                what is deliberately NOT tunable
 */
class AdsSettingsCommand extends Command
{
    protected $signature = 'ads:settings
        {--key= : Setting key to read or change}
        {--value= : New value. JSON for lists/booleans/numbers, or a bare string}
        {--reset : Restore this key to its shipped default}
        {--fixed : Show the values that are deliberately NOT settings, and why}
        {--force : Apply a change even when validation objects}
        {--json : Raw JSON output}';

    protected $description = 'ADS888: read and change ad platform settings (audited)';

    public function handle(AdSettingsService $settings, AdAuditService $audit): int
    {
        if ($this->option('fixed')) {
            return $this->showFixed();
        }

        $key = $this->option('key');

        if ($key === null) {
            return $this->listAll($settings);
        }

        if (! AdSettings::isKnown($key)) {
            $this->error("Unknown setting: {$key}");
            $this->line('  Run `php artisan ads:settings` to list them all.');

            return self::FAILURE;
        }

        if ($this->option('reset')) {
            return $this->reset($settings, $key);
        }

        if ($this->option('value') === null) {
            return $this->showOne($settings, $key);
        }

        return $this->change($settings, $key);
    }

    // ─────────────────────────────────────────────────────────────────────

    private function listAll(AdSettingsService $settings): int
    {
        $all = $settings->all();

        if ($this->option('json')) {
            $this->line(json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->info('ADS888 — settings');
        $this->line(str_repeat('─', 90));

        $rows = [];
        foreach ($all as $key => $meta) {
            $rows[] = [
                $key,
                $this->render($meta['value']),
                $meta['overridden'] ? 'yes' : '',
                mb_strimwidth((string) $meta['description'], 0, 46, '…'),
            ];
        }

        $this->table(['key', 'value', 'changed', 'what it does'], $rows);
        $this->line('');
        $this->line('  Change one:  php artisan ads:settings --key=<key> --value=<value>');
        $this->line('  Full detail: php artisan ads:settings --key=<key>');
        $this->line('  Not tunable: php artisan ads:settings --fixed');
        $this->line('');

        return self::SUCCESS;
    }

    private function showOne(AdSettingsService $settings, string $key): int
    {
        $value = $settings->get($key);

        if ($this->option('json')) {
            $this->line(json_encode([
                'key' => $key, 'value' => $value, 'default' => AdSettings::default($key),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->info($key);
        $this->line(str_repeat('─', 78));
        $this->line('  current : ' . $this->render($value));
        $this->line('  default : ' . $this->render(AdSettings::default($key)));
        $this->line('');
        $this->line('  ' . wordwrap((string) AdSettings::describe($key), 74, "\n  "));
        $this->line('');

        return self::SUCCESS;
    }

    private function change(AdSettingsService $settings, string $key): int
    {
        $raw    = (string) $this->option('value');
        $parsed = $this->parse($raw);
        $before = $settings->get($key);

        // Guardrails explain themselves — a rejection with no reason just gets
        // worked around by the next person.
        $objection = AdSettings::validate($key, $parsed);

        if ($objection !== null) {
            $this->line('');
            $this->warn("  {$key}: " . wordwrap($objection, 72, "\n  "));
            $this->line('');

            if (! $this->option('force')) {
                $this->error('  Refused. Re-run with --force if this is deliberate.');
                $this->line('');

                return self::FAILURE;
            }

            $this->warn('  --force given: applying anyway.');
        }

        $settings->set(
            $key,
            $parsed,
            null,
            'ads:settings' . ($objection !== null ? ' (forced)' : '')
        );

        $this->line('');
        $this->info("  {$key}");
        $this->line('    was : ' . $this->render($before));
        $this->line('    now : ' . $this->render($settings->get($key)));
        $this->line('');
        $this->comment('  Takes effect within 60s (settings cache). Change is audited in ad_audit_log.');
        $this->line('');

        return self::SUCCESS;
    }

    private function reset(AdSettingsService $settings, string $key): int
    {
        $before = $settings->get($key);
        $settings->set($key, AdSettings::default($key), null, 'ads:settings --reset');

        $this->line('');
        $this->info("  {$key} reset");
        $this->line('    was : ' . $this->render($before));
        $this->line('    now : ' . $this->render($settings->get($key)) . '  (shipped default)');
        $this->line('');

        return self::SUCCESS;
    }

    private function showFixed(): int
    {
        $this->line('');
        $this->info('ADS888 — deliberately NOT settings');
        $this->line(str_repeat('─', 78));
        $this->line('');

        foreach (AdSettings::fixedByDesign() as $what => $why) {
            $this->line('  ' . $what);
            $this->line('    ' . wordwrap($why, 72, "\n    "));
            $this->line('');
        }

        return self::SUCCESS;
    }

    /**
     * Parse a CLI value. JSON first so lists, booleans and numbers keep their
     * type; a bare string is accepted for convenience.
     */
    private function parse(string $raw): mixed
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            return '';
        }

        $decoded = json_decode($trimmed, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return match (strtolower($trimmed)) {
            'true'  => true,
            'false' => false,
            'null'  => null,
            default => $trimmed,
        };
    }

    private function render(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        return (string) $value;
    }
}
