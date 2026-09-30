<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\TemplateService;
use App\Engines\Builder\Support\ContactFacts;
use Tests\TestCase;

/**
 * CONTACT-1 (RFC-0021 wave 2) — fail-first tests from REPORT-0067 #4/#6/#14 and REPORT-0068 #3/#6/#14 (2026-09-30):
 * the drone firm's phone "0161 555 0199" and the bakery's "hello@crumbandco.co.uk" were given and never reached the site,
 * the shoe shop got invented hours, "7am–3pm. Phone 01603 555 214" became a service card, and the business profile
 * held no contact facts at all.
 */
class ContactFactsTest extends TestCase
{
    private bool $madeSwitch = false;

    protected function setUp(): void
    {
        parent::setUp();
        $sw = storage_path(ContactFacts::SWITCH);
        if (! is_file($sw)) { touch($sw); $this->madeSwitch = true; }
    }

    protected function tearDown(): void
    {
        if ($this->madeSwitch) @unlink(storage_path(ContactFacts::SWITCH));
        parent::tearDown();
    }

    public function test_given_facts_are_kept_verbatim_and_nothing_else_is_invented(): void
    {
        $data = ['business_name' => 'Crumb and Co', 'phone' => '01603 555 214', 'email' => 'hello@crumbandco.co.uk', 'address' => '12 Elm Hill, Norwich NR3 1HN, UK', 'hours' => 'Tuesday to Sunday 7am to 3pm'];
        $given = ContactFacts::fromBuildData($data);
        $this->assertSame('hello@crumbandco.co.uk', $given['email']);
        $this->assertSame('01603 555 214', $given['phone']);
        $specs = ['contact_phone' => [], 'contact_email' => [], 'contact_address' => [], 'contact_hours' => [], 'contact_phone_label' => [], 'contact_website' => [], 'service_1_title' => []];
        $vars = ['contact_email' => 'hello@crumbandco.com', 'contact_hours' => 'Monday–Friday: 9 AM – 6 PM PST', 'contact_phone' => '', 'contact_phone_label' => 'Phone', 'contact_website' => 'crumbandco.com', 'service_1_title' => 'Sourdough'];
        $out = ContactFacts::enforce($vars, $specs, $given);
        $this->assertSame('hello@crumbandco.co.uk', $out['contact_email'], 'the owner\'s domain, not a rewritten one');
        $this->assertSame('01603 555 214', $out['contact_phone']);
        $this->assertSame('12 Elm Hill, Norwich NR3 1HN, UK', $out['contact_address']);
        $this->assertSame('Tuesday to Sunday 7am to 3pm', $out['contact_hours']);
        $this->assertSame('', $out['contact_website'], 'a website the owner never gave is not invented');
        $this->assertSame('Phone', $out['contact_phone_label'], 'labels are untouched');
        $this->assertSame('Sourdough', $out['service_1_title'], 'catalogue slots are untouched');
    }

    public function test_nothing_given_means_empty_never_a_guess(): void
    {
        $out = ContactFacts::enforce(['contact_email' => 'info@jaykicks.com', 'contact_hours' => 'Monday–Friday: 9 AM – 6 PM PST'], ['contact_email' => [], 'contact_hours' => []], ContactFacts::fromBuildData(['business_name' => 'Jay Kicks']));
        $this->assertSame('', $out['contact_email']); $this->assertSame('', $out['contact_hours']);
        $this->assertSame([], ContactFacts::fromBuildData(['email' => 'not an email', 'phone' => 'null']));
    }

    public function test_hours_phones_addresses_and_page_lists_are_never_services(): void
    {
        $in = ['Sourdough', 'Croissants', '7am–3pm. Phone 01603 555 214', 'Wedding Cakes Made To Order. Open Tuesday To Sunday', 'Pages: Home, Menu, Cakes to Order', 'hello@crumbandco.co.uk', '12 Elm Hill, Norwich NR3 1HN', 'Custom birthday cakes'];
        $this->assertSame(['Sourdough', 'Croissants', 'Custom birthday cakes'], ContactFacts::scrubServices($in));
    }

    public function test_currency_follows_the_location(): void
    {
        $this->assertSame('GBP', ContactFacts::currencyFor('Salford, Greater Manchester'));
        $this->assertSame('GBP', ContactFacts::currencyFor('12 Elm Hill, Norwich NR3 1HN, UK'));
        $this->assertSame('AED', ContactFacts::currencyFor('دبي، منطقة الكرامة'));
        $this->assertSame('USD', ContactFacts::currencyFor(''));
    }

