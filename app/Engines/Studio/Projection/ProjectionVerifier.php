<?php

namespace App\Engines\Studio\Projection;

/**
 * STUDIO888 · Projection — abstract projection verifier.
 *
 * Compares the DESIRED after-state (from the request) with the ACTUAL after-state
 * (read back by the renderer adapter). Acceptance is never assumed: only fields
 * whose actual value matches the desired value are considered verified. This is
 * the guard against a frontend fabricating success.
 */
final class ProjectionVerifier
{
    /**
     * @return array{verified:bool, matched:string[], mismatched:string[], total:int}
     */
    public function verify(ProjectionRequest $request, ProjectionResult $result): array
    {
        $matched = [];
        $mismatched = [];

        foreach ($request->changedFields as $path) {
            $desired = $request->desiredAfterState[$path] ?? null;
            $actual = $result->actualAfterState[$path] ?? null;
            if ($this->equals($desired, $actual)) {
                $matched[] = $path;
            } else {
                $mismatched[] = $path;
            }
        }

        return [
            'verified'   => $mismatched === [] && $matched !== [],
            'matched'    => $matched,
            'mismatched' => $mismatched,
            'total'      => count($request->changedFields),
        ];
    }

    private function equals(mixed $a, mixed $b): bool
    {
        if (is_float($a) || is_float($b)) {
            return is_numeric($a) && is_numeric($b) && abs((float) $a - (float) $b) < 1e-9;
        }

        return $a === $b;
    }
}
