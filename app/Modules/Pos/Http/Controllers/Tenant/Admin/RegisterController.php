<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Pos\Http\PosPresenter;
use App\Modules\Pos\Models\PosRegister;
use App\Modules\Pos\Services\PosRegisterService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registers (spec §51.8).
 */
final class RegisterController extends Controller
{
    public function __construct(
        private readonly PosRegisterService $registers,
        private readonly PosPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'warehouse_id' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return APIResponse::success($this->registers->listRegisters($filters)->map(fn (PosRegister $r): array => $this->presenter->register($r))->values());
    }

    /**
     * Body: warehouse_id, name.
     */
    public function store(Request $request): JsonResponse
    {
        $register = $this->registers->createRegister($request->only(['warehouse_id', 'name']));

        return APIResponse::created($this->presenter->register($register->load('warehouse')), 'Register created');
    }

    /**
     * Body: name?, warehouse_id?
     */
    public function update(Request $request, PosRegister $register): JsonResponse
    {
        return APIResponse::success($this->presenter->register($this->registers->updateRegister($register, $request->only(['name', 'warehouse_id']))->load('warehouse')), 'Register updated');
    }

    public function destroy(PosRegister $register): JsonResponse
    {
        return APIResponse::success($this->presenter->register($this->registers->deactivateRegister($register)->load('warehouse')), 'Register deactivated');
    }

    public function activate(PosRegister $register): JsonResponse
    {
        return APIResponse::success($this->presenter->register($this->registers->activateRegister($register)->load('warehouse')), 'Register activated');
    }

    /**
     * Body: provider (moniepoint, opay, stripe_terminal; null clears), credentials{…}.
     * moniepoint: client_id, client_secret, terminal_serial. opay: merchant_id,
     * secret_key, terminal_serial. stripe_terminal: secret_key, reader_id.
     */
    public function terminal(Request $request, PosRegister $register): JsonResponse
    {
        $provider = $request->input('provider');
        $credentials = $request->input('credentials');

        $register = $this->registers->setTerminal($register, is_string($provider) ? $provider : null, is_array($credentials) ? $credentials : null);

        return APIResponse::success($this->presenter->register($register->load('warehouse')), 'Terminal saved');
    }
}
