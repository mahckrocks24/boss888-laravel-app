<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * K7 (2026-09-25) — one evidence-bearing claim about a business.
 *
 * Awards, certifications, memberships, statistics and testimonials are plural
 * and contestable, which is why they are rows with a source rather than columns
 * on the business. Everything singular about identity — phone, address, opening
 * hours — stayed on `businesses` in K4.
 *
 * Nothing here reaches public structured data unless BOTH are true: the claim's
 * source is one the platform will stand behind, and the owner has chosen to
 * publish it. A claim a model wrote is stored, visible and silent.
 */
class BusinessFact extends Model
{
    use SoftDeletes;

    /** The claim types this model exists for. Anything else belongs elsewhere. */
    public const KINDS = ['award', 'certification', 'membership', 'statistic', 'testimonial'];

    /** Sources the platform will publish, and sources it will not. */
    public const SOURCE_PUBLISHABLE = ['owner_stated', 'verified', 'imported'];
    public const SOURCE_WITHHELD = ['observed', 'proposed', 'ai_generated'];

    protected $fillable = [
        'business_id', 'kind', 'label', 'value', 'source_url', 'evidence',
        'source', 'confidence', 'occurred_on', 'verified_at', 'published', 'sort_order',
    ];

    /**
     * The database carries these defaults; the model carries them too, so an
     * unsaved claim answers isPublishable() exactly as a stored one does.
     */
    protected $attributes = [
        'source' => 'proposed',
        'published' => false,
        'sort_order' => 0,
    ];

    protected $casts = [
        'published' => 'boolean',
        'verified_at' => 'datetime',
        'confidence' => 'float',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * May this claim be stated publicly?
     *
     * Two gates, and both must open. The source gate is the platform's: it will
     * not assert something only a model believes. The `published` gate is the
     * owner's: a true fact they would rather not advertise stays private.
     *
     * Unlike K4's identity fields, an unstamped source does NOT default to
     * owner-stated here. Every row in this table is created by something — a
     * form, an import, a scan — and the default is `proposed`, so silence means
     * nobody has vouched for it yet.
     */
    public function isPublishable(): bool
    {
        return $this->published
            && in_array((string) $this->source, self::SOURCE_PUBLISHABLE, true)
            && trim((string) $this->label) !== '';
    }

    /** Claims of one business that may be published, in the owner's order. */
    public static function publishableFor(int $businessId): array
    {
        return static::where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->where('published', true)
            ->whereIn('source', self::SOURCE_PUBLISHABLE)
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->filter(fn (self $f) => $f->isPublishable())
            ->values()
            ->all();
    }

    /** Mark a claim as checked by a person, which is the only thing that verifies it. */
    public function markVerified(?string $sourceUrl = null, ?string $evidence = null): void
    {
        $this->source = 'verified';
        $this->verified_at = now();
        if ($sourceUrl !== null) {
            $this->source_url = $sourceUrl;
        }
        if ($evidence !== null) {
            $this->evidence = $evidence;
        }
    }
}
