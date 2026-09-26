<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A named invoice layout and numbering scheme (spec §43.1). Exactly one is
 * the default; invoice numbers come from the default.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $invoice_type a4 | 58mm | 80mm
 * @property bool $is_default
 * @property string|null $prefix
 * @property string $numbering_type sequential | random
 * @property int $start_number
 * @property int|null $last_number
 * @property string|null $header_text
 * @property string|null $footer_text
 * @property string|null $payment_notes
 * @property string|null $sales_notes
 * @property int|null $logo_media_id
 * @property string|null $primary_color
 * @property string|null $date_format
 */
class InvoiceTemplate extends Model implements AuditableContract
{
    use Auditable;

    public const array TYPES = ['a4', '58mm', '80mm'];

    public const array FLAGS = [
        'show_barcode', 'show_qr_code', 'show_description', 'show_amount_in_words', 'show_warehouse_info', 'show_bill_to_info',
        'show_payment_details', 'show_payment_notes', 'show_reference_number', 'show_generated_invoice_number', 'show_total_due',
        'show_registration_number', 'show_sales_notes', 'show_customer_name', 'signature_enabled',
    ];

    protected $connection = 'tenant';

    protected $fillable = [
        'name', 'description', 'invoice_type', 'prefix', 'numbering_type', 'start_number', 'header_text', 'footer_text', 'payment_notes',
        'sales_notes', 'logo_media_id', 'logo_height', 'logo_width', 'primary_color', 'date_format', ...self::FLAGS,
    ];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['last_number'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'start_number' => 'integer',
            'last_number' => 'integer',
            'logo_media_id' => 'integer',
            'logo_height' => 'decimal:2',
            'logo_width' => 'decimal:2',
            ...array_fill_keys(self::FLAGS, 'boolean'),
        ];
    }

    public function isThermal(): bool
    {
        return $this->invoice_type !== 'a4';
    }
}
