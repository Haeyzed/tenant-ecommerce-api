<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Concerns;

use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Services\NotificationMatrixService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Channel matrix routes of spec §17.7, for the scope of the current context.
 */
trait ManagesMatrix
{
    public function index(NotificationMatrixService $matrix): JsonResponse
    {
        return APIResponse::success($matrix->listMatrix(NotificationScope::current()));
    }

    public function update(Request $request, string $templateKey, NotificationMatrixService $matrix): JsonResponse
    {
        $validated = $request->validate([
            'channels' => ['sometimes', 'array'],
            'audience' => ['sometimes', 'array', 'min:1'],
        ]);

        $scope = NotificationScope::current();

        DB::connection($scope->connection())->transaction(static function () use ($validated, $templateKey, $matrix, $scope): void {
            if (isset($validated['channels'])) {
                $matrix->updateChannels($templateKey, $validated['channels'], $scope);
            }

            if (isset($validated['audience'])) {
                $matrix->updateAudience($templateKey, array_values($validated['audience']), $scope);
            }
        });

        return APIResponse::success(
            collect($matrix->listMatrix($scope))->firstWhere('key', $templateKey),
            'Notification settings updated',
        );
    }
}
