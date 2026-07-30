<?php

namespace App\Core\Engineer888\Signals;

/**
 * A signal source.
 *
 * Grouped by DATA SOURCE, not by metric — one class per place we have to go
 * looking. Git facts come from git, box facts come from the box. Splitting one
 * class per metric would produce twenty files that all shell out to the same
 * command.
 *
 * collect() must NEVER throw. A signal that cannot be read reports
 * available=false with a reason, because "we could not measure this" is a
 * legitimate and useful brief entry, whereas a crashed brief is not.
 */
interface Signal
{
    public function key(): string;

    public function label(): string;

    /** @return array<string,mixed> always includes 'available' => bool */
    public function collect(): array;
}
