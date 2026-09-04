<?php

namespace App\Engines\Publisher\Services;

use Illuminate\Support\Facades\Validator;

/**
 * PUBLISHER888 Unit 2 — explicit request validation per desk operation.
 * Returns null when valid, else ['success'=>false,'error'=>'VALIDATION','errors'=>{field:[msg]}].
 */
class DeskValidation
{
    // Regex rules MUST be arrays: Laravel splits pipe-delimited rule strings, and these patterns contain pipes.
    private const URL = ['nullable', 'string', 'max:2048', 'regex:#^(https?://|/|$)#i'];
    private const SLUG = ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];

    public static function rules(string $op): array
    {
        return match ($op) {
            'story.create', 'story.update' => [
                'title' => ($op === 'story.create' ? 'required|' : 'sometimes|') . 'string|min:2|max:255',
                'content' => 'nullable|string|max:400000', 'excerpt' => 'nullable|string|max:500',
                'type' => 'nullable|string|in:' . implode(',', DeskService::STORY_TYPES), 'section' => 'nullable|string|max:100', 'region' => 'nullable|string|max:8', 'slug' => self::SLUG,
                'author' => 'nullable|string|max:120', 'meta_title' => 'nullable|string|max:255', 'meta_description' => 'nullable|string|max:320',
                'featured_image_url' => self::URL, 'featured_image_alt' => 'nullable|string|max:255', 'image_caption' => 'nullable|string|max:200', 'image_credit' => 'nullable|string|max:200',
                'tags' => 'nullable|array|max:20', 'tags.*' => 'string|max:40', 'sources' => 'nullable|array|max:20', 'sources.*.label' => 'nullable|string|max:160', 'sources.*.url' => self::URL,
                'expected_updated_at' => 'nullable|string|max:40',
            ],
            'story.schedule' => ['at' => 'required|string|max:40'],
            'section.create', 'section.update' => ['name' => ($op === 'section.create' ? 'required|' : 'sometimes|') . 'string|min:2|max:100', 'slug' => self::SLUG],
            'commission' => ['title' => 'nullable|string|max:255', 'brief' => 'nullable|string|max:5000', 'section' => 'nullable|string|max:100', 'region' => 'nullable|string|max:8',
                'type' => 'nullable|string|in:' . implode(',', DeskService::STORY_TYPES), 'length' => 'nullable|integer|min:300|max:2500', 'tone' => 'nullable|string|max:200', 'audience' => 'nullable|string|max:200', 'keyword' => 'nullable|string|max:120'],
            'job.create', 'job.update' => [
                'title' => ($op === 'job.create' ? 'required|' : 'sometimes|') . 'string|min:2|max:255', 'company' => ($op === 'job.create' ? 'required|' : 'sometimes|') . 'string|min:1|max:255',
                'company_url' => self::URL, 'company_logo_url' => self::URL, 'category' => 'nullable|string|max:60', 'category_slug' => 'nullable|string|max:60', 'employment_type' => 'nullable|string|max:30',
                'city' => 'nullable|string|max:100', 'region' => 'nullable|string|max:100', 'country' => 'nullable|string|max:40', 'is_remote' => 'nullable|boolean',
                'salary_min' => 'nullable|integer|min:0|max:100000000', 'salary_max' => 'nullable|integer|min:0|max:100000000', 'salary_currency' => 'nullable|string|max:3', 'salary_period' => 'nullable|string|max:20', 'salary_text' => 'nullable|string|max:120',
                'summary' => 'nullable|string|max:300', 'description' => 'nullable|string|max:200000', 'requirements' => 'nullable', 'benefits' => 'nullable|array|max:30', 'benefits.*' => 'string|max:120',
                'apply_url' => self::URL, 'apply_email' => 'nullable|email|max:190', 'apply_instructions' => 'nullable|string|max:500', 'source_url' => self::URL, 'verification_source' => 'nullable|string|max:500',
                'expires_at' => 'nullable|date', 'is_featured' => 'nullable|boolean', 'expected_updated_at' => 'nullable|string|max:40',
            ],
            'job.status' => ['status' => 'required|string|in:draft,expired,archived'],
            'inbox.update' => ['status' => 'nullable|string|in:new,contacted,qualified,converted,lost', 'note' => 'nullable|string|max:2000'],
            'member.role' => ['role' => 'required|string|in:' . implode(',', DeskService::ROLES)],
            'member.invite' => ['email' => 'required|email|max:190', 'role' => 'nullable|string|in:' . implode(',', DeskService::ROLES)],
            default => [],
        };
    }

    public static function check(string $op, array $data): ?array
    {
        $rules = self::rules($op); if (!$rules) return null;
        $v = Validator::make($data, $rules);
        if ($v->fails()) return ['success' => false, 'error' => 'VALIDATION', 'message' => $v->errors()->first(), 'errors' => $v->errors()->toArray()];
        return null;
    }
}
