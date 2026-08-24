<?php

namespace App\Core\PlatformEvents;

use App\Console\Commands\PlatformEventsProcessCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Throwable;

/**
 * PRE-DEPLOYMENT VERIFICATION GATE for the platform event scheduled chain.
 *
 * ── WHY THIS EXISTS ──
 *
 * On 2026-07-30 at 10:20:05 the scheduled command died with
 *
 *     Call to undefined method App\Core\PlatformEvents\SubscriberRegistry::active()
 *
 * because a patch removed the method but left one call site behind. `php -l` passed
 * on every file: an undefined static method is not a syntax error, and neither is an
 * undefined class constant or an undefined property. Syntax validity says a file can
 * be parsed. It says nothing about whether the symbols it names exist.
 *
 * This gate answers the question `php -l` cannot: does every symbol the chain
 * actually names resolve at runtime? It does that by REFLECTION, not by grepping for
 * strings and not by trusting configuration that nothing ever loads.
 *
 * STRICTLY READ-ONLY. It records no event, fans out nothing, executes no subscriber,
 * updates no row, queues no job, and contacts no provider, registrar or payment API.
 * Its only side effect is a short-lived Redis probe lock on a key the chain never
 * uses, taken and released immediately to prove the lock backend is reachable.
 */
class ChainVerifier
{
    /** The classes whose source is scanned for unresolvable symbol references. */
    public const CHAIN_SOURCES = [
        'app/Core/PlatformEvents/Outbox.php',
        'app/Core/PlatformEvents/OutboxDispatcher.php',
        'app/Core/PlatformEvents/EventFanOut.php',
        'app/Core/PlatformEvents/DeliveryWorker.php',
        'app/Core/PlatformEvents/DeliveryProjection.php',
        'app/Core/PlatformEvents/EventReplay.php',
        'app/Core/PlatformEvents/EventSystemHealth.php',
        'app/Core/PlatformEvents/ChainVerifier.php',
        'app/Core/PlatformEvents/SubscriberRegistry.php',
        'app/Core/PlatformEvents/SubscriberDeclaration.php',
        'app/Core/PlatformEvents/PlatformEvent.php',
        'app/Core/PlatformEvents/Subscribers/AuditSubscriber.php',
        'app/Core/PlatformEvents/Types/DomainOrderCreated.php',
        'app/Core/PlatformEvents/Types/DomainOrderPaid.php',
        'app/Console/Commands/PlatformEventsProcessCommand.php',
        'app/Console/Commands/PlatformEventsVerifyCommand.php',
    ];

    /** A probe key the real chain never uses, so verification cannot block a run. */
    private const PROBE_LOCK = 'platform-events:verify-probe';

    /** Where the last successfully verified fingerprint is remembered. */
    public const VERIFIED_FINGERPRINT_KEY = 'platform-events:verified-fingerprint';

    /** @var array<int,array{name:string,ok:bool,detail:string}> */
    private array $checks = [];

