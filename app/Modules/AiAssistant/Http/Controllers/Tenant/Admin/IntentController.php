<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\AiAssistant\Models\AiAssistantIntent;
use App\Modules\AiAssistant\Services\AiAssistantService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IntentController extends Controller
{
    public function __construct(private readonly AiAssistantService $assistant) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->assistant->listIntents()->map(fn (AiAssistantIntent $i): array => $this->present($i))->all());
    }

    /**
     * Body: is_active.
     */
    public function toggle(Request $request, AiAssistantIntent $intent): JsonResponse
    {
        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        return APIResponse::success($this->present($this->assistant->toggleIntent($intent, $active)), $active ? 'Intent enabled' : 'Intent disabled');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AiAssistantIntent $intent): array
    {
        return [
            'id' => $intent->id,
            'intent_key' => $intent->intent_key,
            'sample_phrases' => $intent->sample_phrases,
            'required_feature' => $intent->required_feature,
            'is_active' => $intent->is_active,
        ];
    }
}
