<?php

namespace App\Core\Publisher;

/**
 * Facebook Page publishing. Single image or text/link post — nothing else.
 *
 * LAUNCH MEDIA BOUNDARY (deliberate, documented):
 *   - text + link      -> POST /{page-id}/feed
 *   - text + ONE image -> POST /{page-id}/photos
 *   - video, carousel, multi-photo, Stories, Reels: NOT IMPLEMENTED.
 * Video is absent because it needs its own upload session, processing-state
 * polling and separate tests. Shipping it untested would be the fastest way to
 * publish something broken to a customer Page.
 *
 * Requires `pages_manage_posts` on the Page token. That permission is NOT yet
 * granted on this Meta app (App Review pending), so this connector is exercised
 * only through MockTransport today.
 */
class FacebookPublisherConnector
{
    private const GRAPH = 'https://graph.facebook.com/v19.0';
    public const PLATFORM = 'facebook';

    public function __construct(private Transport $transport) {}

    /** The URL the last publish targeted — kept for dry-run evidence. */
    private ?string $lastEndpoint = null;

    /** Media types this connector will accept at launch. */
    public const SUPPORTED_MIME = ['image/jpeg', 'image/png'];
    public const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * @param array $post content, media[], canonical_url, idempotency_key, correlation_id
     * @param array $conn page_id, access_token
     * @return array normalized provider result
     */
    public function publish(array $post, array $conn): array
    {
        $pageId = (string) ($conn['page_id'] ?? '');
        $token  = (string) ($conn['access_token'] ?? '');
        $corr   = (string) ($post['correlation_id'] ?? '');

        if ($pageId === '' || $token === '') {
            return $this->result(false, null, MetaErrorMap::FAILED, 'MISSING_CONNECTION',
                'The Facebook connection is incomplete.', false, $corr);
        }

        $media   = array_values(array_filter((array) ($post['media'] ?? [])));
        $content = (string) ($post['content'] ?? '');

        $guard = $this->validateMedia($media);
        if ($guard !== null) {
            return $this->result(false, null, MetaErrorMap::FAILED, $guard['code'], $guard['message'], false, $corr);
        }

        if ($media === []) {
            $url     = self::GRAPH . "/{$pageId}/feed";
            $payload = array_filter([
                'message' => $content,
                'link'    => $post['canonical_url'] ?? null,
            ]);
        } else {
            $url     = self::GRAPH . "/{$pageId}/photos";
            $payload = array_filter([
                'caption' => $content,
                'url'     => $media[0]['url'] ?? null,
                'published' => 'true',
            ]);
        }

        $this->lastEndpoint = $url;
        $resp = $this->transport->post($url, $payload + ['access_token' => $token]);
        $c    = MetaErrorMap::classify($resp);

        if ($c['class'] === MetaErrorMap::OK) {
            $id = (string) ($resp['json']['post_id'] ?? $resp['json']['id'] ?? '');
            if ($id === '') {
                // A 200 with no id is not a success we can prove. Treat as
                // uncertain rather than claim a publication we cannot cite.
                return $this->result(false, null, MetaErrorMap::UNCERTAIN, 'NO_PROVIDER_ID',
                    'The provider returned success without an id.', false, $corr,
                    $resp['request_id'] ?? null);
            }
            return $this->result(true, $id, MetaErrorMap::OK, null, 'Published.', false, $corr,
                $resp['request_id'] ?? null);
        }

        return $this->result(false, null, $c['class'], $c['code'], $c['message'],
            $c['retryable'], $corr, $resp['request_id'] ?? null, $c['needs_reconnect']);
    }

    /**
     * Reconciliation for an uncertain outcome: did the post actually land?
     * Looks for a recent Page post whose message matches what we sent, so a
     * timed-out request is resolved by evidence rather than by a blind retry.
     */
    public function reconcile(array $post, array $conn): array
    {
        $pageId = (string) ($conn['page_id'] ?? '');
        $token  = (string) ($conn['access_token'] ?? '');
        $corr   = (string) ($post['correlation_id'] ?? '');
        $needle = trim((string) ($post['content'] ?? ''));

        $resp = $this->transport->get(self::GRAPH . "/{$pageId}/posts",
            ['fields' => 'id,message,created_time', 'limit' => 25, 'access_token' => $token]);

        $c = MetaErrorMap::classify($resp);
        if ($c['class'] !== MetaErrorMap::OK) {
            return ['resolved' => false, 'found' => false, 'reason' => $c['code'] ?? 'LOOKUP_FAILED'];
        }

        foreach ((array) ($resp['json']['data'] ?? []) as $row) {
            $msg = trim((string) ($row['message'] ?? ''));
            if ($msg !== '' && $needle !== '' && $msg === $needle) {
                return ['resolved' => true, 'found' => true, 'provider_id' => (string) ($row['id'] ?? ''),
                        'published_at' => $row['created_time'] ?? null];
            }
        }
        return ['resolved' => true, 'found' => false];
    }

    private function validateMedia(array $media): ?array
    {
        if (count($media) > 1) {
            return ['code' => 'MULTI_MEDIA_NOT_SUPPORTED',
                    'message' => 'Only a single image is supported at launch.'];
        }
        foreach ($media as $m) {
            $mime = strtolower((string) ($m['mime'] ?? ''));
            if ($mime !== '' && !in_array($mime, self::SUPPORTED_MIME, true)) {
                return ['code' => 'UNSUPPORTED_MEDIA_TYPE',
                        'message' => 'Only JPEG and PNG images are supported at launch.'];
            }
            if (empty($m['url']) || !filter_var($m['url'], FILTER_VALIDATE_URL)) {
                return ['code' => 'MEDIA_URL_INVALID', 'message' => 'The image must have a public URL.'];
            }
            if (!empty($m['bytes']) && (int) $m['bytes'] > self::MAX_BYTES) {
                return ['code' => 'MEDIA_TOO_LARGE', 'message' => 'The image is larger than 8 MB.'];
            }
        }
        return null;
    }

    private function result(bool $ok, ?string $id, string $class, ?string $code, string $msg,
                            bool $retryable, string $corr, ?string $reqId = null, bool $reconnect = false): array
    {
        return [
            'success'          => $ok,
            'provider'         => self::PLATFORM,
            'account_id'       => null,
            'provider_post_id' => $id,
            'status_class'     => $class,
            'retryable'        => $retryable,
            'needs_reconnect'  => $reconnect,
            'error_code'       => $code,
            'message'          => MetaErrorMap::sanitize($msg),
            'correlation_id'   => $corr,
            'provider_request_id' => $reqId,
            'published_at'     => $ok ? now()->toIso8601String() : null,
            'endpoint'         => $this->lastEndpoint,
        ];
    }
}
