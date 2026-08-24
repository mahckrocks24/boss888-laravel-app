<?php

namespace App\Engines\Marketing\Support;

/**
 * EM-7 PHASE 12 — one recipient of one campaign, normalised.
 *
 * The platform grew two incompatible recipient representations:
 *
 *   MarketingService   a flat list of address strings, personalised at send
 *                      time by looking each address up in `leads`
 *   EmailBuilder/Job   objects carrying name, company, per-recipient template
 *                      variables and an A/B subject variant
 *
 * Neither is wrong; they serve different products. What was wrong is that each
 * had its own send loop, and that is how the identical fabricated-success defect
 * came to be written three times. This type is what both shapes normalise into,
 * so there is one loop from here on.
 *
 * $html is PRE-RENDERED for the body_html shape, where personalisation is a
 * deterministic string substitution that needs no send-time state. It is null
 * for the templated shape, whose rendering injects an open pixel and rewrites
 * links against a per-recipient log row that does not exist until dispatch.
 * Pretending those two could share one rendering moment would have quietly
 * broken campaign tracking.
 */
final class CampaignRecipient
{
    public function __construct(
        public readonly string $address,
        public readonly string $name = '',
        public readonly string $company = '',
        /** @var array<string,mixed> per-recipient template variables */
        public readonly array $variables = [],
        /** Pre-rendered body, or null when rendering must happen at dispatch. */
        public readonly ?string $html = null,
        public readonly ?string $subjectVariant = null,
    ) {
    }

    public function firstName(): string
    {
        return $this->name !== '' ? (explode(' ', $this->name)[0] ?: '') : '';
    }

    /** Comparable form, for equivalence proofs between the two shapes. */
    public function toArray(): array
    {
        return [
            'address'         => $this->address,
            'name'            => $this->name,
            'company'         => $this->company,
            'variables'       => $this->variables,
            'html'            => $this->html,
            'subject_variant' => $this->subjectVariant,
        ];
    }
}
