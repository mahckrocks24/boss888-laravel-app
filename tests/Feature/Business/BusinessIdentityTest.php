<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * K4 (2026-09-25): the canonical business model carries the identity facts a
 * machine-readable Organization needs, and knows which of them it is allowed
 * to publish.
 *
 * The discipline this pins: a value copied out of a settings blob or a
 * generated template variable is a CANDIDATE. It is stored, it is visible, and
 * it does not reach public structured data until someone confirms it. On
 * 2026-09-25 the scan proposed 157 values across 43 businesses - 151 of them
 * from AI-generated template content, including phone numbers like
 * "(512) 555-0178" - and exactly 0 were publishable.
 */
class BusinessIdentityTest extends TestCase
{
    private int $user;
    private int $ws;
    private array $sites = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = (int) DB::table('users')->insertGetId([
            'name' => 'K4 identity', 'email' => 'k4-' . uniqid() . '@example.test',
            'password' => password_hash('K4-Identity-Password-1', PASSWORD_BCRYPT),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ws = (int) DB::table('workspaces')->insertGetId([
            'name' => 'K4 Workspace', 'slug' => 'k4-' . uniqid(), 'timezone' => 'UTC',
            'created_by' => $this->user, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->sites) {
            DB::table('websites')->whereIn('id', $this->sites)->delete();
        }
        DB::table('businesses')->where('workspace_id', $this->ws)->delete();
        DB::table('workspaces')->where('id', $this->ws)->delete();
        DB::table('users')->where('id', $this->user)->delete();
        parent::tearDown();
    }

    private function makeBusiness(array $attrs = []): Business
    {
        return Business::create(array_merge([
            'workspace_id' => $this->ws, 'name' => 'K4 Co',
            'slug' => 'k4co-' . uniqid(), 'is_default' => 0, 'sort_order' => 0,
        ], $attrs));
    }

    private function makeSite(Business $b, array $settings, array $template): int
    {
        $id = (int) DB::table('websites')->insertGetId([
            'workspace_id' => $this->ws, 'business_id' => $b->id, 'name' => 'K4 Site',
            'subdomain' => 'k4-' . uniqid() . '.levelupgrowth.io', 'status' => 'published',
            'settings_json' => json_encode($settings), 'template_variables' => json_encode($template),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->sites[] = $id;

        return $id;
    }

    // ---- the publication gate -------------------------------------------

    /** @dataProvider sources */
    public function test_only_confirmed_sources_may_be_published(string $source, bool $publishable): void
    {
        $b = $this->makeBusiness(['phone' => '+1 555 0100']);
        $b->stampIdentitySource('phone', $source);

        $this->assertSame($publishable, $b->identityIsPublishable('phone'), "source {$source}");
    }

    public static function sources(): array
    {
        return [
            'owner stated' => ['owner_stated', true],
            'verified' => ['verified', true],
            'imported' => ['imported', true],
            'observed' => ['observed', false],
            'proposed' => ['proposed', false],
            'ai generated' => ['ai_generated', false],
        ];
    }

    public function test_a_value_with_no_recorded_source_is_treated_as_owner_stated(): void
    {
        // Every automatic writer stamps provenance, so an unstamped value can
        // only have been typed in by the customer.
        $b = $this->makeBusiness(['phone' => '+1 555 0100']);

        $this->assertNull($b->identitySource('phone'));
        $this->assertTrue($b->identityIsPublishable('phone'));
    }

    public function test_an_empty_field_is_never_publishable(): void
    {
        $b = $this->makeBusiness();

        foreach (['phone', 'email', 'address_json', 'sameas_json'] as $field) {
            $this->assertFalse($b->identityIsPublishable($field), $field);
        }
    }

    public function test_stamping_one_field_leaves_the_others_alone(): void
    {
        $b = $this->makeBusiness(['phone' => '+1 555 0100', 'email' => 'a@example.test']);
        $b->stampIdentitySource('phone', 'owner_stated');
        $b->stampIdentitySource('email', 'ai_generated');

        $this->assertSame('owner_stated', $b->identitySource('phone'));
        $this->assertSame('ai_generated', $b->identitySource('email'));
        $this->assertTrue($b->identityIsPublishable('phone'));
        $this->assertFalse($b->identityIsPublishable('email'));
    }

    public function test_json_identity_fields_round_trip(): void
    {
        $b = $this->makeBusiness([
            'address_json' => ['streetAddress' => '1 High St', 'addressLocality' => 'Manchester'],
            'sameas_json' => ['https://facebook.com/example'],
            'area_served_json' => ['Greater Manchester'],
        ]);
        $fresh = Business::find($b->id);

        $this->assertSame('Manchester', $fresh->address_json['addressLocality']);
        $this->assertSame(['https://facebook.com/example'], $fresh->sameas_json);
        $this->assertSame(['Greater Manchester'], $fresh->area_served_json);
    }

    // ---- the candidate scan ---------------------------------------------

    public function test_settings_values_are_proposed_and_template_values_are_ai_generated(): void
    {
        $b = $this->makeBusiness();
        $this->makeSite($b, ['phone' => '+63 947 166 0941'], ['contact_hours' => 'Mon-Fri 9-5']);

        Artisan::call('business:propose-identity', ['--apply' => true, '--workspace' => $this->ws]);
        $fresh = Business::find($b->id);

        $this->assertSame('+63 947 166 0941', $fresh->phone);
        $this->assertSame('proposed', $fresh->identitySource('phone'), 'settings_json is configuration');
        $this->assertSame('ai_generated', $fresh->identitySource('opening_hours_json'), 'template_variables is generated copy');
        $this->assertFalse($fresh->identityIsPublishable('phone'), 'a candidate is never published');
        $this->assertFalse($fresh->identityIsPublishable('opening_hours_json'));
    }

    public function test_the_scan_never_overwrites_a_value_already_held(): void
    {
        $b = $this->makeBusiness(['phone' => '+44 161 000 0000']);
        $b->stampIdentitySource('phone', 'owner_stated');
        $b->save();
        $this->makeSite($b, ['phone' => '(512) 555-0178'], []);

        Artisan::call('business:propose-identity', ['--apply' => true, '--workspace' => $this->ws]);
        $fresh = Business::find($b->id);

        $this->assertSame('+44 161 000 0000', $fresh->phone, 'an owner-stated number is not replaced by a scan');
        $this->assertSame('owner_stated', $fresh->identitySource('phone'));
    }

    public function test_a_repeated_locality_is_not_stored_twice(): void
    {
        $b = $this->makeBusiness();
        $this->makeSite($b, [], ['contact_address' => 'Austin, TX', 'city' => 'Austin, TX']);

        Artisan::call('business:propose-identity', ['--apply' => true, '--workspace' => $this->ws]);
        $fresh = Business::find($b->id);

        $this->assertSame(['addressLocality' => 'Austin, TX'], $fresh->address_json);
    }

    public function test_the_scan_is_idempotent(): void
    {
        $b = $this->makeBusiness();
        $this->makeSite($b, ['email' => 'hello@example.test'], []);

        Artisan::call('business:propose-identity', ['--apply' => true, '--workspace' => $this->ws]);
        $after = Business::find($b->id)->identity_meta_json;
        Artisan::call('business:propose-identity', ['--apply' => true, '--workspace' => $this->ws]);

        $this->assertSame($after, Business::find($b->id)->identity_meta_json);
    }

    public function test_the_scan_ignores_draft_websites(): void
    {
        $b = $this->makeBusiness();
        $id = $this->makeSite($b, ['phone' => '+1 555 0111'], []);
        DB::table('websites')->where('id', $id)->update(['status' => 'draft']);

        Artisan::call('business:propose-identity', ['--apply' => true, '--workspace' => $this->ws]);

        $this->assertNull(Business::find($b->id)->phone, 'a draft carries no public identity (EV-1097)');
    }
}