    public function test_structured_data_carries_the_facts(): void
    {
        $x = ContactFacts::jsonLdExtras(['contact_phone' => '0161 555 0199', 'contact_email' => 'hello@skyscope.co.uk', 'contact_address' => 'Salford', 'contact_hours' => 'Mon–Fri 8–6', 'contact_phone_label' => 'Call']);
        $this->assertSame('0161 555 0199', $x['telephone']); $this->assertSame('hello@skyscope.co.uk', $x['email']);
        $this->assertSame('Salford', $x['address']['streetAddress']); $this->assertSame('Mon–Fri 8–6', $x['openingHours']);
        $html = '<head><script type="application/ld+json">{"@context":"https://schema.org","@type":"LocalBusiness","name":"SkyScope Surveys","description":"x"}</script></head>';
        $out = ContactFacts::refreshJsonLd($html, ['contact_phone' => '0161 555 0199']);
        $this->assertStringContainsString('"telephone":"0161 555 0199"', $out); $this->assertStringContainsString('"name":"SkyScope Surveys"', $out);
    }

    public function test_rendered_export_carries_telephone_in_its_business_schema(): void
    {
        $t = app(TemplateService::class);
        $m = $t->getManifest('gym'); $this->assertNotNull($m);
        $vars = []; foreach (($m['variables'] ?? []) as $k => $spec) { $vars[$k] = (string) (is_array($spec) ? ($spec['default'] ?? '') : ''); }
        $vars['business_name'] = 'Iron Works Gym'; $vars['contact_phone'] = '0161 555 0199'; $vars['contact_email'] = 'hello@ironworks.co.uk';
        $html = $t->render('gym', $vars, null);
        $this->assertMatchesRegularExpression('/application\/ld\+json">\{[^<]*"telephone":"0161 555 0199"/', $html);
        $this->assertMatchesRegularExpression('/application\/ld\+json">\{[^<]*"email":"hello@ironworks\.co\.uk"/', $html);
    }

    public function test_the_owners_own_email_and_phone_beat_the_models_tidying(): void
    {
        $history = [['role' => 'user', 'content' => 'Open Tuesday to Sunday 7am to 3pm, phone 01603 555 214, email hello@crumbandco.co.uk. Warm feel.'], ['role' => 'arthur', 'content' => 'Lovely.']];
        $bd = ContactFacts::reconcileWithConversation(['business_name' => 'Crumb and Co', 'email' => 'hello@crumbandco.com', 'phone' => '01603555214'], $history);
        $this->assertSame('hello@crumbandco.co.uk', $bd['email']); $this->assertSame('01603 555 214', $bd['phone']);
        $bd2 = ContactFacts::reconcileWithConversation(['email' => 'x@y.com'], [['role' => 'user', 'content' => 'no contact given']]);
        $this->assertSame('x@y.com', $bd2['email'], 'nothing in the conversation, nothing changes');
    }

    public function test_plain_text_facts_become_tap_to_call_and_mail_links(): void
    {
        $html = '<span class="contact-item-value" data-field="contact_phone">01603 555 214</span><dd data-field="contact_email">hello@crumbandco.co.uk</dd><span data-field="contact_phone_label">Phone</span><a href="tel:0161" data-field="booking_phone">0161 555 0199</a><span data-field="contact_phone"></span>';
        $out = ContactFacts::linkFactElements($html);
        $this->assertStringContainsString('<a href="tel:01603555214" class="contact-item-value" data-field="contact_phone" style="color:inherit;text-decoration:inherit;">01603 555 214</a>', $out);
        $this->assertStringContainsString('<a href="mailto:hello@crumbandco.co.uk" data-field="contact_email" style="color:inherit;text-decoration:inherit;">hello@crumbandco.co.uk</a>', $out);
        $this->assertStringContainsString('<span data-field="contact_phone_label">Phone</span>', $out, 'labels stay');
        $this->assertStringContainsString('<a href="tel:0161" data-field="booking_phone">0161 555 0199</a>', $out, 'an existing link stays');
        $this->assertStringContainsString('<span data-field="contact_phone"></span>', $out, 'an empty slot stays');
    }

    public function test_editor_validation(): void
    {
        [$f, $e] = ContactFacts::validate(['phone' => '0161 555 0199', 'email' => 'nope', 'address' => '', 'hours' => "Mon–Fri 8–6\r\nSat 9–1"]);
        $this->assertSame('0161 555 0199', $f['phone']); $this->assertSame("Mon–Fri 8–6\nSat 9–1", $f['hours']);
        $this->assertSame('', $f['address'], 'an emptied box clears the fact'); $this->assertArrayHasKey('email', $e);
    }
}
