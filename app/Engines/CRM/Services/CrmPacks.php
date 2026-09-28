<?php

namespace App\Engines\CRM\Services;

use Illuminate\Support\Facades\DB;

/**
 * CRM-UX-2 (Clients revamp Phase 2): industry packs. One engine, set up per BUSINESS by its pack: the words it uses
 * for a client, the stages its clients move through, the details it keeps about them and the lists it opens.
 *
 * Every pack stage maps to one of the five lifecycle statuses (new, contacted, qualified, converted, lost) so
 * reports, automations and Sarah keep working whatever the industry calls things.
 *
 * A business gets a pack from its industry automatically; the owner can switch it and rename the words
 * (businesses.settings_json.crm = {pack, one, many}).
 */
class CrmPacks
{
    public const STATUSES = ['new', 'contacted', 'qualified', 'converted', 'lost'];

    public static function all(): array
    {
        return [
            'appointments' => [
                'booked' => 'booked', 'label' => 'Appointments', 'about' => 'Clinics, salons, barbers, gyms, pet care and car servicing: people who book visits.',
                'one' => 'Client', 'many' => 'Clients',
                'stages' => [
                    ['key' => 'enquiry', 'name' => 'Enquiry', 'status' => 'new'],
                    ['key' => 'contacted', 'name' => 'Contacted', 'status' => 'contacted'],
                    ['key' => 'booked', 'name' => 'Booked', 'status' => 'qualified'],
                    ['key' => 'visited', 'name' => 'Visited', 'status' => 'converted'],
                    ['key' => 'regular', 'name' => 'Regular', 'status' => 'converted'],
                    ['key' => 'lost', 'name' => 'Not now', 'status' => 'lost'],
                ],
                'fields' => [
                    ['key' => 'service', 'label' => 'Service they want', 'type' => 'text'],
                    ['key' => 'preferred_time', 'label' => 'Preferred day and time', 'type' => 'text'],
                    ['key' => 'birthday', 'label' => 'Birthday', 'type' => 'date'],
                    ['key' => 'notes_private', 'label' => 'Private notes (allergies, preferences)', 'type' => 'textarea'],
                ],
                'views' => [
                    ['key' => 'new_enquiries', 'name' => 'New enquiries'],
                    ['key' => 'booked', 'name' => 'Booked', 'stage' => 'booked'],
                    ['key' => 'no_reply_3d', 'name' => 'Waiting for a reply'],
                    ['key' => 'lapsed_60d', 'name' => 'Not seen in 60 days'],
                ],
            ],
            'property' => [
                'booked' => 'viewing', 'label' => 'Property', 'about' => 'Real estate agents and agencies: buyers, sellers, landlords and tenants.',
                'one' => 'Client', 'many' => 'Clients',
                'stages' => [
                    ['key' => 'new_lead', 'name' => 'New lead', 'status' => 'new'],
                    ['key' => 'contacted', 'name' => 'Contacted', 'status' => 'contacted'],
                    ['key' => 'viewing', 'name' => 'Viewing booked', 'status' => 'qualified'],
                    ['key' => 'offer', 'name' => 'Offer made', 'status' => 'qualified'],
                    ['key' => 'under_contract', 'name' => 'Under contract', 'status' => 'qualified'],
                    ['key' => 'closed', 'name' => 'Closed', 'status' => 'converted'],
                    ['key' => 'lost', 'name' => 'Lost', 'status' => 'lost'],
                ],
                'fields' => [
                    ['key' => 'role', 'label' => 'Looking to', 'type' => 'select', 'options' => ['Buy', 'Sell', 'Rent', 'Let out', 'Buy and sell']],
                    ['key' => 'budget', 'label' => 'Budget', 'type' => 'text'],
                    ['key' => 'areas', 'label' => 'Areas', 'type' => 'text'],
                    ['key' => 'bedrooms', 'label' => 'Bedrooms', 'type' => 'text'],
                    ['key' => 'pre_approved', 'label' => 'Finance ready', 'type' => 'select', 'options' => ['Yes', 'No', 'In progress']],
                    ['key' => 'property_address', 'label' => 'Their property (if selling)', 'type' => 'text'],
                ],
                'views' => [
                    ['key' => 'new_today', 'name' => 'New today'],
                    ['key' => 'no_reply_3d', 'name' => 'Not contacted in 3 days'],
                    ['key' => 'offers', 'name' => 'Offers out', 'stage' => 'offer'],
                    ['key' => 'under_contract', 'name' => 'Under contract', 'stage' => 'under_contract'],
                ],
            ],
            'stays' => [
                'booked' => 'confirmed', 'label' => 'Stays and venues', 'about' => 'Hotels, resorts, rentals, event venues and travel: guests who book dates.',
                'one' => 'Guest', 'many' => 'Guests',
                'stages' => [
                    ['key' => 'enquiry', 'name' => 'Enquiry', 'status' => 'new'],
                    ['key' => 'proposal', 'name' => 'Proposal sent', 'status' => 'contacted'],
                    ['key' => 'deposit', 'name' => 'Deposit paid', 'status' => 'qualified'],
                    ['key' => 'confirmed', 'name' => 'Confirmed', 'status' => 'converted'],
                    ['key' => 'completed', 'name' => 'Completed', 'status' => 'converted'],
                    ['key' => 'lost', 'name' => 'Lost', 'status' => 'lost'],
                ],
                'fields' => [
                    ['key' => 'dates', 'label' => 'Dates', 'type' => 'text'],
                    ['key' => 'guests', 'label' => 'Number of guests', 'type' => 'text'],
                    ['key' => 'budget', 'label' => 'Budget', 'type' => 'text'],
                    ['key' => 'occasion', 'label' => 'Occasion', 'type' => 'text'],
                ],
                'views' => [
                    ['key' => 'new_enquiries', 'name' => 'New enquiries'],
                    ['key' => 'proposals', 'name' => 'Proposals out', 'stage' => 'proposal'],
                    ['key' => 'confirmed', 'name' => 'Confirmed', 'stage' => 'confirmed'],
                    ['key' => 'no_reply_3d', 'name' => 'Waiting for a reply'],
                ],
            ],
            'projects' => [
                'booked' => 'discovery', 'label' => 'Projects and quotes', 'about' => 'Trades, builders, designers, caterers, agencies and advisers: work that is quoted, won and delivered.',
                'one' => 'Client', 'many' => 'Clients',
                'stages' => [
                    ['key' => 'lead', 'name' => 'New lead', 'status' => 'new'],
                    ['key' => 'discovery', 'name' => 'Call or visit', 'status' => 'contacted'],
                    ['key' => 'quote_sent', 'name' => 'Quote sent', 'status' => 'qualified'],
                    ['key' => 'won', 'name' => 'Won', 'status' => 'converted'],
                    ['key' => 'in_progress', 'name' => 'In progress', 'status' => 'converted'],
                    ['key' => 'paid', 'name' => 'Paid', 'status' => 'converted'],
                    ['key' => 'lost', 'name' => 'Lost', 'status' => 'lost'],
                ],
                'fields' => [
                    ['key' => 'job', 'label' => 'What they need', 'type' => 'textarea'],
                    ['key' => 'budget', 'label' => 'Budget', 'type' => 'text'],
                    ['key' => 'date_needed', 'label' => 'Date needed', 'type' => 'date'],
                    ['key' => 'site_address', 'label' => 'Address', 'type' => 'text'],
                ],
                'views' => [
                    ['key' => 'new_enquiries', 'name' => 'New enquiries'],
                    ['key' => 'quotes', 'name' => 'Quotes awaiting reply', 'stage' => 'quote_sent'],
                    ['key' => 'in_progress', 'name' => 'In progress', 'stage' => 'in_progress'],
                    ['key' => 'no_reply_3d', 'name' => 'Waiting for a reply'],
                ],
            ],
            'guests' => [
                'booked' => 'contacted', 'label' => 'Guests and orders', 'about' => 'Restaurants, cafes, bakeries and shops: regulars, orders and reservations.',
                'one' => 'Customer', 'many' => 'Customers',
                'stages' => [
                    ['key' => 'new', 'name' => 'New', 'status' => 'new'],
                    ['key' => 'contacted', 'name' => 'Replied to', 'status' => 'contacted'],
                    ['key' => 'returning', 'name' => 'Returning', 'status' => 'converted'],
                    ['key' => 'regular', 'name' => 'Regular', 'status' => 'converted'],
                    ['key' => 'vip', 'name' => 'VIP', 'status' => 'converted'],
                    ['key' => 'lost', 'name' => 'Not now', 'status' => 'lost'],
                ],
                'fields' => [
                    ['key' => 'favourite', 'label' => 'Usual order or favourite', 'type' => 'text'],
                    ['key' => 'dietary', 'label' => 'Dietary needs', 'type' => 'text'],
                    ['key' => 'birthday', 'label' => 'Birthday', 'type' => 'date'],
                    ['key' => 'occasion', 'label' => 'Special occasion', 'type' => 'text'],
                ],
                'views' => [
                    ['key' => 'new_enquiries', 'name' => 'New'],
                    ['key' => 'regulars', 'name' => 'Regulars', 'stage' => 'regular'],
                    ['key' => 'vip', 'name' => 'VIP', 'stage' => 'vip'],
                    ['key' => 'lapsed_60d', 'name' => 'Not seen in 60 days'],
                ],
            ],
            'enrolment' => [
                'booked' => 'trial', 'label' => 'Enrolment', 'about' => 'Schools, tutors, courses, training centres and childcare: students and parents.',
                'one' => 'Student', 'many' => 'Students',
                'stages' => [
                    ['key' => 'enquiry', 'name' => 'Enquiry', 'status' => 'new'],
                    ['key' => 'contacted', 'name' => 'Contacted', 'status' => 'contacted'],
                    ['key' => 'trial', 'name' => 'Trial or tour', 'status' => 'qualified'],
                    ['key' => 'enrolled', 'name' => 'Enrolled', 'status' => 'converted'],
                    ['key' => 'renewal', 'name' => 'Renewal due', 'status' => 'converted'],
                    ['key' => 'left', 'name' => 'Left', 'status' => 'lost'],
                ],
                'fields' => [
                    ['key' => 'course', 'label' => 'Course or class', 'type' => 'text'],
                    ['key' => 'parent', 'label' => 'Parent or guardian', 'type' => 'text'],
                    ['key' => 'start_date', 'label' => 'Start date', 'type' => 'date'],
                    ['key' => 'level', 'label' => 'Level or age', 'type' => 'text'],
                ],
                'views' => [
                    ['key' => 'new_enquiries', 'name' => 'New enquiries'],
                    ['key' => 'trials', 'name' => 'Trials booked', 'stage' => 'trial'],
                    ['key' => 'renewals', 'name' => 'Renewals due', 'stage' => 'renewal'],
                    ['key' => 'no_reply_3d', 'name' => 'Waiting for a reply'],
                ],
            ],
            'audience' => [
                'booked' => 'contacted', 'label' => 'Audience', 'about' => 'News sites and media: advertisers, sponsors and subscribers.',
                'one' => 'Contact', 'many' => 'Contacts',
                'stages' => [
                    ['key' => 'lead', 'name' => 'Lead', 'status' => 'new'],
                    ['key' => 'contacted', 'name' => 'Contacted', 'status' => 'contacted'],
                    ['key' => 'proposal', 'name' => 'Proposal sent', 'status' => 'qualified'],
                    ['key' => 'booked', 'name' => 'Booked', 'status' => 'converted'],
                    ['key' => 'running', 'name' => 'Running', 'status' => 'converted'],
                    ['key' => 'lost', 'name' => 'Lost', 'status' => 'lost'],
                ],
                'fields' => [
                    ['key' => 'kind', 'label' => 'Type', 'type' => 'select', 'options' => ['Advertiser', 'Sponsor', 'Subscriber', 'Partner']],
                    ['key' => 'budget', 'label' => 'Budget', 'type' => 'text'],
                    ['key' => 'campaign_dates', 'label' => 'Campaign dates', 'type' => 'text'],
                ],
                'views' => [
                    ['key' => 'new_enquiries', 'name' => 'New'],
                    ['key' => 'proposals', 'name' => 'Proposals out', 'stage' => 'proposal'],
                    ['key' => 'running', 'name' => 'Running', 'stage' => 'running'],
                ],
            ],
            'general' => [
                'booked' => 'qualified', 'label' => 'General', 'about' => 'Any other business: a simple enquiry-to-won pipeline.',
                'one' => 'Client', 'many' => 'Clients',
                'stages' => [
                    ['key' => 'new', 'name' => 'New', 'status' => 'new'],
                    ['key' => 'contacted', 'name' => 'Contacted', 'status' => 'contacted'],
                    ['key' => 'qualified', 'name' => 'Qualified', 'status' => 'qualified'],
                    ['key' => 'won', 'name' => 'Won', 'status' => 'converted'],
                    ['key' => 'lost', 'name' => 'Lost', 'status' => 'lost'],
                ],
                'fields' => [
                    ['key' => 'need', 'label' => 'What they need', 'type' => 'textarea'],
                    ['key' => 'budget', 'label' => 'Budget', 'type' => 'text'],
                ],
                'views' => [
                    ['key' => 'new_enquiries', 'name' => 'New enquiries'],
                    ['key' => 'no_reply_3d', 'name' => 'Waiting for a reply'],
                    ['key' => 'won', 'name' => 'Won', 'stage' => 'won'],
                ],
            ],
        ];
    }

