<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Pos\Http\PosPresenter;
use App\Modules\Pos\Models\PosRegister;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Pos\Services\PosSessionService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cash sessions (spec §51.1, §51.5, §51.8).
 */
final class SessionController extends Controller
{
    public function __construct(
        private readonly PosSessionService $sessions,
        private readonly PosPresenter $presenter,
    ) {}

    public function current(PosRegister $register): JsonResponse
    {
        $session = $this->sessions->getCurrentSession($register);

        return APIResponse::success($session === null ? null : $this->presenter->session($session->load('register'), true));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'register_id' => ['sometimes', 'integer'],
            'cashier_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in([PosSession::OPEN, PosSession::CLOSED])],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->sessions->listSessions($filters)->through(fn (PosSession $s): array => $this->presenter->session($s)));
    }

    /**
     * Body: register_id, opening_cash_float.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'register_id' => ['required', 'integer', Rule::exists('tenant.pos_registers', 'id')],
            'opening_cash_float' => ['required', 'numeric', 'min:0'],
        ]);

        $session = $this->sessions->openSession(PosRegister::query()->findOrFail($validated['register_id']), $this->user($request), (string) $validated['opening_cash_float']);

        return APIResponse::created($this->presenter->session($session->load(['register', 'openedBy'])), 'Session opened');
    }

    public function show(PosSession $session): JsonResponse
    {
        return APIResponse::success($this->presenter->session($session->load(['register', 'openedBy', 'closedBy']), true));
    }

    /**
     * Body: closing_cash_float (the counted drawer).
     */
    public function close(Request $request, PosSession $session): JsonResponse
    {
        $counted = (string) $request->validate(['closing_cash_float' => ['required', 'numeric', 'min:0']])['closing_cash_float'];
        $session = $this->sessions->closeSession($session, $counted, $this->user($request));

        return APIResponse::success($this->presenter->session($session->load(['register', 'openedBy', 'closedBy']), true), 'Session closed');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
