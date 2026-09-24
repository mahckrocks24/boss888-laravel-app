<?php

namespace App\Console\Commands;

use App\Models\Business;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * K4 (2026-09-25) — find identity facts the platform already holds and PROPOSE them.
 *
 * Contact facts are scattered by build path: Adjang keeps contact_address and
 * contact_hours in websites.template_variables (199 keys), SG Travel and MR
 * Systems keep phone and email in websites.settings_json, AMG has neither.
 * None of it reaches the business profile, so none of it can reach schema.
 *
 * Everything this writes is stamped source=proposed. A proposed value is stored
 * and visible, and SchemaComposer will NOT publish it. Someone has to confirm
 * it first. That is the whole point: a string sitting in a template variable is
 * evidence that a fact might exist, not evidence of what the fact is.
 *
 * It never reads page prose or article text. A sentence a model wrote is not a
 * source for a business fact, and "since 2013" in a hero line is a question to
 * ask, not a founding date to store.
 *
 * Dry run is the default. --apply is required to write.
 */
class BusinessIdentityProposeCommand extends Command
{
    protected $signature = 'business:propose-identity
        {--apply : write the proposals (default is a dry run)}
        {--workspace= : limit to one workspace id}';

    protected $description = 'K4: propose phone, email, address, hours and social identities from stored site settings';

    /** settings_json / template_variables keys that may carry each fact. */
    private const KEYS = [
        'phone' => ['phone', 'contact_phone', 'telephone', 'whatsapp', 'contact_whatsapp'],
        'email' => ['email', 'contact_email', 'enquiry_email'],
    ];

    private const SOCIAL = ['facebook', 'instagram', 'linkedin', 'twitter', 'x', 'youtube', 'tiktok', 'pinterest'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $sites = DB::table('websites')->whereNull('deleted_at')
            ->where('status', 'published')->whereNotNull('business_id')
            ->when($this->option('workspace'), fn ($q) => $q->where('workspace_id', (int) $this->option('workspace')))
            ->get(['id', 'workspace_id', 'business_id', 'name', 'settings_json', 'template_variables']);

        $proposedCount = 0;
        $bySource = [];
        $touched = 0;
        $rows = [];

        foreach ($sites as $site) {
            $business = Business::find($site->business_id);
            if (! $business) {
                continue;
            }

            // Two bags, two provenances. settings_json is configuration someone
            // set for the site; template_variables is generation output, which
            // is AI-written copy and carries fake seed data such as
            // "(512) 555-0178" and "info@auroradentalstudio.com". Both are
            // withheld from publication, but they are not the same kind of
            // claim and Sarah must be able to tell them apart.
            $settings = $this->decode($site->settings_json);
            $template = $this->decode($site->template_variables);
            $bag = array_merge($settings, $template);
            if (! $bag) {
                continue;
            }
            $sourceOf = function (array $keys) use ($settings): string {
                foreach ($keys as $key) {
                    foreach ($settings as $k => $v) {
                        if (strcasecmp((string) $k, $key) === 0 && ! is_array($v) && trim((string) $v) !== '') {
                            return 'proposed';
                        }
                    }
                }

                return 'ai_generated';
            };

            $found = [];

            foreach (self::KEYS as $field => $keys) {
                if ($this->alreadyHeld($business, $field)) {
                    continue;
                }
                $value = $this->firstScalar($bag, $keys);
                if ($value !== null) {
                    $found[$field] = [$value, $sourceOf($keys)];
                }
            }

            if (! $this->alreadyHeld($business, 'address_json')) {
                $addressKeys = ['contact_address', 'address', 'street_address', 'office'];
                $street = $this->firstScalar($bag, $addressKeys);
                $city = $this->firstScalar($bag, ['city', 'home_city', 'locality']);
                // "Austin, TX" is commonly stored in both slots. One value is a
                // locality, not a street address plus a locality that repeat.
                if ($street !== null && $city !== null && strcasecmp($street, $city) === 0) {
                    $street = null;
                }
                $address = array_filter([
                    'streetAddress' => $street,
                    'addressLocality' => $city,
                ], fn ($v) => $v !== null && $v !== '');
                if ($address) {
                    $found['address_json'] = [$address, $sourceOf($addressKeys)];
                }
            }

            if (! $this->alreadyHeld($business, 'opening_hours_json')) {
                $hoursKeys = ['contact_hours', 'opening_hours', 'hours'];
                $hours = $this->firstScalar($bag, $hoursKeys);
                if ($hours !== null) {
                    $found['opening_hours_json'] = [['raw' => $hours], $sourceOf($hoursKeys)];
                }
            }

            if (! $this->alreadyHeld($business, 'sameas_json')) {
                $social = [];
                foreach (self::SOCIAL as $network) {
                    $url = $this->firstScalar($bag, [$network, $network . '_url']);
                    if ($url !== null && str_starts_with(strtolower($url), 'http')) {
                        $social[] = $url;
                    }
                }
                if ($social) {
                    $found['sameas_json'] = [array_values(array_unique($social)), $sourceOf(self::SOCIAL)];
                }
            }

            if (! $found) {
                continue;
            }

            $touched++;
            foreach ($found as $field => [$value, $source]) {
                $proposedCount++;
                $bySource[$source] = ($bySource[$source] ?? 0) + 1;
                $rows[] = sprintf('  %-26s %-19s %-13s %s', substr((string) $site->name, 0, 25), $field, $source, $this->short($value));
                if ($apply) {
                    $business->{$field} = $value;
                    $business->stampIdentitySource(
                        $field,
                        $source,
                        $source === 'proposed' ? 'websites.settings_json' : 'websites.template_variables'
                    );
                }
            }

            if ($apply) {
                $business->saveQuietly();
            }
        }

        $this->line('published websites with a profile : ' . count($sites));
        $this->line('  businesses with proposals       : ' . $touched);
        $this->line('  fields proposed                 : ' . $proposedCount);
        foreach ($bySource as $source => $count) {
            $this->line(sprintf('    %-14s %d', $source, $count));
        }
        $this->newLine();
        foreach (array_slice($rows, 0, 40) as $row) {
            $this->line($row);
        }
        if (count($rows) > 40) {
            $this->line('  ... ' . (count($rows) - 40) . ' more');
        }

        $this->newLine();
        if ($apply) {
            $this->info('Written as candidates. Nothing here is a verified fact and SchemaComposer publishes none of it until someone confirms it.');
        } else {
            $this->warn('DRY RUN — nothing written. Re-run with --apply.');
        }

        return self::SUCCESS;
    }

    private function decode(?string $json): array
    {
        $out = json_decode((string) $json, true);

        return is_array($out) ? $out : [];
    }

    /** A field already carrying a value is never overwritten by a proposal. */
    private function alreadyHeld(Business $b, string $field): bool
    {
        $v = $b->{$field} ?? null;

        return $v !== null && $v !== '' && $v !== [];
    }

    private function firstScalar(array $bag, array $keys): ?string
    {
        foreach ($keys as $key) {
            foreach ($bag as $k => $v) {
                if (strcasecmp((string) $k, $key) !== 0 || is_array($v)) {
                    continue;
                }
                $v = trim((string) $v);
                if ($v !== '' && mb_strlen($v) <= 400) {
                    return $v;
                }
            }
        }

        return null;
    }

    private function short(mixed $value): string
    {
        $text = is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : (string) $value;

        return mb_strlen($text) > 70 ? mb_substr($text, 0, 67) . '...' : $text;
    }
}
