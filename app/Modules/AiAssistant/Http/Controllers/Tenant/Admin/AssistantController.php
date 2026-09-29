<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\AiAssistant\Services\AiAssistantService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/admin/ai-assistant/ask (spec §62.4). Body: question. Answers
 * {matched, intent_key, answer, data}; an unmatched question gets a
 * clarifying message, never a guess.
 */
final class AssistantController extends Controller
{
    public function __construct(private readonly AiAssistantService $assistant) {}

    public function ask(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return APIResponse::success($this->assistant->askQuestion((string) $request->input('question', ''), $user));
    }
}
