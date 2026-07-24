<?php

namespace Tests\Feature\Infrastructure;

use App\Engines\Infrastructure\Services\SubdomainService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * INFRA888 - canonical subdomain rules.
 *
 * Pure-function coverage (normalize/check/hostname/reserved) needs no DB.
 * Availability coverage seeds its own user -> workspace -> website chain and
 * rolls it back, so the suite depends on no pre-existing rows.
 */
class SubdomainServiceTest extends TestCase
{
    use DatabaseTransactions;

    private SubdomainService $svc;

    private int $wsId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new SubdomainService();

        $uid = (int) DB::table('users')->insertGetId([
            'name'       => 'Subdomain Fixture',
            'email'      => 'subdomain-fixture-' . uniqid() . '@example.test',
            'password'   => bcrypt('x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->wsId = (int) DB::table('workspaces')->insertGetId([
            'name'       => 'Subdomain Fixture WS',
            'slug'       => 'subdomain-fixture-' . uniqid(),
            'created_by' => $uid,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @dataProvider normalizeCases */
    public function test_normalize(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->svc->normalize($raw));
    }

    public static function normalizeCases(): array
    {
        return [
            'lowercases'            => ['MySite', 'mysite'],
            'trims whitespace'      => ['  shop  ', 'shop'],
            'collapses hyphens'     => ['my---shop', 'my-shop'],
            'trims leading hyphen'  => ['-shop', 'shop'],
            'trims trailing hyphen' => ['shop-', 'shop'],
            'strips illegal chars'  => ['my_shop!@#', 'myshop'],
            'strips spaces'         => ['my shop', 'myshop'],
            'reduces full hostname' => ['shop.levelupgrowth.io', 'shop'],
            'mixed mess'            => ['  --My__Great Shop--  ', 'mygreatshop'],
            'garbage yields empty'  => ['!!!', ''],
        ];
    }

    /** @dataProvider validCases */
    public function test_check_accepts_valid(string $label): void
    {
        $r = $this->svc->check($label);
        $this->assertTrue($r['valid'], $label . ' should be valid, got: ' . (string) $r['error']);
    }

    public static function validCases(): array
    {
        return [['abc'], ['shop'], ['my-shop'], ['a1b'], ['chef-red'], [str_repeat('a', 50)]];
    }

    /** @dataProvider invalidCases */
    public function test_check_rejects_invalid(string $label): void
    {
        $this->assertFalse($this->svc->check($label)['valid'], $label . ' should be invalid');
    }

    public static function invalidCases(): array
    {
        return [
            'empty'           => [''],
            'too short'       => ['ab'],
            'too long'        => [str_repeat('a', 51)],
            'leading hyphen'  => ['-shop'],
            'trailing hyphen' => ['shop-'],
            'uppercase'       => ['Shop'],
            'underscore'      => ['my_shop'],
            'dot'             => ['my.shop'],
            'reserved www'    => ['www'],
            'reserved admin'  => ['admin'],
            'reserved api'    => ['api'],
        ];
    }

    public function test_boundary_lengths(): void
    {
        $this->assertTrue($this->svc->check(str_repeat('a', 3))['valid'], '3 chars must be valid');
        $this->assertTrue($this->svc->check(str_repeat('a', 50))['valid'], '50 chars must be valid');
        $this->assertFalse($this->svc->check(str_repeat('a', 2))['valid'], '2 chars must be invalid');
        $this->assertFalse($this->svc->check(str_repeat('a', 51))['valid'], '51 chars must be invalid');
    }

    public function test_hostname_is_canonical(): void
    {
        $this->assertSame('shop.levelupgrowth.io', $this->svc->hostname('shop'));
    }

    public function test_every_reserved_label_is_rejected(): void
    {
        foreach (SubdomainService::RESERVED as $r) {
            $this->assertFalse($this->svc->check($r)['valid'], $r . ' is reserved and must be rejected');
        }
    }

    /** Seed a website occupying $label; rolled back by DatabaseTransactions. */
    private function seedTakenSubdomain(string $label): int
    {
        return (int) DB::table('websites')->insertGetId([
            'workspace_id' => $this->wsId,
            'name'         => 'Fixture ' . $label,
            'subdomain'    => $this->svc->hostname($label),
        ]);
    }

    public function test_taken_subdomain_is_unavailable(): void
    {
        $this->seedTakenSubdomain('fixture-taken');
        $this->assertFalse($this->svc->isAvailable('fixture-taken'));
    }

    public function test_taken_subdomain_is_available_when_excluding_its_own_website(): void
    {
        $id = $this->seedTakenSubdomain('fixture-self');
        $this->assertTrue(
            $this->svc->isAvailable('fixture-self', $id),
            'a website must not block its own existing subdomain'
        );
    }

    public function test_soft_deleted_website_does_not_block_subdomain(): void
    {
        DB::table('websites')->insert([
            'workspace_id' => $this->wsId,
            'name'         => 'Fixture deleted',
            'subdomain'    => $this->svc->hostname('fixture-deleted'),
            'deleted_at'   => now(),
        ]);
        $this->assertTrue($this->svc->isAvailable('fixture-deleted'));
    }

    public function test_free_subdomain_is_available(): void
    {
        $this->assertTrue($this->svc->isAvailable('zz-not-taken-label'));
    }

    public function test_require_available_throws_customer_safe_message_when_taken(): void
    {
        $this->seedTakenSubdomain('fixture-clash');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already taken/i');
        $this->svc->requireAvailable('fixture-clash');
    }

    public function test_require_available_returns_normalized_label(): void
    {
        // "  ZZ--Fresh Label  " -> lowercase/trim -> "zz--fresh label"
        // -> strip space -> "zz--freshlabel" -> collapse hyphens -> "zz-freshlabel"
        $label = $this->svc->requireAvailable('  ZZ--Fresh Label  ');
        $this->assertSame('zz-freshlabel', $label);
        $this->assertMatchesRegularExpression(SubdomainService::PATTERN, $label);
    }

    public function test_suggest_avoids_taken_label(): void
    {
        $this->seedTakenSubdomain('fixture-suggest');
        $s = $this->svc->suggest('fixture-suggest');
        $this->assertNotNull($s);
        $this->assertNotSame('fixture-suggest', $s);
        $this->assertTrue($this->svc->isAvailable($s));
        $this->assertTrue($this->svc->check($s)['valid']);
    }
}
