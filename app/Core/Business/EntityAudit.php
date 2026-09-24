<?php

namespace App\Core\Business;

/**
 * K10 (2026-09-25) — does this page publish the RIGHT business, or just a
 * well-formatted one?
 *
 * AeoAuditService scores twelve checks and every one of them is about the shape
 * of an answer: is there a TLDR near the top, are there question-style H2s, are
 * there lists, is the meta description the right length. A page could pass all
 * twelve while telling Google that 127.0.0.1 published it — which 8 articles
 * did, and 174 more named the platform.
 *
 * These checks are about the entity. They are deterministic, they read only the
 * HTML that was actually served plus the site's own canonical truth, and each
 * one is SKIPPED rather than failed when it does not apply: a news publisher is
 * not marked down for having no street address, and a page with no Article node
 * is not marked down for the publisher of an article it does not contain.
 *
 * The findings are recorded beside the twelve, not folded into them. The AEO
 * score is computed by the runtime from a weight matrix this session cannot
 * reach (DEC-0026), so changing what that score means is not a change this unit
 * is entitled to make. `entity_ok` says plainly whether anything failed.
 */
final class EntityAudit
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const SKIP = 'skip';

    public function __construct(private readonly SchemaComposer $composer)
    {
    }

    /**
     * @param  string  $html      the page as served
     * @param  string  $host      the canonical host this page should speak as
     * @param  ?string $industry  the business industry, for the expected type
     * @return array{entity_ok: bool, checks: array<string, array{status: string, detail: string}>}
     */
    public function run(string $html, string $host, ?string $industry = null): array
    {
        $nodes = $this->jsonLdNodes($html);
        $checks = [];

        if (! $nodes) {
            $checks['entity_present'] = $this->result(self::FAIL, 'No JSON-LD on the page, so no business entity is published at all.');

            return $this->summarise($checks);
        }
        $checks['entity_present'] = $this->result(self::PASS, count($nodes) . ' JSON-LD node(s) found.');

        $business = $this->businessNode($nodes);
        $article = $this->firstOfType($nodes, ['Article', 'BlogPosting', 'NewsArticle']);

        // 1. Is the type the most specific one the industry supports?
        if (! $business) {
            $checks['entity_type_specific'] = $this->result(self::SKIP, 'No Organization or LocalBusiness node on this page.');
        } else {
            $actual = (string) ($business['@type'] ?? '');
            $expected = $this->composer->typeFor($industry);
            if ($expected !== 'LocalBusiness' && $expected !== 'Organization' && $actual !== $expected) {
                $checks['entity_type_specific'] = $this->result(
                    self::FAIL,
                    "Published as {$actual}; this business is a {$expected}. A generic type tells an answer engine the business is the same kind of thing as every other."
                );
            } else {
                $checks['entity_type_specific'] = $this->result(self::PASS, "Published as {$actual}.");
            }
        }

        // 2. Can anything refer to the business?
        if (! $business) {
            $checks['entity_has_stable_id'] = $this->result(self::SKIP, 'No business node to identify.');
        } elseif (empty($business['@id'])) {
            $checks['entity_has_stable_id'] = $this->result(self::FAIL, 'The business node has no @id, so no other statement can point at it.');
        } else {
            $checks['entity_has_stable_id'] = $this->result(self::PASS, 'Business @id: ' . $business['@id']);
        }

        // 3. Does every url in the graph speak as this site?
        $foreign = $this->foreignHosts($nodes, $host);
        if (! $foreign) {
            $checks['entity_canonical_host'] = $this->result(self::PASS, "Every entity url is on {$host}.");
        } else {
            $checks['entity_canonical_host'] = $this->result(
                self::FAIL,
                'Entity urls name ' . implode(', ', array_slice($foreign, 0, 3)) . " instead of {$host}."
            );
        }

        // 4. Is the publisher this business, and is it a reference?
        if (! $article) {
            $checks['entity_publisher_correct'] = $this->result(self::SKIP, 'No Article node on this page.');
            $checks['entity_nodes_linked'] = $this->result(self::SKIP, 'No Article node to link.');
        } else {
            $publisher = $article['publisher'] ?? null;
            $publisherUrl = is_array($publisher) ? (string) ($publisher['url'] ?? $publisher['@id'] ?? '') : '';
            $publisherHost = $publisherUrl !== '' ? (parse_url($publisherUrl, PHP_URL_HOST) ?: '') : '';

            if ($publisher === null) {
                $checks['entity_publisher_correct'] = $this->result(self::FAIL, 'The article names no publisher.');
            } elseif ($publisherHost !== '' && strcasecmp($publisherHost, $host) !== 0) {
                $checks['entity_publisher_correct'] = $this->result(
                    self::FAIL,
                    "The article says {$publisherHost} published it. This site is {$host}."
                );
            } else {
                $checks['entity_publisher_correct'] = $this->result(self::PASS, 'The article names this site as publisher.');
            }

            $isReference = is_array($publisher) && isset($publisher['@id']) && ! isset($publisher['name']);
            $target = $isReference ? (string) $publisher['@id'] : '';
            $resolves = $target !== '' && $this->hasId($nodes, $target);

            if ($isReference && $resolves) {
                $checks['entity_nodes_linked'] = $this->result(self::PASS, 'Publisher is a reference to a node in the same graph.');
            } elseif ($isReference) {
                $checks['entity_nodes_linked'] = $this->result(self::FAIL, "Publisher points at {$target}, which is not in this graph.");
            } else {
                $checks['entity_nodes_linked'] = $this->result(
                    self::FAIL,
                    'The article re-declares the business as a separate anonymous node instead of referencing it.'
                );
            }
        }

        // 5. Can a machine reach this business? Only asked of a place.
        $type = (string) ($business['@type'] ?? '');
        if (! $business) {
            $checks['entity_contactable'] = $this->result(self::SKIP, 'No business node.');
        } elseif ($this->isNotAPlace($type)) {
            $checks['entity_contactable'] = $this->result(self::SKIP, "A {$type} is not a place; a postal address does not apply.");
        } elseif (! empty($business['telephone']) || ! empty($business['address'])) {
            $checks['entity_contactable'] = $this->result(self::PASS, 'A phone number or postal address is published.');
        } else {
            $checks['entity_contactable'] = $this->result(
                self::FAIL,
                'Neither a phone number nor a postal address is published, so no answer engine can say where this business is or how to reach it.'
            );
        }

        return $this->summarise($checks);
    }

    // ---- helpers ---------------------------------------------------------

    /** Every node in every ld+json block on the page, @graph flattened. */
    public function jsonLdNodes(string $html): array
    {
        if (! preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
            return [];
        }

        $nodes = [];
        foreach ($m[1] as $block) {
            $decoded = json_decode(trim($block), true);
            if (! is_array($decoded)) {
                continue;
            }
            $candidates = $decoded['@graph'] ?? (isset($decoded['@type']) ? [$decoded] : $decoded);
            foreach ((array) $candidates as $node) {
                if (is_array($node) && isset($node['@type'])) {
                    $nodes[] = $node;
                }
            }
        }

        return $nodes;
    }

    /**
     * Everything that is not the business itself. BlogPosting does not contain
     * the string "Article", which is exactly why this is an explicit list.
     */
    private const NOT_THE_BUSINESS = [
        'WebSite', 'WebPage', 'BreadcrumbList', 'FAQPage', 'Question', 'Answer',
        'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'Report',
        'Service', 'Offer', 'Product', 'ImageObject', 'Person', 'PostalAddress',
        'ListItem', 'SearchAction', 'CollectionPage', 'ItemList',
    ];

    private function businessNode(array $nodes): ?array
    {
        foreach ($nodes as $node) {
            $type = (string) ($node['@type'] ?? '');
            if ($type === '' || in_array($type, self::NOT_THE_BUSINESS, true)) {
                continue;
            }

            return $node;
        }

        return null;
    }

    private function firstOfType(array $nodes, array $types): ?array
    {
        foreach ($nodes as $node) {
            if (in_array((string) ($node['@type'] ?? ''), $types, true)) {
                return $node;
            }
        }

        return null;
    }

    private function hasId(array $nodes, string $id): bool
    {
        foreach ($nodes as $node) {
            if (($node['@id'] ?? null) === $id) {
                return true;
            }
        }

        return false;
    }

    /** Hosts named by entity urls that are not this site. */
    private function foreignHosts(array $nodes, string $host): array
    {
        $found = [];
        foreach ($nodes as $node) {
            foreach (['url', '@id'] as $key) {
                $value = $node[$key] ?? null;
                if (! is_string($value) || ! str_contains($value, '://')) {
                    continue;
                }
                $nodeHost = parse_url($value, PHP_URL_HOST) ?: '';
                if ($nodeHost !== '' && strcasecmp($nodeHost, $host) !== 0) {
                    $found[$nodeHost] = true;
                }
            }
        }

        return array_keys($found);
    }

    private function isNotAPlace(string $type): bool
    {
        return in_array($type, ['Organization', 'NewsMediaOrganization', 'NGO', 'EducationalOrganization', 'ProfessionalService'], true);
    }

    private function result(string $status, string $detail): array
    {
        return ['status' => $status, 'detail' => $detail];
    }

    private function summarise(array $checks): array
    {
        $failed = array_keys(array_filter($checks, fn ($c) => $c['status'] === self::FAIL));

        return [
            'entity_ok' => $failed === [],
            'entity_failed' => $failed,
            'checks' => $checks,
        ];
    }
}
