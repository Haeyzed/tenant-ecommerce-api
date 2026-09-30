<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\SettingsMediaService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * The store's image settings (BG-12): store logo, favicon, share image.
 */
final class SettingsMediaController extends Controller
{
    public function __construct(private readonly SettingsMediaService $media) {}

    /**
     * Multipart: setting (store_logo, favicon, seo_share_image), image.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'setting' => ['required', Rule::in(array_keys(SettingsMediaService::TENANT))],
            'image' => ['required', 'file'],
        ]);
        /** @var UploadedFile $file */
        $file = $request->file('image');

        return APIResponse::created($this->media->upload($validated['setting'], $file, false), 'Image uploaded');
    }

    public function destroy(string $setting): JsonResponse
    {
        $this->media->remove($setting, false);

        return APIResponse::success(null, 'Image removed');
    }
}
