<?php

namespace App\Core\Publisher;

/**
 * Instagram publishing — the CONTAINER workflow, not a Facebook feed post.
 *
 * Instagram is genuinely two calls, and treating it like Facebook is the classic
 * way to ship something that silently never posts:
 *
 *   1. POST /{ig-user-id}/media          -> returns a creation_id (container)
 *   2. (optionally) GET /{container-id}?fields=status_code  until FINISHED
 *   3. POST /{ig-user-id}/media_publish  {creation_id}  -> returns the media id
 *
 * Step 2 matters because a container can be IN_PROGRESS, and publishing an
 * unfinished container fails. Containers also EXPIRE (~24h), which is a distinct
 * permanent failure rather than something to retry forever.
 *
 * LAUNCH MEDIA BOUNDARY: single JPEG/PNG image feed post. No carousel, no
 * Stories, no Reels, no video — each needs its own container parameters and
 * processing semantics, and none of that is tested.
 *
 * Requires `instagram_basic` + `instagram_content_publish` and an Instagram
 * professional account linked to a Facebook Page. Neither permission is granted
 * on this Meta app yet, so this connector runs only through MockTransport today.
 */
class InstagramPublisherConnector
{
    private const GRAPH = 'https://graph.facebook.com/v19.0';
    public const PLATFORM = 'instagram';

    public const SUPPORTED_MIME = ['image/jpeg', 'image/png'];
    public const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_STATUS_POLLS = 5;

    public function __construct(private Transport $transport) {}

    /** The URL the last publish step targeted — kept for dry-run evidence. */
    private ?string $lastEndpoint = null;

    public function publish(array $post, array $conn): array
    {
        $igId  = (string) ($conn['ig_user_id'] ?? '');
        $token = (string) ($conn['access_token'] ?? '');
        $corr  = (string) ($post['correlation_id'] ?? '');

        if ($igId === '' || $token === '') {
            return $this->result(false, null, MetaErrorMap::FAILED, 'MISSING_CONNECTION',
                'The Instagram connection is incomplete.', false, $corr);
        }
        if (empty($conn['page_id'])) {
            // IG publishing is only possible through a linked Page.
            return $this->result(false, null, MetaErrorMap::FAILED, 'IG_NOT_LINKED_TO_PAGE',
                'This Instagram account is not linked to a Facebook Page.', false, $corr);
        }

        $media   = array_values(array_filter((array) ($post['media'] ?? [])));
        $content = (string) ($post['content'] ?? '');

        $guard = $this->validateMedia($media);
        if ($guard !== null) {
            return $this->result(false, null, MetaErrorMap::FAILED, $guard['code'], $guard['message'], false, $corr);
        }

        // ── Step 1: create the container.
        $this->lastEndpoint = self::GRAPH . "/{$igId}/media";
        $create = $this->transport->post(self::GRAPH . "/{$igId}/media", [
            'image_url'    => $media[0]['url'],
            'caption'      => $content,
            'access_token' => $token,
        ]);
        $c1 = MetaErrorMap::classify($create);

        if ($c1['class'] !== MetaErrorMap::OK) {
            // A failed container creation published nothing — safe to surface as-is.
            return $this->result(false, null, $c1['class'], $c1['code'] ?? 'CONTAINER_CREATE_FAILED',
                $c1['message'], $c1['retryable'], $corr, $create['request_id'] ?? null, $c1['needs_reconnect']);
        }

        $containerId = (string) ($create['json']['id'] ?? '');
        if ($containerId === '') {
            return $this->result(false, null, MetaErrorMap::FAILED, 'NO_CONTAINER_ID',
                'The provider did not return a media container.', false, $corr, $create['request_id'] ?? null);
        }

        // ── Step 2: readiness. An unfinished container cannot be published.
        $ready = $this->awaitContainer($containerId, $token);
        if (!$ready['ok']) {
            return $this->result(false, null, $ready['class'], $ready['code'], $ready['message'],
                $ready['retryable'], $corr, null);
        }

        // ── Step 3: publish. From here a timeout is genuinely uncertain: the
        //    media may exist. That MUST go to reconciliation, never a retry.
        $pub = $this->transport->post(self::GRAPH . "/{$igId}/media_publish", [
            'creation_id'  => $containerId,
            'access_token' => $token,
        ]);
        $c2 = MetaErrorMap::classify($pub);

        if ($c2['class'] === MetaErrorMap::OK) {
            $mediaId = (string) ($pub['json']['id'] ?? '');
            if ($mediaId === '') {
                return $this->result(false, null, MetaErrorMap::UNCERTAIN, 'NO_MEDIA_ID',
                    'The provider returned success without a media id.', false, $corr,
                    $pub['request_id'] ?? null, false, $containerId);
            }
            return $this->result(true, $mediaId, MetaErrorMap::OK, null, 'Published.', false, $corr,
                $pub['request_id'] ?? null, false, $containerId);
        }

        return $this->result(false, null, $c2['class'], $c2['code'], $c2['message'],
            $c2['retryable'], $corr, $pub['request_id'] ?? null, $c2['needs_reconnect'], $containerId);
    }

