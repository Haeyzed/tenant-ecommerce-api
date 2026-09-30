<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\SettingsMediaService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * The platform's image settings (BG-12): logo, favicon, share image.
 */
final class PlatformSettingsMediaController extends Controller
{
    public function __construct(private readonly SettingsMediaService $media) {}

    /**
     * Multipart: setting (platform_logo, platform_favicon, seo_share_image), image.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'setting' => ['required', Rule::in(array_keys(SettingsMediaService::PLATFORM))],
            'image' => ['required', 'file'],
        ]);
        /** @var UploadedFile $file */
        $file = $request->file('image');

        return APIResponse::created($this->media->upload($validated['setting'], $file, true), 'Image uploaded');
    }

    public function destroy(string $setting): JsonResponse
    {
        $this->media->remove($setting, true);

        return APIResponse::success(null, 'Image removed');
    }
}