    /** Industry text (template key or free text) → pack key, plus the word for a client in that industry. */
    public static function forIndustry(?string $industry): array
    {
        $i = strtolower(trim((string) $industry));
        $has = fn (array $words) => (bool) array_filter($words, fn ($w) => $i !== '' && str_contains($i, $w));
        return match (true) {
            $has(['dental', 'dentist', 'medical', 'clinic', 'aesthetic', 'doctor', 'physio', 'therapy', 'health']) => ['appointments', 'Patient', 'Patients'],
            $has(['salon', 'barber', 'beauty', 'spa', 'nail', 'hair', 'lash', 'massage']) => ['appointments', 'Client', 'Clients'],
            $has(['gym', 'fitness', 'yoga', 'pilates', 'crossfit', 'martial']) => ['appointments', 'Member', 'Members'],
            $has(['pet_services', 'pet services', 'pet care', 'vet', 'groom', 'dog']) => ['appointments', 'Pet parent', 'Pet parents'],
            $has(['automotive', 'car ', 'mechanic', 'garage', 'auto ']) => ['appointments', 'Customer', 'Customers'],
            $has(['real estate', 'real_estate', 'realty', 'property', 'realtor', 'broker']) => ['property', 'Client', 'Clients'],
            $has(['hotel', 'resort', 'rental', 'short_term', 'airbnb', 'venue', 'travel', 'tour', 'bnb', 'hostel']) => ['stays', 'Guest', 'Guests'],
            $has(['restaurant', 'cafe', 'café', 'coffee', 'bakery', 'bar', 'retail', 'shop', 'store', 'ecommerce', 'boutique', 'food', 'sourdough']) => ['guests', 'Customer', 'Customers'],
            $has(['school', 'tutor', 'course', 'training', 'childcare', 'academy', 'learning', 'education', 'daycare']) => ['enrolment', 'Student', 'Students'],
            $has(['news', 'media', 'magazine', 'publisher', 'podcast']) => ['audience', 'Contact', 'Contacts'],
            $has(['legal', 'law', 'attorney', 'lawyer']) => ['projects', 'Client', 'Clients'],
            $has(['home_services', 'home services', 'construction', 'builder', 'plumb', 'electric', 'interior', 'architect', 'catering', 'caterer', 'chef', 'event', 'wedding',
                  'agency', 'marketing', 'consult', 'accounting', 'it services', 'it_services', 'software', 'design', 'photograph', 'clean', 'landscap', 'contractor']) => ['projects', 'Client', 'Clients'],
            default => ['general', 'Client', 'Clients'],
        };
    }

