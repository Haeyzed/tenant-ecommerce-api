<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Pos\Http\PosPresenter;
use App\Modules\Pos\Models\PosRegister;
use App\Modules\Pos\Services\PosSaleService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Card-terminal charges (spec §51.4, A-35): started and polled before the
 * sale; the sale then names the charge's reference as a card_terminal
 * payment.
 */
final class TerminalChargeController extends Controller
{
    public function __construct(
        private readonly PosSaleService $sales,
        private readonly PosPresenter $presenter,
    ) {}

    /**
     * Body: register_id, amount.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'register_id' => ['required', 'integer', Rule::exists('tenant.pos_registers', 'id')],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $charge = $this->sales->initiateTerminalCharge(PosRegister::query()->findOrFail($validated['register_id']), (string) $validated['amount'], $user);

        return APIResponse::created($this->presenter->charge($charge), 'Charge sent to the terminal');
    }

    /**
     * Query: register_id.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $registerId = (int) $request->validate(['register_id' => ['required', 'integer']])['register_id'];

        return APIResponse::success($this->presenter->charge($this->sales->verifyTerminalCharge(PosRegister::query()->findOrFail($registerId), $reference)));
    }
}
