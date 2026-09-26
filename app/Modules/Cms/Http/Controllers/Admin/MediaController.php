<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Models\CmsBanner;
use App\Modules\Cms\Models\CmsBlogPost;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Models\CmsTestimonial;
use App\Modules\Cms\Support\CmsMedia;
use App\Modules\Settings\Services\StorefrontConfigService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Images of CMS records (multipart field "image"). Each record has one
 * image of its kind; pages also collect the images their sections use.
 */
final class MediaController extends Controller
{
    public function __construct(private readonly CmsMedia $media) {}

    public function pageImage(Request $request, CmsPage $page): JsonResponse
    {
        return $this->single($request, $page, 'og_image');
    }

    public function pageSectionMedia(Request $request, CmsPage $page): JsonResponse
    {
        $media = $this->media->store($page, 'section_media', $this->file($request));

        return APIResponse::created(['id' => $media->id, 'url' => $media->getUrl()], 'Image uploaded; use its id as a section media_id');
    }

    public function postImage(Request $request, CmsBlogPost $post): JsonResponse
    {
        return $this->single($request, $post, 'cover_image');
    }

    public function bannerImage(Request $request, CmsBanner $banner): JsonResponse
    {
        $response = $this->single($request, $banner, 'image');

        if (tenancy()->initialized) {
            StorefrontConfigService::flush();
        }

        return $response;
    }

    public function testimonialImage(Request $request, CmsTestimonial $testimonial): JsonResponse
    {
        return $this->single($request, $testimonial, 'photo');
    }

    private function single(Request $request, CmsPage|CmsBlogPost|CmsBanner|CmsTestimonial $record, string $collection): JsonResponse
    {
        $media = $this->media->store($record, $collection, $this->file($request));

        return APIResponse::created(['id' => $media->id, 'url' => $media->getUrl()], 'Image uploaded');
    }

    private function file(Request $request): UploadedFile
    {
        $request->validate(['image' => ['required', 'file']]);

        /** @var UploadedFile */
        return $request->file('image');
    }
}
