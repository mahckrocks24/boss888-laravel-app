<?php

namespace App\Core\Engineering;

use Illuminate\Http\Request;

/**
 * ENTERPRISE888 E1 M0 — the shared list/filter contract.
 *
 * WHY THIS EXISTS
 * The admin controllers currently each invent their own listing behaviour —
 * `->limit((int) $r->input('limit', 100))` appears in four different files with
 * no shared bound, no sort validation and no record of what was applied. E1 adds
 * up to eight more list surfaces; without one contract that becomes twelve.
 *
 * It also carries an evidence obligation. Every Engineering screen must be able
 * to state what it actually queried — the blueprint requires each number to name
 * its source and the filter that produced it. `applied()` is that statement.
 *
 * DELIBERATELY NARROW: no query building, no database coupling. It validates and
 * describes intent. Each read service decides how to honour it.
 */
final class EngineeringListQuery
{
    public const MAX_LIMIT     = 500;
    public const DEFAULT_LIMIT = 100;

    /** Bounded date window, in days, for any range filter that is unindexed. */
    public const MAX_UNBOUNDED_DAYS = 90;

    private function __construct(
        public readonly int $limit,
        public readonly int $offset,
        public readonly ?string $sort,
        public readonly string $direction,
        public readonly array $filters,
        public readonly ?string $search,
        public readonly array $rejected,
    ) {}

    /**
     * Build from a request.
     *
     * @param string[] $allowedSorts   sortable columns, empty = sorting unsupported
     * @param string[] $allowedFilters filter keys this surface understands
     */
    public static function fromRequest(
        Request $request,
        array $allowedSorts = [],
        array $allowedFilters = [],
    ): self {
        $rejected = [];

        // ── limit ────────────────────────────────────────────────────────────
        $limit = (int) $request->input('limit', self::DEFAULT_LIMIT);
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }
        if ($limit > self::MAX_LIMIT) {
            $rejected[] = "limit {$limit} exceeds maximum " . self::MAX_LIMIT;
            $limit = self::MAX_LIMIT;
        }

        $offset = max(0, (int) $request->input('offset', 0));

        // ── sort — an unsupported column is REJECTED, never silently ignored ──
        $sort = $request->input('sort');
        if ($sort !== null && !in_array($sort, $allowedSorts, true)) {
            $rejected[] = "sort '{$sort}' is not supported here";
            $sort = null;
        }

        $direction = strtolower((string) $request->input('direction', 'desc'));
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $rejected[] = "direction '{$direction}' is not valid";
            $direction = 'desc';
        }

        // ── filters ──────────────────────────────────────────────────────────
        $filters = [];
        foreach ((array) $request->input('filter', []) as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (!in_array($key, $allowedFilters, true)) {
                $rejected[] = "filter '{$key}' is not supported here";
                continue;
            }
            if ($value === '' || $value === null) {
                continue;
            }
            $filters[$key] = is_scalar($value) ? (string) $value : null;
            if ($filters[$key] === null) {
                unset($filters[$key]);
                $rejected[] = "filter '{$key}' must be a scalar";
            }
        }

        $search = $request->input('search');
        $search = is_string($search) && trim($search) !== '' ? trim($search) : null;

        return new self($limit, $offset, $sort, $direction, $filters, $search, $rejected);
    }

    /** An empty query — used where a surface takes no parameters. */
    public static function none(): self
    {
        return new self(self::MAX_LIMIT, 0, null, 'desc', [], null, []);
    }

    /**
     * What was actually applied.
     *
     * This is evidence, not decoration: a screen that cannot say what it queried
     * cannot substantiate the numbers it shows.
     */
    public function applied(): array
    {
        return [
            'limit'     => $this->limit,
            'offset'    => $this->offset,
            'sort'      => $this->sort,
            'direction' => $this->direction,
            'filters'   => $this->filters,
            'search'    => $this->search,
            'rejected'  => $this->rejected,
        ];
    }

    public function hasFilter(string $key): bool
    {
        return array_key_exists($key, $this->filters);
    }

    public function filter(string $key, ?string $default = null): ?string
    {
        return $this->filters[$key] ?? $default;
    }
}
