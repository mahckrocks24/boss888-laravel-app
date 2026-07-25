<?php

namespace App\Connectors;

use App\Core\Distribution\PlatformPolicy;

/**
 * Builds the EXACT provider request a publish would send, validates it, and
 * returns it WITHOUT TRANSMITTING ANYTHING.
 *
 * There is no HTTP client in this class. Not a disabled one, not a guarded one —
 * none. That is the safety property, and it is verifiable by inspection.
 *
 * WHY IT STILL EXISTS AFTER THE PUBLISHER SCOPE CHANGE
 * ----------------------------------------------------
 * The legacy SocialConnector never published either: its private createPost /
 * publishPost POST to {SOCIAL_CONNECTOR_URL}/api/social/posts — an external
 * microservice that was never built, with the env var empty. There has never
 * been a Graph API call in this product. Real Facebook/Instagram transmission is
 * the next increment; until it lands, the Publisher terminates here so a post can
 * never be reported as published when it was not.
 *
 * The payload this class produces is the contract that transmission work must
 * satisfy.
 */
class DryRunSocialConnector
{
    /**
     * @param array $share platform, content, [canonical_url], [media], article_id|null,
     *                     idempotency_key, social_account_id, [intent]
     * @return array{ok:bool, transmitted:false, endpoint:?string, method:?string,
     *               payload:array, violations:array, reason:?string}
     */
    public function buildPayload(array $share, ?object $account = null): array
    {
        $platform = PlatformPolicy::normalise((string) ($share['platform'] ?? ''));
        $content  = (string) ($share['content'] ?? '');
        $url      = (string) ($share['canonical_url'] ?? '');
        $media    = is_array($share['media'] ?? null) ? $share['media'] : [];
        $intent   = (string) ($share['intent'] ?? PlatformPolicy::INTENT_MANUAL_PUBLISH);

        $support = PlatformPolicy::check($platform, $intent);
        if (!$support['ok']) return $this->refuse($support['reason']);

        $violations = [];

        if (trim($content) === '') $violations[] = 'EMPTY_CONTENT';

        // An article share exists to deliver the link; a free post does not.
        if ($intent === PlatformPolicy::INTENT_ARTICLE_SHARE) {
            if ($url === '' || !str_contains($content, $url)) {
                $violations[] = 'CANONICAL_URL_MISSING_FROM_CONTENT';
            }
            if (empty($share['article_id'])) $violations[] = 'MISSING_ARTICLE_ID';
        }

        $max = PlatformPolicy::maxLength($platform);
        if (strlen($content) > $max) $violations[] = "CONTENT_EXCEEDS_LIMIT_{$max}";

        if (PlatformPolicy::requiresMedia($platform) && $media === []) {
            $violations[] = strtoupper($platform) . '_REQUIRES_MEDIA';
        }
        if (count($media) > PlatformPolicy::maxMedia($platform)) {
            $violations[] = strtoupper($platform) . '_TOO_MANY_MEDIA';
        }
        foreach ($media as $m) {
            if (empty($m['url'])) { $violations[] = 'MEDIA_ITEM_HAS_NO_URL'; break; }
        }

        if (empty($share['idempotency_key'])) $violations[] = 'MISSING_IDEMPOTENCY_KEY';

        $externalId = $account->account_id ?? null;
        if (empty($externalId)) $violations[] = 'ACCOUNT_HAS_NO_PROVIDER_ID';

        $built = $this->shape($platform, $content, $url, (string) $externalId, $media);

        return [
            'ok'          => $violations === [],
            'transmitted' => false,
            'endpoint'    => $built['endpoint'],
            'method'      => 'POST',
            'payload'     => $built['payload'],
            'violations'  => $violations,
            'reason'      => $violations === [] ? null : 'PAYLOAD_INVALID',
        ];
    }

    /**
     * Provider-shaped request bodies. Credentials are NEVER included — the access
     * token is attached at transmission, which is exactly the step this class does
     * not perform. Nothing here is secret, so the whole structure is safe to
     * persist as audit evidence.
     */
    private function shape(string $platform, string $content, string $url, string $externalId, array $media): array
    {
        return match ($platform) {
            'facebook' => [
                // Photo posts use a different edge than link/text posts.
                'endpoint' => $media === []
                    ? "https://graph.facebook.com/v19.0/{$externalId}/feed"
                    : "https://graph.facebook.com/v19.0/{$externalId}/photos",
                'payload'  => $media === []
                    ? array_filter(['message' => $content, 'link' => $url ?: null])
                    : array_filter([
                        'caption' => $content,
                        'url'     => $media[0]['url'] ?? null,
                        'link'    => $url ?: null,
                    ]),
            ],
            // Instagram is a two-step publish: create a media container, then
            // publish it. Step 1 is modelled here; step 2 needs the container id
            // returned by the provider, which only exists once we transmit.
            'instagram' => [
                'endpoint' => "https://graph.facebook.com/v19.0/{$externalId}/media",
                'payload'  => array_filter([
                    'caption'   => $content,
                    'image_url' => $media[0]['url'] ?? null,
                    '_step2'    => "POST /v19.0/{$externalId}/media_publish {creation_id}",
                ]),
            ],
            'linkedin' => [
                'endpoint' => 'https://api.linkedin.com/v2/ugcPosts',
                'payload'  => [
                    'author'          => "urn:li:organization:{$externalId}",
                    'lifecycleState'  => 'PUBLISHED',
                    'specificContent' => [
                        'com.linkedin.ugc.ShareContent' => [
                            'shareCommentary'    => ['text' => $content],
                            'shareMediaCategory' => $url !== '' ? 'ARTICLE' : ($media === [] ? 'NONE' : 'IMAGE'),
                            'media'              => $url !== ''
                                ? [['status' => 'READY', 'originalUrl' => $url]]
                                : [],
                        ],
                    ],
                    'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
                ],
            ],
            default => ['endpoint' => null, 'payload' => []],
        };
    }

    private function refuse(string $reason): array
    {
        return ['ok' => false, 'transmitted' => false, 'endpoint' => null, 'method' => null,
                'payload' => [], 'violations' => [$reason], 'reason' => $reason];
    }
}
