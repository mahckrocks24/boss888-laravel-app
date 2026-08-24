<?php
/**
 * Route registration census — MISSION-018 WS-1 (2026-08-24), RISK-0005.
 *
 * Laravel's RouteCollection keys routes by method+URI, so a duplicate
 * registration silently OVERWRITES the earlier one: `route:list` and the
 * router itself are structurally incapable of showing shadowing after the
 * fact. This has produced real defects twice on this deployment — a stub
 * that starved the Studio assets surface (2026-08-13) and an unscoped
 * closure that shadowed the tenancy-checked task-retry route (RISK-0044).
 *
 * This script records every registration BEFORE the overwrite by swapping
 * the router for a recording subclass, then reports any (method, uri)
 * registered more than once, with the source (closure file:line or
 * controller@method) of each registration and which one serves.
 *
 * Usage:   php tools/route-registration-census.php
 * Exit:    0 = no duplicates; 1 = duplicates found (list on stdout).
 * CI: run on every change to routes/ or any file calling loadRoutesFrom.
 */

require __DIR__ . '/../vendor/autoload.php';

class RecordingRouter extends Illuminate\Routing\Router
{
    public static array $log = [];

    public function addRoute($methods, $uri, $action)
    {
        $route = parent::addRoute($methods, $uri, $action);
        $uses = $route->getAction('uses');
        if ($uses instanceof Closure) {
            $ref = new ReflectionFunction($uses);
            $src = str_replace(dirname(__DIR__) . '/', '', $ref->getFileName()) . ':' . $ref->getStartLine();
        } else {
            $src = is_string($uses) ? $uses : gettype($uses);
        }
        foreach ($route->methods() as $m) {
            if ($m === 'HEAD') {
                continue;
            }
            self::$log[] = [$m . ' ' . $route->uri(), $src];
        }
        return $route;
    }
}

$app = require __DIR__ . '/../bootstrap/app.php';
$app->extend('router', fn ($router, $app) => new RecordingRouter($app['events'], $app));
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$byKey = [];
foreach (RecordingRouter::$log as [$key, $src]) {
    $byKey[$key][] = $src;
}
$dups = array_filter($byKey, fn ($v) => count($v) > 1);

echo 'registrations: ' . count(RecordingRouter::$log)
    . '  distinct: ' . count($byKey)
    . '  duplicates: ' . count($dups) . "\n";

foreach ($dups as $key => $sources) {
    echo "\n" . $key . "\n";
    $last = count($sources) - 1;
    foreach ($sources as $i => $s) {
        echo '   ' . ($i === $last ? 'SERVES  ' : 'SHADOWED') . '  ' . $s . "\n";
    }
}

exit($dups === [] ? 0 : 1);
