<?php

declare(strict_types=1);

namespace App\Modules\SalesAgents\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\SalesAgents\Models\SalesAgent;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Sales agents and order attribution (spec §52). Attribution is fixed once
 * an order is confirmed: the commission is calculated then (§52.2).
 */
final readonly class SalesAgentService
{
    public function __construct(private FeatureAccessService $features) {}

    /**
     * Crediting new sales needs the module enabled; commissions already
     * earned can still be paid while it winds down.
     */
    public function enabled(): bool
    {
        $tenant = tenant();

        return $tenant instanceof Tenant && $this->features->state($tenant, 'sales_agents') === ModuleState::Enabled;
    }

    /**
     * @param  array<string, mixed>  $data  name, phone, email?, agent_code? (generated when absent), commission_rate?, user_id?
     */
    public function createAgent(array $data): SalesAgent
    {
        $validated = Validator::make($data, $this->rules(null))->validate();

        $agent = new SalesAgent;
        $agent->forceFill([
            ...$validated,
            'email' => isset($validated['email']) ? strtolower((string) $validated['email']) : null,
            'agent_code' => isset($validated['agent_code']) ? strtoupper((string) $validated['agent_code']) : $this->newCode((string) $validated['name']),
            'status' => SalesAgent::ACTIVE,
        ])->save();

        return $agent->refresh();
    }

    /**
     * @param  array<string, mixed>  $data  name?, phone?, email?, agent_code?, commission_rate?, user_id?, status?
     */
    public function updateAgent(SalesAgent $agent, array $data): SalesAgent
    {
        $validated = Validator::make($data, [
            ...$this->rules($agent),
            'status' => ['sometimes', Rule::in([SalesAgent::ACTIVE, SalesAgent::INACTIVE])],
        ])->validate();

        if (array_key_exists('email', $validated)) {
            $validated['email'] = $validated['email'] === null ? null : strtolower((string) $validated['email']);
        }

        if (isset($validated['agent_code'])) {
            $validated['agent_code'] = strtoupper((string) $validated['agent_code']);
        }

        $agent->forceFill($validated)->save();

        return $agent;
    }

    public function deactivateAgent(SalesAgent $agent): void
    {
        $agent->forceFill(['status' => SalesAgent::INACTIVE])->save();
    }

    /**
     * @param  array{status?: string, search?: string}  $filters
     * @return Collection<int, SalesAgent>
     */
    public function listAgents(array $filters = []): Collection
    {
        return SalesAgent::query()->with('user:id,name')
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['search']), static function ($q) use ($filters): void {
                $like = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
                $q->where(static fn ($w) => $w->where('name', 'like', $like)->orWhere('agent_code', strtoupper((string) $filters['search']))
                    ->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like));
            })
            ->orderBy('name')->get();
    }

    public function getAgentByCode(string $code): ?SalesAgent
    {
        return SalesAgent::query()->where('agent_code', strtoupper(trim($code)))->first();
    }

    /**
     * The active agent for a checkout code or a POS/staff id (§52.2). Null
     * when nothing was sent, or when the module is off and the code is
     * only a leftover referral.
     */
    public function resolveForSale(?string $code = null, ?int $agentId = null): ?SalesAgent
    {
        if (($code === null || trim($code) === '') && $agentId === null) {
            return null;
        }

        if (! $this->enabled()) {
            if ($agentId !== null) {
                throw ApiException::unprocessable('feature_unavailable', 'Sales agents are not enabled for this store.');
            }

            return null;
        }

        $agent = $agentId !== null ? SalesAgent::query()->find($agentId) : $this->getAgentByCode((string) $code);

        if ($agent === null || $agent->status !== SalesAgent::ACTIVE) {
            throw ApiException::unprocessable('sales_agent_invalid', 'This sales agent code is not valid.');
        }

        return $agent;
    }

    /**
     * Staff attribution (§52.2): only before confirmation. Null clears it.
     */
    public function attributeOrder(Order $order, ?SalesAgent $agent): Order
    {
        if ($agent !== null && $agent->status !== SalesAgent::ACTIVE) {
            throw ApiException::unprocessable('sales_agent_invalid', 'This sales agent is inactive.');
        }

        return DB::connection('tenant')->transaction(function () use ($order, $agent): Order {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->confirmed_at !== null || $locked->status === Order::CANCELLED) {
                throw ApiException::unprocessable('order_confirmed', 'The sales agent can be changed only before the order is confirmed.');
            }

            $locked->forceFill(['sales_agent_id' => $agent?->id])->save();

            return $locked;
        });
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(?SalesAgent $agent): array
    {
        $sometimes = $agent === null ? 'required' : 'sometimes';

        return [
            'name' => [$sometimes, 'string', 'max:120'],
            'phone' => [$sometimes, 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'agent_code' => ['sometimes', 'string', 'min:3', 'max:32', 'alpha_num', Rule::unique('tenant.sales_agents', 'agent_code')->ignore($agent?->id)],
            'commission_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.users', 'id'), Rule::unique('tenant.sales_agents', 'user_id')->ignore($agent?->id)],
        ];
    }

    /**
     * Up to four letters of the name and four characters: ADA-style codes
     * are easy to say at the till.
     */
    private function newCode(string $name): string
    {
        $prefix = substr((string) preg_replace('/[^A-Z]/', '', strtoupper(Str::ascii($name))), 0, 4) ?: 'AGT';

        do {
            $code = $prefix.strtoupper(Str::random(4));
        } while (preg_match('/^[A-Z0-9]+$/', $code) !== 1 || SalesAgent::query()->where('agent_code', $code)->exists());

        return $code;
    }
}
