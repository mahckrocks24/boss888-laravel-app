<?php

namespace App\Core\Billing;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Collection;

class PlanService
{
    public function all(): Collection
    {
        return Plan::all();
    }

    public function findBySlug(string $slug): ?Plan
    {
        return Plan::where('slug', $slug)->first();
    }
}