    /** The setup a business uses: its pack (automatic from industry unless the owner chose) with the owner's words. */
    public static function forBusiness(?int $businessId): array
    {
        $b = $businessId ? DB::table('businesses')->where('id', $businessId)->whereNull('deleted_at')->first(['id', 'name', 'industry', 'settings_json']) : null;
        $set = $b ? ((json_decode((string) $b->settings_json, true) ?: [])['crm'] ?? []) : [];
        [$auto, $one, $many] = self::forIndustry($b->industry ?? null);
        $key = isset(self::all()[$set['pack'] ?? '']) ? $set['pack'] : $auto;
        $pack = self::all()[$key];
        if (($set['pack'] ?? null) === null || $key === $auto) { $pack['one'] = $one; $pack['many'] = $many; }
        if (! empty($set['one'])) $pack['one'] = mb_substr((string) $set['one'], 0, 30);
        if (! empty($set['many'])) $pack['many'] = mb_substr((string) $set['many'], 0, 30);
        return ['key' => $key, 'auto' => $auto, 'chosen' => ! empty($set['pack'])] + $pack;
    }

    /** The stage a lead stands in for its pack: its own stage when set, else the first stage of its status. */
    public static function stageOf(array $pack, ?string $stage, ?string $status): string
    {
        foreach ($pack['stages'] as $s) if ($s['key'] === $stage) return $s['key'];
        foreach ($pack['stages'] as $s) if ($s['status'] === ($status ?: 'new')) return $s['key'];
        return $pack['stages'][0]['key'];
    }

    public static function statusOf(array $pack, string $stage): ?string
    {
        foreach ($pack['stages'] as $s) if ($s['key'] === $stage) return $s['status'];
        return null;
    }
}
