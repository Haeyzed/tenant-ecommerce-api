<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\BookingStaff;
use App\Modules\Catalog\Models\Product;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bookable staff (spec §66.3). Deactivating keeps the bookings already
 * made; no new slots are offered for an inactive member.
 */
final readonly class BookingStaffService
{
    public function createStaff(User $user): BookingStaff
    {
        if (! $user->is_active) {
            throw ApiException::unprocessable('user_inactive', 'Choose an active staff user.');
        }

        if (BookingStaff::query()->where('user_id', $user->id)->exists()) {
            throw ApiException::conflict('booking_staff_exists', 'This user is already bookable.');
        }

        $staff = new BookingStaff;
        $staff->forceFill(['user_id' => $user->id, 'is_active' => true])->save();

        return $staff->load(['user:id,name,email', 'availability', 'products:id,name']);
    }

    /**
     * @param  array{is_active?: bool}  $data
     */
    public function updateStaff(BookingStaff $staff, array $data): BookingStaff
    {
        if (array_key_exists('is_active', $data)) {
            $staff->forceFill(['is_active' => (bool) $data['is_active']])->save();
        }

        return $staff->load(['user:id,name,email', 'availability', 'products:id,name']);
    }

    public function deactivateStaff(BookingStaff $staff): BookingStaff
    {
        return $this->updateStaff($staff, ['is_active' => false]);
    }

    public function attachToProduct(Product $product, BookingStaff $staff): void
    {
        if (! $product->is_bookable) {
            throw ApiException::unprocessable('product_not_bookable', 'Make this service bookable first.');
        }

        DB::connection('tenant')->table('product_booking_staff')->insertOrIgnore(['product_id' => $product->id, 'booking_staff_id' => $staff->id]);
    }

    public function detachFromProduct(Product $product, BookingStaff $staff): void
    {
        DB::connection('tenant')->table('product_booking_staff')->where('product_id', $product->id)->where('booking_staff_id', $staff->id)->delete();
    }

    /**
     * @param  array{product_id?: int, is_active?: bool}  $filters
     * @return Collection<int, BookingStaff>
     */
    public function listStaff(array $filters = []): Collection
    {
        return BookingStaff::query()->with(['user:id,name,email', 'availability', 'products:id,name'])
            ->when(isset($filters['product_id']), static fn ($q) => $q->whereHas('products', static fn ($p) => $p->whereKey($filters['product_id'])))
            ->when(isset($filters['is_active']), static fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('id')->get();
    }
}
