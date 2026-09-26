<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Services\PushDeviceTokenService;
use App\Shared\Http\APIResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service FCM token registration for any tenant actor (spec §16.5).
 */
final class PushDeviceTokenController extends Controller
{
    public function __construct(private readonly PushDeviceTokenService $tokens) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'in:ios,android,web'],
        ]);

        $this->tokens->register($this->actor($request), $validated['token'], $validated['platform']);

        return APIResponse::created(null, 'Device registered');
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'max:512']]);
        $this->tokens->unregister($this->actor($request), $validated['token']);

        return APIResponse::noContent('Device removed');
    }

    private function actor(Request $request): Model
    {
        /** @var Model */
        return $request->user();
    }
}
