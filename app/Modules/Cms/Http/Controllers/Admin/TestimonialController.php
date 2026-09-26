<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Http\CmsPresenter;
use App\Modules\Cms\Models\CmsTestimonial;
use App\Modules\Cms\Services\CmsTestimonialService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TestimonialController extends Controller
{
    public function __construct(private readonly CmsTestimonialService $testimonials) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->testimonials->listTestimonials(false)->map(static fn (CmsTestimonial $t): array => CmsPresenter::testimonial($t, false))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created(CmsPresenter::testimonial($this->testimonials->createTestimonial($request->all()), false), 'Testimonial created');
    }

    public function update(Request $request, CmsTestimonial $testimonial): JsonResponse
    {
        return APIResponse::success(CmsPresenter::testimonial($this->testimonials->updateTestimonial($testimonial, $request->all()), false), 'Testimonial updated');
    }

    public function destroy(CmsTestimonial $testimonial): JsonResponse
    {
        $this->testimonials->deleteTestimonial($testimonial);

        return APIResponse::noContent('Testimonial deleted');
    }
}
