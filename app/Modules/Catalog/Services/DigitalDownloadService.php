<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\DigitalDownloadGrant;
use App\Modules\Catalog\Models\DigitalProductFile;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Digital download entitlements (spec §28.4): one grant per file of each
 * digital line on confirmation; revoked on cancellation or full refund.
 * Files are only ever delivered through a 5-minute temporary URL, or
 * streamed when the disk cannot sign one.
 */
final class DigitalDownloadService
{
    /**
     * Creates the order's grants. Idempotent (unique item + file); runs
     * inside the confirmation transaction.
     */
    public function grantForOrder(Order $order): void
    {
        $order->loadMissing('items.product');
        $confirmedAt = $order->confirmed_at ?? now();

        foreach ($order->items as $item) {
            if ($item->product?->product_type !== Product::DIGITAL) {
                continue;
            }

            DigitalProductFile::query()->where('product_id', $item->product_id)->orderBy('id')->get()
                ->each(static function (DigitalProductFile $file) use ($order, $item, $confirmedAt): void {
                    DB::connection('tenant')->table('digital_download_grants')->insertOrIgnore([
                        'order_item_id' => $item->id,
                        'digital_product_file_id' => $file->id,
                        'customer_id' => $order->customer_id,
                        'download_count' => 0,
                        'download_limit' => $file->download_limit,
                        'expires_at' => $file->expires_after_days === null ? null : $confirmedAt->copy()->addDays($file->expires_after_days),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
        }
    }

    public function revokeForOrder(Order $order): int
    {
        return DigitalDownloadGrant::query()
            ->whereIn('order_item_id', OrderItem::query()->select('id')->where('order_id', $order->id))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The caller's grants: a customer's own, or a guest order's by token.
     *
     * @return Collection<int, DigitalDownloadGrant>
     */
    public function listFor(?Customer $customer, ?string $guestToken): Collection
    {
        if ($customer === null && $guestToken === null) {
            return new Collection;
        }

        return DigitalDownloadGrant::query()
            ->with(['orderItem:id,order_id,name_snapshot', 'orderItem.order:id,order_number', 'file.media'])
            ->whereHas('orderItem.order', static function (Builder $orders) use ($customer, $guestToken): void {
                $customer !== null
                    ? $orders->where('customer_id', $customer->id)
                    : $orders->whereNull('customer_id')->where('guest_token', $guestToken);
            })
            ->orderByDesc('id')
            ->get();
    }

    public function assertOwnedBy(DigitalDownloadGrant $grant, ?Customer $customer, ?string $guestToken): void
    {
        $order = $grant->orderItem->order;

        $owned = $customer !== null
            ? $order->customer_id === $customer->id
            : $order->customer_id === null && $order->guest_token !== null && $guestToken !== null && hash_equals($order->guest_token, $guestToken);

        if (! $owned) {
            throw (new ModelNotFoundException)->setModel(DigitalDownloadGrant::class, [$grant->id]);
        }
    }

    /**
     * Counts one download under a row lock, then delivers the file.
     */
    public function download(DigitalDownloadGrant $grant): Response
    {
        DB::connection('tenant')->transaction(static function () use ($grant): void {
            /** @var DigitalDownloadGrant $locked */
            $locked = DigitalDownloadGrant::query()->lockForUpdate()->findOrFail($grant->id);

            if (($reason = $locked->unusableReason()) !== null) {
                throw new ApiException($reason, match ($reason) {
                    'download_revoked' => 'This download is no longer available.',
                    'download_expired' => 'This download link has expired.',
                    default => 'You have reached the download limit for this file.',
                }, 410);
            }

            $locked->forceFill(['download_count' => $locked->download_count + 1])->save();
            $grant->setRawAttributes($locked->getAttributes(), true);
        });

        $media = $grant->file->getFirstMedia('file') ?? throw new ApiException('download_unavailable', 'This file is no longer available.', 410);
        $disk = Storage::disk($media->disk);

        if ($media->disk !== 'local' && $disk->providesTemporaryUrls()) {
            try {
                return redirect()->away($media->getTemporaryUrl(now()->addMinutes(5)));
            } catch (Throwable) {
                // Fall back to streaming below.
            }
        }

        return $disk->download($media->getPathRelativeToRoot(), $media->file_name);
    }
}
