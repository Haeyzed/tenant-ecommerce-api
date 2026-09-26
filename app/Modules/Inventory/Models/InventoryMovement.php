<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One row of the append-only stock ledger (spec §32.8). Rows are never
 * updated or deleted.
 *
 * @property int $id
 * @property int $inventory_id
 * @property int $warehouse_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $movement_type
 * @property string $quantity_delta
 * @property string $reserved_delta
 * @property string $quantity_after
 * @property string $reserved_after
 * @property string|null $unit_cost_snapshot
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $reason
 * @property int|null $user_id
 * @property Carbon $created_at
 */
class InventoryMovement extends Model
{
    public const array TYPES = [
        'reserve', 'release', 'deduct', 'restock_return', 'restock_cancel', 'purchase_receipt',
        'purchase_return', 'transfer_out', 'transfer_in', 'adjustment_in', 'adjustment_out',
        'work_order_consume', 'work_order_output', 'repair_consume', 'repair_return', 'reservation_move',
    ];

    public const null UPDATED_AT = null;

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'inventory_id' => 'integer',
        'warehouse_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'quantity_delta' => 'decimal:3',
        'reserved_delta' => 'decimal:3',
        'quantity_after' => 'decimal:3',
        'reserved_after' => 'decimal:3',
        'unit_cost_snapshot' => 'decimal:4',
        'reference_id' => 'integer',
        'user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('Inventory movements are immutable.'));
        static::deleting(static fn (): never => throw new LogicException('Inventory movements are immutable.'));
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
