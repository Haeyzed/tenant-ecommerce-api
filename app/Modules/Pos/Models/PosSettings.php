<?php

declare(strict_types=1);

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * The POS screen's configuration (spec §51.7), a singleton row.
 *
 * @property int $id
 * @property int|null $default_warehouse_id
 * @property int|null $default_customer_id the standing walk-in customer
 * @property int|null $default_cashier_user_id
 * @property int $products_per_page
 * @property bool $touchscreen_keyboard_enabled
 * @property bool $table_management_enabled
 * @property bool $send_sms_after_sale
 * @property bool $cash_register_enabled
 * @property bool $print_receipt_by_default
 * @property bool $play_sound_on_sale
 * @property list<string> $enabled_payment_methods
 */
class PosSettings extends Model implements AuditableContract
{
    use Auditable;

    /** The POS methods of §51.2. */
    public const array METHODS = ['cash', 'card_terminal', 'bank_transfer', 'cheque', 'gift_card', 'reward_points', 'credit_sale'];

    protected $connection = 'tenant';

    protected $table = 'pos_settings';

    protected $fillable = [];

    protected $casts = [
        'default_warehouse_id' => 'integer',
        'default_customer_id' => 'integer',
        'default_cashier_user_id' => 'integer',
        'products_per_page' => 'integer',
        'touchscreen_keyboard_enabled' => 'boolean',
        'table_management_enabled' => 'boolean',
        'send_sms_after_sale' => 'boolean',
        'cash_register_enabled' => 'boolean',
        'print_receipt_by_default' => 'boolean',
        'play_sound_on_sale' => 'boolean',
        'enabled_payment_methods' => 'array',
    ];
}