    /**
     * A fingerprint of everything verification actually inspects.
     *
     * Source contents AND the structural configuration, because a release can
     * change the event-class map or the handler map without touching a single line
     * of PHP — and a fingerprint that missed that would let a broken configuration
     * through on the strength of an earlier pass.
     *
     * Deliberately not a timestamp or a version string: it changes exactly when
     * something verification cares about changes, and not otherwise.
     */
    public static function fingerprint(): string
    {
        $parts = [];

        foreach (self::CHAIN_SOURCES as $rel) {
            $path = base_path($rel);
            $parts[] = $rel . ':' . (is_file($path) ? hash_file('sha256', $path) : 'ABSENT');
        }

        // Structural config — the maps and declarations, not the on/off switches.
        // A flag flip is an operational act and must not force re-verification.
        $parts[] = 'event_classes:' . json_encode((array) config('platform_events.event_classes', []));
        $parts[] = 'subscriber_handlers:' . json_encode((array) config('platform_events.subscriber_handlers', []));
        $parts[] = 'declared_subscribers:' . json_encode(array_keys(SubscriberRegistry::all()));

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @param array<string,string> $extraSources label => PHP source, scanned as if it
     *                                           were a chain file. Used by tests to
     *                                           prove a broken reference is caught.
     * @return array{passed:bool,checks:array,summary:array}
     */
    public function verify(array $extraSources = []): array
    {
        $this->checks = [];

        $this->checkCommandResolvable();
        $this->checkSchedulerRegistration();
        $this->checkSymbolReferences($extraSources);
        $this->checkDeletedApiAbsent($extraSources);
        $this->checkSubscriberHandlers();
        $this->checkEventClasses();
        $this->checkSubscriberSchemaAcceptance();
        $this->checkSubscriberStates();
        $this->checkFlagConfiguration();
        $this->checkWorkspaceAllowList();
        $this->checkTables();
        $this->checkLockBackend();
        $this->checkProjectionConsistency();

        $failed = array_values(array_filter($this->checks, static fn (array $c): bool => ! $c['ok']));

        return [
            'passed' => $failed === [],
            'checks' => $this->checks,
            'summary' => [
                'total' => count($this->checks),
                'failed' => count($failed),
                'failed_names' => array_column($failed, 'name'),
            ],
        ];
    }

    private function add(string $name, bool $ok, string $detail = ''): void
    {
        $this->checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }

    // ══════════════════════════════════════════════════ command + scheduler ══

    private function checkCommandResolvable(): void
    {
        try {
            $instance = app(PlatformEventsProcessCommand::class);
            $ok = $instance instanceof PlatformEventsProcessCommand;
            $this->add('command_resolvable', $ok, $ok ? PlatformEventsProcessCommand::class : 'container returned the wrong type');
        } catch (Throwable $e) {
            $this->add('command_resolvable', false, class_basename($e) . ': ' . $e->getMessage());

            return;
        }

        // The signature the scheduler names must actually be registered.
        try {
            $registered = array_keys(app(\Illuminate\Contracts\Console\Kernel::class)->all());
            $ok = in_array('platform-events:process', $registered, true);
            $this->add('command_registered', $ok, $ok ? 'platform-events:process' : 'not present in the artisan registry');
        } catch (Throwable $e) {
            $this->add('command_registered', false, class_basename($e) . ': ' . $e->getMessage());
        }
    }

    private function checkSchedulerRegistration(): void
    {
        try {
            $events = app(Schedule::class)->events();
        } catch (Throwable $e) {
            $this->add('scheduler_points_at_existing_command', false, class_basename($e) . ': ' . $e->getMessage());

            return;
        }

        if ($events === []) {
            // Outside a console command the schedule is legitimately empty; this is
            // undetermined, not a failure. Same rule as EventSystemHealth.
            $this->add('scheduler_points_at_existing_command', true,
                'undetermined from this context — run inside artisan to confirm');

            return;
        }

        $known = array_keys(app(\Illuminate\Contracts\Console\Kernel::class)->all());
        $ours = [];

        foreach ($events as $e) {
            if (str_contains((string) $e->command, 'platform-events')) {
                $ours[] = (string) $e->command;
            }
        }

        if ($ours === []) {
            $this->add('scheduler_points_at_existing_command', false,
                'no platform-events entry is registered in the schedule');

            return;
        }

        // Every scheduled platform-events entry must name a command that exists.
        $bad = [];

        foreach ($ours as $cmd) {
            $found = false;

            foreach ($known as $sig) {
                if (str_contains($cmd, $sig)) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $bad[] = $cmd;
            }
        }

        $this->add('scheduler_points_at_existing_command', $bad === [],
            $bad === [] ? implode(' | ', $ours) : 'scheduled but unknown: ' . implode(', ', $bad));
    }

    // ══════════════════════════════════════════════════════ symbol resolution ══

    /**
     * THE check that would have caught the 10:20:05 failure.
     *
     * Scans each chain source for `Something::member` references, resolves the short
     * class name through that file's own use-statements and namespace, and asserts by
     * reflection that the method or constant exists. Only App\ classes are inspected:
     * facades resolve through __callStatic and would produce noise, not signal.
     */
    private function checkSymbolReferences(array $extraSources): void
    {
        $problems = [];
        $scanned = 0;
        $refs = 0;

        foreach ($this->sources($extraSources, $problems) as $label => $src) {
            $scanned++;

            foreach ($this->staticReferences($src) as $ref) {
                [$fqcn, $member, $isCall] = $ref;

                if (! str_starts_with($fqcn, 'App\\')) {
                    continue;   // facades resolve via __callStatic; not our contract
                }

                $refs++;

                if (! class_exists($fqcn) && ! interface_exists($fqcn) && ! trait_exists($fqcn)) {
                    $problems[] = "{$label}: class {$fqcn} does not exist";
                    continue;
                }

                if ($isCall) {
                    if (! method_exists($fqcn, $member) && ! method_exists($fqcn, '__callStatic')) {
                        $problems[] = "{$label}: {$fqcn}::{$member}() does not exist";
                    }

                    continue;
                }

                if (! (new ReflectionClass($fqcn))->hasConstant($member)) {
                    $problems[] = "{$label}: constant {$fqcn}::{$member} does not exist";
                }
            }
        }

        $this->add('chain_symbols_resolve', $problems === [],
            $problems === []
                ? "{$refs} App-class references across {$scanned} source(s) all resolve"
                : implode(' ; ', array_slice($problems, 0, 8)));
    }

    /**
     * @param array<string,string> $extraSources
     * @param array<int,string>    $problems
     * @return array<string,string>
     */
    private function sources(array $extraSources, array &$problems): array
    {
        $sources = [];

        foreach (self::CHAIN_SOURCES as $rel) {
            $path = base_path($rel);

            if (! is_file($path)) {
                $problems[] = "{$rel}: chain source file is absent";
                continue;
            }

            $sources[$rel] = (string) file_get_contents($path);
        }

        foreach ($extraSources as $label => $src) {
            $sources[$label] = $src;
        }

        return $sources;
    }

    /**
     * Every real `Class::member` reference in a source, found by TOKENISING it.
     *
     * Tokenising rather than pattern-matching, because a regex cannot tell code from
     * prose. An earlier regex version of this check reported failures for class names
     * that appeared inside this file's own docblocks and inside string literals such
     * as the deleted-API needle — noise that would have trained an operator to ignore
     * the gate. token_get_all() yields comments and strings as their own token types,
     * so they are excluded by construction rather than by exception lists.
     *
     * @return array<int,array{0:string,1:string,2:bool}> [FQCN, member, isMethodCall]
     */
    private function staticReferences(string $src): array
    {
        $tokens = token_get_all($src);
        $aliases = [];
        $namespace = null;
        $out = [];

        // Pass 1: namespace and use-statements, so short names can be resolved.
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];

            if (! is_array($t)) {
                continue;
            }

            if ($t[0] === T_NAMESPACE) {
                $namespace = $this->readName($tokens, $i);
                continue;
            }

            if ($t[0] === T_USE) {
                // Only top-level imports; a closure's `use (...)` has no name token.
                $name = $this->readName($tokens, $i);

                if ($name === null || $name === '') {
                    continue;
                }

                $alias = null;

                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                        break;
                    }

                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_AS) {
                        $alias = $this->readName($tokens, $j);
                        break;
                    }
                }

                $short = $alias ?? substr($name, (int) strrpos('\\' . $name, '\\'));
                $aliases[ltrim($short, '\\')] = ltrim($name, '\\');
            }
        }

        // Pass 2: the references themselves.
        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || ! in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $next = $this->nextMeaningful($tokens, $i);

            if ($next === null || ! is_array($tokens[$next]) || $tokens[$next][0] !== T_DOUBLE_COLON) {
                continue;
            }

            $classToken = $tokens[$i];
            $memberIdx = $this->nextMeaningful($tokens, $next);

            if ($memberIdx === null || ! is_array($tokens[$memberIdx])) {
                continue;   // ::$staticProp or ::{expr} — not covered here
            }

            $member = $tokens[$memberIdx][1];

            if ($tokens[$memberIdx][0] === T_CLASS || $member === 'class') {
                continue;   // ::class needs only the class to exist, checked below anyway
            }

            if (in_array(strtolower($classToken[1]), ['self', 'static', 'parent'], true)) {
                continue;
            }

            $after = $this->nextMeaningful($tokens, $memberIdx);
            $isCall = $after !== null && $tokens[$after] === '(';

            $fqcn = $this->resolveToken($classToken, $aliases, $namespace);

            if ($fqcn === null) {
                continue;
            }

            $out[] = [$fqcn, $member, $isCall];
        }

        return $out;
    }

    /** Index of the next token that is not whitespace or a comment. */
    private function nextMeaningful(array $tokens, int $from): ?int
    {
        $count = count($tokens);

        for ($i = $from + 1; $i < $count; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** Read the name token following a namespace/use/as keyword. */
    private function readName(array $tokens, int $from): ?string
    {
        $idx = $this->nextMeaningful($tokens, $from);

        if ($idx === null || ! is_array($tokens[$idx])) {
            return null;
        }

        if (! in_array($tokens[$idx][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        return $tokens[$idx][1];
    }

    private function resolveToken(array $classToken, array $aliases, ?string $namespace): ?string
    {
        $raw = $classToken[1];

        // Fully qualified (written with a leading separator) — authoritative as-is.
        if ($classToken[0] === T_NAME_FULLY_QUALIFIED) {
            return ltrim($raw, '\\');
        }

        // Partially qualified — the first segment may itself be an alias.
        if ($classToken[0] === T_NAME_QUALIFIED) {
            $parts = explode('\\', $raw);
            $first = array_shift($parts);

            if (isset($aliases[$first])) {
                return $aliases[$first] . ($parts === [] ? '' : '\\' . implode('\\', $parts));
            }

            return ($namespace !== null ? $namespace . '\\' : '') . $raw;
        }

        // Bare name.
        if (isset($aliases[$raw])) {
            return $aliases[$raw];
        }

        if ($namespace !== null && class_exists($namespace . '\\' . $raw)) {
            return $namespace . '\\' . $raw;
        }

        // Global class (Throwable, ReflectionClass, ...). Not ours, so not reported;
        // returning it keeps the App\ filter as the single place that decides scope.
        if (class_exists($raw) || interface_exists($raw)) {
            return $raw;
        }

        // Unresolvable bare name in an App file: report it against this namespace.
        return $namespace !== null && str_starts_with($namespace, 'App\\')
            ? $namespace . '\\' . $raw
            : null;
    }

    /**
     * The API removed in Phase 1C.1 must not be referenced anywhere as a real call.
     *
     * Token-based, so a mention inside a string literal or a docblock — including the
     * needle in this method and the test that asserts its absence — is not a hit.
     * Only an actual SubscriberRegistry::active() call site counts.
     */
    private function checkDeletedApiAbsent(array $extraSources): void
    {
        $removed = [SubscriberRegistry::class => ['active']];
        $hits = [];

        $sources = [];

        foreach (['app', 'tests'] as $dir) {
            $base = base_path($dir);

            if (! is_dir($base)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));

            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $rel = str_replace(base_path() . '/', '', $file->getPathname());
                    $sources[$rel] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        foreach ($extraSources as $label => $src) {
            $sources[$label] = $src;
        }

        foreach ($sources as $label => $src) {
            foreach ($this->staticReferences($src) as [$fqcn, $member, $isCall]) {
                if (isset($removed[$fqcn]) && in_array($member, $removed[$fqcn], true)) {
                    $hits[] = "{$label} calls {$fqcn}::{$member}()";
                }
            }
        }

        $this->add('deleted_registry_api_unreferenced', $hits === [],
            $hits === []
                ? 'no call site references any removed SubscriberRegistry API'
                : implode(' ; ', array_slice($hits, 0, 6)));
    }

    // ══════════════════════════════════════════════════════════ configuration ══

    private function checkSubscriberHandlers(): void
    {
        $map = (array) config('platform_events.subscriber_handlers', []);

        if ($map === []) {
            $this->add('subscriber_handlers_configured', false, 'no subscriber handler map is configured');

            return;
        }

        $problems = [];

        foreach (SubscriberRegistry::all() as $key => $decl) {
            if (! isset($map[$key])) {
                $problems[] = "{$key}: declared but has no configured handler";
                continue;
            }

            [$class, $method] = array_pad((array) $map[$key], 2, null);

            if ($class === null || ! class_exists($class)) {
                $problems[] = "{$key}: handler class '" . ($class ?? 'null') . "' does not exist";
                continue;
            }

            if ($method === null || ! method_exists($class, $method)) {
                $problems[] = "{$key}: {$class}::" . ($method ?? 'null') . '() does not exist';
                continue;
            }

            if (! is_callable([$class, $method]) && ! (new ReflectionClass($class))->getMethod($method)->isPublic()) {
                $problems[] = "{$key}: {$class}::{$method}() is not callable";
            }
        }

        // A configured handler for an undeclared subscriber is also a defect.
        foreach (array_keys($map) as $key) {
            if (! SubscriberRegistry::has($key)) {
                $problems[] = "{$key}: handler configured but the subscriber is not declared";
            }
        }

        $this->add('subscriber_handlers_callable', $problems === [],
            $problems === [] ? implode(', ', array_keys($map)) : implode(' ; ', $problems));
    }

    private function checkEventClasses(): void
    {
        $map = (array) config('platform_events.event_classes', []);

        if ($map === []) {
            $this->add('event_classes_configured', false, 'no event class map is configured');

            return;
        }

        $problems = [];

        foreach ($map as $type => $class) {
            if (! is_string($class) || ! class_exists($class)) {
                $problems[] = "{$type}: class '" . (is_string($class) ? $class : gettype($class)) . "' does not exist";
                continue;
            }

            if (! is_subclass_of($class, PlatformEvent::class)) {
                $problems[] = "{$type}: {$class} does not extend " . PlatformEvent::class;
                continue;
            }

            if (! method_exists($class, 'type') || ! method_exists($class, 'schemaVersion')) {
                $problems[] = "{$type}: {$class} is missing type() or schemaVersion()";
                continue;
            }

            $declaredType = $class::type();

            if ($declaredType !== $type) {
                $problems[] = "{$type}: {$class}::type() returns '{$declaredType}' — the map key and the class disagree";
                continue;
            }

            if ($class::schemaVersion() < 1) {
                $problems[] = "{$type}: schemaVersion() must be >= 1";
            }
        }

        // Every producer flag must name a mapped event type.
        foreach (array_keys((array) config('platform_events.producers', [])) as $type) {
            if (! isset($map[$type])) {
                $problems[] = "{$type}: producer flag exists but no event class is mapped";
            }
        }

        $this->add('event_classes_valid', $problems === [],
            $problems === [] ? implode(', ', array_keys($map)) : implode(' ; ', $problems));
    }

    private function checkSubscriberSchemaAcceptance(): void
    {
        $map = (array) config('platform_events.event_classes', []);
        $problems = [];

        foreach (SubscriberRegistry::all() as $key => $decl) {
            foreach ($decl->accepts as $type => $versions) {
                foreach ($versions as $v) {
                    if (! is_int($v) || $v < 1) {
                        $problems[] = "{$key}: accepts {$type} version '" . var_export($v, true) . "' which is not a positive integer";
                    }
                }

                if (! isset($map[$type])) {
                    $problems[] = "{$key}: accepts '{$type}' but no event class is mapped for it";
                    continue;
                }

                $class = $map[$type];

                if (! class_exists($class) || ! method_exists($class, 'schemaVersion')) {
                    continue;   // already reported by checkEventClasses
                }

                $current = $class::schemaVersion();

                // A subscriber that does not accept the version currently being
                // produced would silently skip every new event.
                if (! in_array($current, $versions, true)) {
                    $problems[] = "{$key}: does not accept {$type} v{$current}, which is the version now produced";
                }
            }
        }

        $this->add('subscriber_schema_acceptance', $problems === [],
            $problems === [] ? 'every declaration accepts the version currently produced' : implode(' ; ', $problems));
    }

    private function checkSubscriberStates(): void
    {
        $required = ['STATE_ACTIVE', 'STATE_EXECUTION_PAUSED', 'STATE_DISABLED_FOR_FUTURE', 'STATE_RETIRED'];
        $ref = new ReflectionClass(SubscriberRegistry::class);
        $missing = array_values(array_filter($required, static fn (string $c): bool => ! $ref->hasConstant($c)));

        if ($missing !== []) {
            $this->add('subscriber_state_constants', false, 'missing: ' . implode(', ', $missing));

            return;
        }

        $values = array_map(static fn (string $c) => $ref->getConstant($c), $required);
        $distinct = count(array_unique($values)) === count($values);

        $this->add('subscriber_state_constants', $distinct,
            $distinct ? implode(', ', $values) : 'state constants are not distinct: ' . implode(', ', $values));

        // Every declared subscriber must report one of them.
        $bad = [];

        foreach (array_keys(SubscriberRegistry::all()) as $key) {
            try {
                $state = SubscriberRegistry::state($key);

                if (! in_array($state, $values, true)) {
                    $bad[] = "{$key} => '{$state}'";
                }
            } catch (Throwable $e) {
                $bad[] = "{$key} => " . class_basename($e);
            }
        }

        $this->add('subscriber_states_resolve', $bad === [],
            $bad === [] ? json_encode(SubscriberRegistry::states()) : implode(', ', $bad));
    }

    private function checkFlagConfiguration(): void
    {
        $producers = (array) config('platform_events.producers', []);
        $subscribers = (array) config('platform_events.subscribers', []);

        $problems = [];

        foreach (['platform_events.enabled', 'platform_events.fanout.enabled', 'platform_events.delivery_worker.enabled'] as $k) {
            if (! is_bool(config($k))) {
                $problems[] = "{$k} is not a boolean (" . gettype(config($k)) . ')';
            }
        }

        foreach ($producers as $type => $v) {
            if (! is_bool($v)) {
                $problems[] = "producer '{$type}' flag is not a boolean";
            }
        }

        foreach ($subscribers as $key => $v) {
            if (! is_bool($v)) {
                $problems[] = "subscriber '{$key}' flag is not a boolean";
            }

            if (! SubscriberRegistry::has($key)) {
                $problems[] = "subscriber flag '{$key}' names no declared subscriber";
            }
        }

        $this->add('flag_configuration_valid', $problems === [], $problems === []
            ? sprintf('foundation=%s fanout=%s delivery=%s producers=%s subscribers=%s',
                var_export(config('platform_events.enabled'), true),
                var_export(config('platform_events.fanout.enabled'), true),
                var_export(config('platform_events.delivery_worker.enabled'), true),
                json_encode($producers), json_encode($subscribers))
            : implode(' ; ', $problems));
    }

    private function checkWorkspaceAllowList(): void
    {
        $outbox = new Outbox();
        $allowed = $outbox->allowedWorkspaces();

        $problems = [];

        foreach ($allowed as $ws) {
            if (! is_int($ws) || $ws < 1) {
                $problems[] = 'allow-list contains a non-positive-integer entry';
            }
        }

        // Fail-closed is the whole point: an empty list must permit nothing.
        if ($allowed === [] && $outbox->workspaceAllowed(1)) {
            $problems[] = 'an empty allow-list permitted a workspace — the gate is not fail-closed';
        }

        $this->add('workspace_allowlist_valid', $problems === [],
            $problems === [] ? 'allow-list ' . json_encode($allowed) : implode(' ; ', $problems));
    }

    // ═══════════════════════════════════════════════════════════ infrastructure ══

    private function checkTables(): void
    {
        $required = ['platform_events', 'platform_event_deliveries', 'audit_logs', 'workspaces', 'users'];
        $absent = [];

        foreach ($required as $t) {
            try {
                if (! Schema::hasTable($t)) {
                    $absent[] = $t;
                }
            } catch (Throwable $e) {
                $this->add('tables_available', false, class_basename($e) . ': ' . $e->getMessage());

                return;
            }
        }

        $this->add('tables_available', $absent === [],
            $absent === [] ? implode(', ', $required) : 'absent: ' . implode(', ', $absent));
    }

    private function checkLockBackend(): void
    {
        try {
            // A DIFFERENT key from the processing lock, so verification can never
            // block or steal a real run. Acquired and released immediately.
            $lock = Cache::lock(self::PROBE_LOCK, 1);
            $got = $lock->get();

            if ($got) {
                $lock->release();
            }

            $this->add('lock_backend_available', $got,
                $got ? 'probe lock acquired and released' : 'probe lock could not be acquired');
        } catch (Throwable $e) {
            $this->add('lock_backend_available', false, class_basename($e) . ': ' . $e->getMessage());
        }
    }

    private function checkProjectionConsistency(): void
    {
        try {
            $audit = DeliveryProjection::audit();
        } catch (Throwable $e) {
            $this->add('delivery_count_projection_consistent', false, class_basename($e) . ': ' . $e->getMessage());

            return;
        }

        $ok = $audit['drifted'] === [];

        $this->add('delivery_count_projection_consistent', $ok, $ok
            ? "{$audit['checked']} event(s) match the delivery ledger"
            : count($audit['drifted']) . ' event(s) disagree with the ledger: '
              . implode(', ', array_map(
                  static fn (array $d): string => "#{$d['row_id']} projected {$d['projected']} vs ledger {$d['ledger']}",
                  array_slice($audit['drifted'], 0, 5)
              )));
    }

    // ═════════════════════════════════════════════════════════════════ parsing ══

}
