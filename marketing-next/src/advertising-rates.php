<?php
declare(strict_types=1);
/*
 * ADV-PAGE-2 rate card (RFC-0031). PROPOSED figures: the page prints a price only when `approved` is true, which is the
 * Owner's decision (RFC-0031 D1). Until then every package reads "rate card on request" and the media-plan builder
 * describes the plan without a price. Money in USD. CPM = per 1,000 VIEWABLE impressions (IAB/MRC), the only thing the
 * engine bills (AdBudgetService). CPC = per click on our click endpoint. Flat = a month of a placement set.
 */
return [
    'approved' => false,
    'currency' => 'USD',
    'cpm' => ['footer_sticky' => 4.00, 'in_content_mrec' => 8.00, 'interstitial_modal' => 18.00],
    'cpc' => ['footer_sticky' => 0.60, 'in_content_mrec' => 0.60],
    'flat' => ['local' => 149.00, 'area' => 490.00, 'industry' => 990.00],
    'creative_set' => 99.00,
    'minimum' => 150.00,
];
