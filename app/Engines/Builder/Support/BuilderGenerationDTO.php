<?php

namespace App\Engines\Builder\Support;

use InvalidArgumentException;

/**
 * BUILDER888 P1-6 — the canonical output of Arthur-as-compiler.
 *
 * Provider-neutral by construction: nothing DeepSeek-, OpenAI- or MiniMax-shaped
 * may enter, because every template variable must already have passed through
 * GenerationVariableContract. Validated before a single row is written, so an
 * invalid document can never produce a partial site.
 *
 * Deliberately knows nothing about databases, credits, approvals or providers.
 */
final class BuilderGenerationDTO
{
    /** Page statuses the Builder domain accepts at creation time. */
    public const PAGE_STATUSES = ['draft', 'published'];

    /**
     * Site types the domain accepts. `template` remains valid: P1-6 converges
     * PERSISTENCE only. Representation convergence (template_variables vs
     * sections_json) is a separate, later milestone — see the audit's
     * "Representation Convergence" finding.
     */
    public const SITE_TYPES = ['template', 'builder', 'website'];

    private function __construct(
        public readonly int $workspaceId,
        public readonly ?int $createdBy,
        public readonly string $name,
        public readonly string $type,
        public readonly ?string $templateIndustry,
        public readonly array $templateVariables,
        public readonly array $seo,
        public readonly array $settings,
        public readonly array $pages,
        public readonly array $generationMeta,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @throws InvalidArgumentException when the document cannot be persisted safely
     */
    public static function fromArray(array $input): self
    {
        $ws = (int) ($input['workspace_id'] ?? 0);
        if ($ws <= 0) {
            throw new InvalidArgumentException('BuilderGenerationDTO: workspace_id is required.');
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('BuilderGenerationDTO: name is required.');
        }

        $type = (string) ($input['type'] ?? 'template');
        if (! in_array($type, self::SITE_TYPES, true)) {
            throw new InvalidArgumentException("BuilderGenerationDTO: unsupported site type '{$type}'.");
        }

        // ── template variables ──────────────────────────────────────────────
        // Must already be a flat scalar map. Anything structured is a symptom
        // of raw provider output bypassing GenerationVariableContract, which is
        // exactly the P1-8 failure — refuse rather than persist it.
        $vars = $input['template_variables'] ?? [];
        if (! is_array($vars)) {
            throw new InvalidArgumentException('BuilderGenerationDTO: template_variables must be an array.');
        }
        foreach ($vars as $k => $v) {
            if (! is_string($k) || $k === '') {
                throw new InvalidArgumentException('BuilderGenerationDTO: template variable keys must be non-empty strings.');
            }
            if (! is_string($v)) {
                throw new InvalidArgumentException(
                    "BuilderGenerationDTO: template variable '{$k}' is " . gettype($v)
                    . '; only contract-normalised strings may be persisted.'
                );
            }
        }

        // ── pages ───────────────────────────────────────────────────────────
        $pagesIn = $input['pages'] ?? [];
        if (! is_array($pagesIn) || $pagesIn === []) {
            throw new InvalidArgumentException('BuilderGenerationDTO: at least one page is required.');
        }

        $pages = [];
        $slugs = [];
        foreach (array_values($pagesIn) as $i => $p) {
            if (! is_array($p)) {
                throw new InvalidArgumentException("BuilderGenerationDTO: page #{$i} is not an array.");
            }

            $slug = trim((string) ($p['slug'] ?? ''));
            if ($slug === '' || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                throw new InvalidArgumentException("BuilderGenerationDTO: page #{$i} has an invalid slug '{$slug}'.");
            }
            if (isset($slugs[$slug])) {
                throw new InvalidArgumentException("BuilderGenerationDTO: duplicate page slug '{$slug}'.");
            }
            $slugs[$slug] = true;

            // Status is domain data, not a free string. Generated pages must be
            // able to state the status their representation needs to render.
            $status = (string) ($p['status'] ?? 'draft');
            if (! in_array($status, self::PAGE_STATUSES, true)) {
                throw new InvalidArgumentException(
                    "BuilderGenerationDTO: page '{$slug}' has unsupported status '{$status}'."
                );
            }

            $sections = $p['sections'] ?? [];
            if (! is_array($sections)) {
                throw new InvalidArgumentException("BuilderGenerationDTO: page '{$slug}' sections must be an array.");
            }

            $pages[] = [
                'title'       => trim((string) ($p['title'] ?? ucfirst($slug))),
                'slug'        => $slug,
                'type'        => (string) ($p['type'] ?? 'page'),
                'status'      => $status,
                'is_homepage' => (bool) ($p['is_homepage'] ?? false),
                'position'    => (int) ($p['position'] ?? $i),
                'sections'    => $sections,
                'seo'         => is_array($p['seo'] ?? null) ? $p['seo'] : [],
            ];
        }

        $homepages = array_filter($pages, fn ($p) => $p['is_homepage']);
        if (count($homepages) > 1) {
            throw new InvalidArgumentException('BuilderGenerationDTO: more than one homepage.');
        }

        return new self(
            workspaceId:       $ws,
            createdBy:         isset($input['created_by']) ? (int) $input['created_by'] : null,
            name:              $name,
            type:              $type,
            templateIndustry:  isset($input['template_industry']) ? (string) $input['template_industry'] : null,
            templateVariables: $vars,
            seo:               is_array($input['seo'] ?? null) ? $input['seo'] : [],
            settings:          is_array($input['settings'] ?? null) ? $input['settings'] : [],
            pages:             $pages,
            generationMeta:    is_array($input['generation_meta'] ?? null) ? $input['generation_meta'] : [],
        );
    }

    /** Shape consumed by BuilderService::createWebsite(). */
    public function websiteAttributes(): array
    {
        return [
            'name'               => $this->name,
            'type'               => $this->type,
            'user_id'            => $this->createdBy,
            'seo'                => $this->seo,
            'settings'           => $this->settings,
            'template_industry'  => $this->templateIndustry,
            'template_variables' => $this->templateVariables,
        ];
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }
}
