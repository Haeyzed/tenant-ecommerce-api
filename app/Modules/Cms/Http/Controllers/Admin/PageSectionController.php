<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Http\CmsPresenter;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Services\CmsPageSectionService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PageSectionController extends Controller
{
    public function sync(Request $request, CmsPage $page, CmsPageSectionService $sections): JsonResponse
    {
        $data = $request->validate(['sections' => ['present', 'array', 'max:50']]);

        return APIResponse::success(CmsPresenter::page($sections->syncSections($page, $data['sections'])->load('media'), false), 'Sections saved');
    }
}
