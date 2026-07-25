<?php

namespace Tests\Feature\Queue;

use Tests\TestCase;

/**
 * STUDIO888 MRC-2A — FIX 1 regression: the redis queue `retry_after` must exceed
 * the longest legitimate job timeout, otherwise a still-running long job is
 * re-delivered to a second worker and executed twice.
 *
 * Laravel invariant: retry_after > max job $timeout (with margin).
 */
class RetryAfterSafetyTest extends TestCase
{
    /** @test */
    public function redis_retry_after_exceeds_the_longest_job_timeout()
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        $maxTimeout = 0;
        $longest = '';
        foreach (glob(base_path('app/Jobs/*.php')) as $file) {
            $class = 'App\\Jobs\\' . basename($file, '.php');
            if (! class_exists($class)) {
                continue;
            }
            $timeout = (int) ((new \ReflectionClass($class))->getDefaultProperties()['timeout'] ?? 0);
            if ($timeout > $maxTimeout) {
                $maxTimeout = $timeout;
                $longest = $class;
            }
        }

        $this->assertGreaterThan(
            $maxTimeout,
            $retryAfter,
            "retry_after ({$retryAfter}s) must exceed the longest job timeout ({$maxTimeout}s from {$longest}) "
            . "to prevent duplicate execution of a still-running job."
        );
    }
}