    /** @return array{ok:bool, class?:string, code?:string, message?:string, retryable?:bool} */
    private function awaitContainer(string $containerId, string $token): array
    {
        for ($i = 0; $i < self::MAX_STATUS_POLLS; $i++) {
            $r = $this->transport->get(self::GRAPH . "/{$containerId}",
                ['fields' => 'status_code,status', 'access_token' => $token]);
            $c = MetaErrorMap::classify($r);

            if ($c['class'] !== MetaErrorMap::OK) {
                return ['ok' => false, 'class' => $c['class'], 'code' => $c['code'] ?? 'CONTAINER_STATUS_FAILED',
                        'message' => $c['message'], 'retryable' => $c['retryable']];
            }

            $status = strtoupper((string) ($r['json']['status_code'] ?? 'FINISHED'));
            if ($status === 'FINISHED')   return ['ok' => true];
            if ($status === 'ERROR')      return ['ok' => false, 'class' => MetaErrorMap::FAILED,
                                                  'code' => 'CONTAINER_ERROR',
                                                  'message' => 'The provider could not process this image.',
                                                  'retryable' => false];
            if ($status === 'EXPIRED')    return ['ok' => false, 'class' => MetaErrorMap::FAILED,
                                                  'code' => 'CONTAINER_EXPIRED',
                                                  'message' => 'The upload expired before publishing.',
                                                  'retryable' => false];
            // IN_PROGRESS / PUBLISHED fall through to another poll.
        }

        return ['ok' => false, 'class' => MetaErrorMap::RETRYABLE, 'code' => 'CONTAINER_NOT_READY',
                'message' => 'The image is still being processed.', 'retryable' => true];
    }

    /** Did the media actually land? Used only to resolve an uncertain outcome. */
    public function reconcile(array $post, array $conn): array
    {
        $igId  = (string) ($conn['ig_user_id'] ?? '');
        $token = (string) ($conn['access_token'] ?? '');
        $needle = trim((string) ($post['content'] ?? ''));

        $r = $this->transport->get(self::GRAPH . "/{$igId}/media",
            ['fields' => 'id,caption,timestamp', 'limit' => 25, 'access_token' => $token]);
        $c = MetaErrorMap::classify($r);
        if ($c['class'] !== MetaErrorMap::OK) {
            return ['resolved' => false, 'found' => false, 'reason' => $c['code'] ?? 'LOOKUP_FAILED'];
        }

        foreach ((array) ($r['json']['data'] ?? []) as $row) {
            $cap = trim((string) ($row['caption'] ?? ''));
            if ($cap !== '' && $needle !== '' && $cap === $needle) {
                return ['resolved' => true, 'found' => true, 'provider_id' => (string) ($row['id'] ?? ''),
                        'published_at' => $row['timestamp'] ?? null];
            }
        }
        return ['resolved' => true, 'found' => false];
    }

    private function validateMedia(array $media): ?array
    {
        if ($media === []) {
            return ['code' => 'INSTAGRAM_REQUIRES_MEDIA', 'message' => 'Instagram posts require an image.'];
        }
        if (count($media) > 1) {
            return ['code' => 'CAROUSEL_NOT_SUPPORTED', 'message' => 'Only a single image is supported at launch.'];
        }
        $m = $media[0];
        $mime = strtolower((string) ($m['mime'] ?? ''));
        if ($mime !== '' && !in_array($mime, self::SUPPORTED_MIME, true)) {
            return ['code' => 'UNSUPPORTED_MEDIA_TYPE',
                    'message' => 'Instagram supports JPEG and PNG images at launch.'];
        }
        // Meta fetches the image itself, so the URL must be publicly reachable.
        if (empty($m['url']) || !filter_var($m['url'], FILTER_VALIDATE_URL)
            || !str_starts_with(strtolower((string) $m['url']), 'https://')) {
            return ['code' => 'MEDIA_URL_NOT_PUBLIC_HTTPS',
                    'message' => 'Instagram requires a public https image URL.'];
        }
        if (!empty($m['bytes']) && (int) $m['bytes'] > self::MAX_BYTES) {
            return ['code' => 'MEDIA_TOO_LARGE', 'message' => 'The image is larger than 8 MB.'];
        }
        return null;
    }

    private function result(bool $ok, ?string $id, string $class, ?string $code, string $msg,
                            bool $retryable, string $corr, ?string $reqId = null,
                            bool $reconnect = false, ?string $containerId = null): array
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
            'container_id'     => $containerId,
            'published_at'     => $ok ? now()->toIso8601String() : null,
            'endpoint'         => $this->lastEndpoint,
        ];
    }
}
