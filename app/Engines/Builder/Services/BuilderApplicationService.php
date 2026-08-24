<?php

namespace App\Engines\Builder\Services;

use App\Engines\Builder\Support\BuilderGenerationDTO;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * BUILDER888 P1-6 — the single orchestrator for website generation.
 *
 * Law 11: Arthur compiles intent into a BuilderGenerationDTO and hands it here.
 * This class coordinates; it never persists. Every write goes through
 * BuilderService, which remains the sole writer of Builder domain state.
 *
 * Deliberately NOT: a persistence layer, a renderer, an AI service, a Runtime
 * client, or a billing service.
 */
final class BuilderApplicationService
{
    public function __construct(
        private readonly BuilderService $builder,
    ) {}

    /**
     * Persist a validated generation document atomically.
     *
     * All-or-nothing: a site with no pages, or with only some of its pages, is
     * not a website. Any failure rolls the whole thing back so no orphan
     * website survives.
     *
     * The AI work that produced the DTO has already happened and cost money —
     * a persistence failure must never trigger regeneration. The caller keeps
     * the DTO and may retry persistence alone.
     *
     * @return array{website_id:int, workspace_id:int, page_ids:array<int,int>, status:string}
     * @throws Throwable
     */
    public function generateWebsite(BuilderGenerationDTO $dto): array
    {
        return DB::transaction(function () use ($dto) {
            $created = $this->builder->createWebsite($dto->workspaceId, $dto->websiteAttributes());

            // BUILDER888 P1-6-a — createWebsite() has a DUAL return contract: it
            // reports plan/entitlement refusals as a value (success=false,
            // limit_reached=true, error=<customer wording>), not an exception.
            // Treating that as "no id" turned an actionable business refusal into
            // "We couldn't save this website". Surface it truthfully instead.
            if (($created['success'] ?? null) === false) {
                throw new \App\Engines\Builder\Exceptions\BuilderRefusedException(
                    (string) ($created['error'] ?? 'This action is not available on your current plan.'),
                    (bool) ($created['limit_reached'] ?? false)
                );
            }

            $websiteId = (int) ($created['website_id'] ?? 0);
            if ($websiteId <= 0) {
                // Never let a half-made site escape the transaction.
                throw new \RuntimeException('BuilderApplicationService: createWebsite returned no id.');
            }

            $pageIds = [];
            foreach ($dto->pages as $page) {
                $result = $this->builder->createPage($websiteId, [
                    'title'       => $page['title'],
                    'slug'        => $page['slug'],
                    'type'        => $page['type'],
                    // Generation states the status its representation needs;
                    // createPage()'s default is unchanged for every other caller.
                    'status'      => $page['status'],
                    'is_homepage' => $page['is_homepage'],
                    'sections'    => $page['sections'],
                    'seo'         => $page['seo'],
                ]);

                $pageId = (int) ($result['page_id'] ?? $result['id'] ?? 0);
                if ($pageId <= 0) {
                    throw new \RuntimeException(
                        "BuilderApplicationService: createPage returned no id for '{$page['slug']}'."
                    );
                }
                $pageIds[] = $pageId;
            }

            if (count($pageIds) !== $dto->pageCount()) {
                throw new \RuntimeException('BuilderApplicationService: page count mismatch after persistence.');
            }

            return [
                'website_id'   => $websiteId,
                'workspace_id' => $dto->workspaceId,
                'page_ids'     => $pageIds,
                'status'       => 'draft',
            ];
        });
    }
}
