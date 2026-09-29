<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Services;

use App\Contracts\Approvable;
use App\Modules\Approvals\Support\ApprovalGate;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Marketplace\Models\SellerGroup;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Plans\Services\PlanLimitService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Sellers (spec §50.1, §50.2, §50.6). Registration creates a pending
 * application; approval (directly, or through a seller_application
 * workflow, §60) lets the seller log in and counts against max_sellers.
 */
final readonly class SellerService implements Approvable
{
    public function __construct(
        private NotificationDispatchService $notifications,
        private ApprovalGate $approvals,
        private PlanLimitService $limits,
        private TenantSettingsService $settings,
    ) {}

    /**
     * Approved sellers: the max_sellers counter (§11.8).
     */
    public static function countApproved(): int
    {
        return Seller::query()->where('status', Seller::APPROVED)->count();
    }

    /**
     * @param  array<string, mixed>  $data  business_name, contact_name?, email, phone?, password, password_confirmation
     */
    public function registerSeller(array $data): Seller
    {
        $validated = Validator::make($data, [
            'business_name' => ['required', 'string', 'max:160'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('tenant.sellers', 'email')],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ])->validate();

        $seller = DB::connection('tenant')->transaction(function () use ($validated): Seller {
            $seller = new Seller;
            $seller->forceFill([
                'business_name' => $validated['business_name'],
                'contact_name' => $validated['contact_name'] ?? null,
                'email' => strtolower((string) $validated['email']),
                'phone' => $validated['phone'] ?? null,
                'password' => $validated['password'],
                'status' => Seller::PENDING,
            ])->save();

            // A seller_application workflow, when one matches, decides the application (§50.2).
            $this->approvals->hold('seller_application', $seller);

            return $seller;
        });

        $this->notifications->dispatch('seller.new_seller_application', null, [
            'seller_name' => $seller->business_name,
            'store_name' => Customer::storeName(),
        ]);

        return $seller;
    }

    public function approveSeller(Seller $seller): Seller
    {
        $this->approvals->assertNoPending($seller);

        return $this->approve($seller);
    }

    public function rejectSeller(Seller $seller, string $reason): Seller
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']])->validate();
        $this->approvals->assertNoPending($seller);

        return $this->reject($seller, $reason);
    }

    /**
     * Revokes the seller's sessions and takes its products off sale;
     * approving again does not put them back on sale (§50.2 step 4).
     */
    public function suspendSeller(Seller $seller): Seller
    {
        return DB::connection('tenant')->transaction(function () use ($seller): Seller {
            /** @var Seller $locked */
            $locked = Seller::query()->lockForUpdate()->findOrFail($seller->id);

            if ($locked->status !== Seller::APPROVED) {
                throw ApiException::invalidTransition($locked->status, Seller::SUSPENDED);
            }

            $locked->forceFill(['status' => Seller::SUSPENDED])->save();
            $locked->tokens()->delete();
            Product::query()->where('seller_id', $locked->id)->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);

            return $locked;
        });
    }

    public function updateCommissionRate(Seller $seller, ?string $rate): Seller
    {
        Validator::make(['commission_rate' => $rate], ['commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4']])->validate();
        $seller->forceFill(['commission_rate' => $rate])->save();

        return $seller;
    }

    public function assignToGroup(Seller $seller, ?SellerGroup $group): Seller
    {
        $seller->forceFill(['seller_group_id' => $group?->id])->save();

        return $seller;
    }

    /**
     * The seller's own rate, else its group's, else the store default (§50.3).
     */
    public function getEffectiveCommissionRate(Seller $seller): string
    {
        $seller->loadMissing('group');

        return Money::normalize((string) ($seller->commission_rate ?? $seller->group?->default_commission_rate
            ?? $this->settings->get('default_seller_commission_rate', '0')));
    }

    /**
     * @param  array{status?: string, seller_group_id?: int, search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Seller>
     */
    public function listSellers(array $filters = []): LengthAwarePaginator
    {
        return Seller::query()->with('group:id,name,default_commission_rate')->withCount('products')
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['seller_group_id']), static fn ($q) => $q->where('seller_group_id', $filters['seller_group_id']))
            ->when(isset($filters['search']), static function ($q) use ($filters): void {
                $like = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
                $q->where(static fn ($w) => $w->where('business_name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * @return Collection<int, Product>
     */
    public function listSellerProducts(Seller $seller): Collection
    {
        return Product::query()->where('seller_id', $seller->id)->orderByDesc('id')->get();
    }

    /**
     * Self-service (§50.6): never the status, group or rate.
     *
     * @param  array<string, mixed>  $data  business_name?, contact_name?, phone?
     */
    public function updateProfile(Seller $seller, array $data): Seller
    {
        $validated = Validator::make($data, [
            'business_name' => ['sometimes', 'string', 'max:160'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ])->validate();

        $seller->forceFill($validated)->save();

        return $seller;
    }

    // ---- Approvals (§60, module_key seller_application) ----------------

    public function onApprovalGranted(Model $record): void
    {
        /** @var Seller $record */
        $this->approve($record);
    }

    public function onApprovalRejected(Model $record, ?string $note): void
    {
        /** @var Seller $record */
        $this->reject($record, $note ?? 'The application was not approved.');
    }

    public function approvalSubject(Model $record): string
    {
        /** @var Seller $record */
        return 'seller application '.$record->business_name;
    }

    public function approvalFacts(Model $record): array
    {
        return [];
    }

    // ---- Internals ------------------------------------------------------------

    /**
     * Pending or suspended → approved, within max_sellers (pending
     * applications never count, so sign-ups are never blocked, §50.6).
     */
    private function approve(Seller $seller): Seller
    {
        $approved = DB::connection('tenant')->transaction(function () use ($seller): Seller {
            /** @var Seller $locked */
            $locked = Seller::query()->lockForUpdate()->findOrFail($seller->id);

            if (! in_array($locked->status, [Seller::PENDING, Seller::SUSPENDED], true)) {
                throw ApiException::invalidTransition($locked->status, Seller::APPROVED);
            }

            $tenant = tenant();
            $limit = $tenant instanceof Tenant ? $this->limits->getLimit($tenant, 'max_sellers') : null;

            if ($limit !== null && self::countApproved() >= $limit) {
                throw ApiException::forbidden('limit_reached', 'Upgrade your plan to approve more sellers.', ['limit' => 'max_sellers', 'limit_value' => $limit]);
            }

            $locked->forceFill(['status' => Seller::APPROVED, 'approved_at' => now(), 'rejection_reason' => null])->save();

            return $locked;
        });

        $this->notifications->dispatch('seller.application_approved', $approved, [
            'seller_name' => $approved->business_name,
            'store_name' => Customer::storeName(),
        ]);

        return $approved;
    }

    private function reject(Seller $seller, string $reason): Seller
    {
        $rejected = DB::connection('tenant')->transaction(function () use ($seller, $reason): Seller {
            /** @var Seller $locked */
            $locked = Seller::query()->lockForUpdate()->findOrFail($seller->id);

            if ($locked->status !== Seller::PENDING) {
                throw ApiException::invalidTransition($locked->status, Seller::REJECTED);
            }

            $locked->forceFill(['status' => Seller::REJECTED, 'rejection_reason' => mb_substr($reason, 0, 255)])->save();

            return $locked;
        });

        $this->notifications->dispatch('seller.application_rejected', $rejected, [
            'seller_name' => $rejected->business_name,
            'store_name' => Customer::storeName(),
            'reason' => (string) $rejected->rejection_reason,
        ]);

        return $rejected;
    }
}
