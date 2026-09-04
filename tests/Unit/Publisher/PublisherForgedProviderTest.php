<?php

namespace Tests\Unit\Publisher;

use App\Core\Publisher\FacebookPublisherConnector;
use App\Core\Publisher\InstagramPublisherConnector;
use App\Core\Publisher\MetaErrorMap;
use App\Core\Publisher\MockTransport;
use Tests\TestCase;

/**
 * SOCIAL-888 PUBLISH-HARDENING — forged-provider proof (no DB, no socket).
 *
 * Meta App Review blocks real publication, so the canonical publisher is
 * exercised through MockTransport with SCRIPTED provider responses. This proves
 * the honesty + truthful-state contract that gates real publishing:
 *   provable external id -> success; 200-without-id -> uncertain (never a claim);
 *   auth error -> failed + needs_reconnect; rate/5xx -> retryable; timeout -> uncertain.
 */
class PublisherForgedProviderTest extends TestCase
{
    private function fbPost(array $over = []): array
    {
        return array_merge(['content' => 'Fresh sourdough today.', 'media' => [],
            'canonical_url' => 'https://example.test/bread', 'idempotency_key' => 'idem-1',
            'correlation_id' => 'corr-1'], $over);
    }
    private function fbConn(): array { return ['page_id' => 'PAGE_1', 'access_token' => 'TOK_1']; }

    /** @test */
    public function provable_external_id_yields_success(): void
    {
        $t = new MockTransport(['status' => 200, 'json' => ['id' => 'fb_123'], 'error' => null, 'request_id' => 'r1']);
        $r = (new FacebookPublisherConnector($t))->publish($this->fbPost(), $this->fbConn());
        $this->assertTrue($r['success']);
        $this->assertSame('fb_123', $r['provider_post_id']);
        $this->assertSame(MetaErrorMap::OK, $r['status_class']);
        $this->assertNotEmpty($t->calls, 'transport recorded the call (no real socket)');
    }

    /** @test */
    public function two_hundred_without_id_is_uncertain_never_a_success(): void
    {
        $t = (new MockTransport())->on('/feed', ['status' => 200, 'json' => []]);
        $r = (new FacebookPublisherConnector($t))->publish($this->fbPost(), $this->fbConn());
        $this->assertFalse($r['success']);
        $this->assertSame(MetaErrorMap::UNCERTAIN, $r['status_class']);
        $this->assertSame('NO_PROVIDER_ID', $r['error_code']);
        $this->assertNull($r['provider_post_id']);
    }

    /** @test */
    public function auth_error_fails_and_flags_reconnect(): void
    {
        $t = (new MockTransport())->on('/feed', ['status' => 400, 'json' => ['error' => ['code' => 190]]]);
        $r = (new FacebookPublisherConnector($t))->publish($this->fbPost(), $this->fbConn());
        $this->assertFalse($r['success']);
        $this->assertSame(MetaErrorMap::FAILED, $r['status_class']);
        $this->assertTrue($r['needs_reconnect']);
    }

    /** @test */
    public function rate_limit_is_retryable(): void
    {
        $t = (new MockTransport())->on('/feed', ['status' => 400, 'json' => ['error' => ['code' => 4]]]);
        $r = (new FacebookPublisherConnector($t))->publish($this->fbPost(), $this->fbConn());
        $this->assertFalse($r['success']);
        $this->assertTrue($r['retryable']);
    }

    /** @test */
    public function transport_timeout_is_uncertain(): void
    {
        $t = (new MockTransport())->on('/feed', ['status' => 0, 'json' => [], 'error' => 'TRANSPORT_TIMEOUT']);
        $r = (new FacebookPublisherConnector($t))->publish($this->fbPost(), $this->fbConn());
        $this->assertSame(MetaErrorMap::UNCERTAIN, $r['status_class']);
        $this->assertFalse($r['success']);
    }

    /** @test */
    public function missing_connection_fails_clean(): void
    {
        $r = (new FacebookPublisherConnector(new MockTransport()))->publish($this->fbPost(), []);
        $this->assertFalse($r['success']);
        $this->assertSame('MISSING_CONNECTION', $r['error_code']);
    }

    /** @test */
    public function multi_media_is_rejected_at_launch(): void
    {
        $post = $this->fbPost(['media' => [
            ['url' => 'https://x.test/a.jpg', 'mime' => 'image/jpeg'],
            ['url' => 'https://x.test/b.jpg', 'mime' => 'image/jpeg'],
        ]]);
        $r = (new FacebookPublisherConnector(new MockTransport()))->publish($post, $this->fbConn());
        $this->assertFalse($r['success']);
        $this->assertSame('MULTI_MEDIA_NOT_SUPPORTED', $r['error_code']);
    }

    /** @test */
    public function instagram_container_workflow_publishes(): void
    {
        $t = (new MockTransport())
            ->on('/media', ['status' => 200, 'json' => ['id' => 'container_1']])
            ->on('/media_publish', ['status' => 200, 'json' => ['id' => 'ig_pub_1']]);
        $post = $this->fbPost(['media' => [['url' => 'https://x.test/a.jpg', 'mime' => 'image/jpeg']]]);
        $conn = ['page_id' => 'PAGE_1', 'ig_user_id' => 'IG_1', 'access_token' => 'TOK_1'];
        $r = (new InstagramPublisherConnector($t))->publish($post, $conn);
        $this->assertTrue($r['success'], json_encode($r));
        $this->assertSame('ig_pub_1', $r['provider_post_id']);
    }
}
