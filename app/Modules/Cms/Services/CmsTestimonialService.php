<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Models\CmsTestimonial;
use Illuminate\Support\Collection;

/**
 * Hand-curated testimonials (spec §24.4).
 */
final readonly class CmsTestimonialService
{
    /**
     * @return Collection<int, CmsTestimonial>
     */
    public function listTestimonials(bool $activeOnly, bool $featuredOnly = false): Collection
    {
        return CmsTestimonial::query()
            ->when($activeOnly, static fn ($q) => $q->where('is_active', true))
            ->when($featuredOnly, static fn ($q) => $q->where('is_featured', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, CmsTestimonial>
     */
    public function getFeaturedTestimonials(int $limit = 12): Collection
    {
        return $this->listTestimonials(true, true)->take(min(12, $limit))->values();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createTestimonial(array $data): CmsTestimonial
    {
        /** @var CmsTestimonial $testimonial */
        $testimonial = CmsTestimonial::query()->create($this->validate($data, true));

        return $testimonial;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTestimonial(CmsTestimonial $testimonial, array $data): CmsTestimonial
    {
        $testimonial->fill($this->validate($data, false))->save();

        return $testimonial;
    }

    public function deleteTestimonial(CmsTestimonial $testimonial): void
    {
        $testimonial->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return validator($data, [
            'customer_name' => [$req, 'string', 'max:120'],
            'customer_title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'quote' => [$req, 'string', 'max:2000'],
            'rating' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();
    }
}
