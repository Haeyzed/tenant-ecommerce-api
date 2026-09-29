<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\AiAssistant\Models\AiAssistantQueryLog;
use App\Modules\AiAssistant\Services\AiAssistantService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Questions asked (spec §62.3); ?matched=0 lists the unanswered ones.
 */
final class QueryLogController extends Controller
{
    public function __construct(private readonly AiAssistantService $assistant) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'matched' => ['sometimes', 'boolean'],
            'intent_key' => ['sometimes', 'string', 'max:64'],
            'user_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if (array_key_exists('matched', $filters)) {
            $filters['matched'] = filter_var($filters['matched'], FILTER_VALIDATE_BOOLEAN);
        }

        return APIResponse::success($this->assistant->getQueryLogs($filters)->through(static fn (AiAssistantQueryLog $log): array => [
            'id' => $log->id,
            'user' => $log->user === null ? null : ['id' => $log->user->id, 'name' => $log->user->name],
            'question' => $log->raw_query,
            'matched_intent_key' => $log->matched_intent_key,
            'response_summary' => $log->response_summary,
            'created_at' => $log->created_at->toIso8601String(),
        ]));
    }
}
