<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\DigitalDownloadGrant;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Documents\Models\InvoiceTemplate;
use App\Modules\Documents\Services\InvoiceTemplateService;
use App\Modules\Documents\Services\ReceiptPrinterService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\OrderPaymentLedgerService;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Database\Seeders\Tenant\DocumentDefaultsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

const DOC_GUEST_TOKEN = 'guest-token-documents-0123456789abcdef';

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    app(DocumentDefaultsSeeder::class)->run();
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];
    $this->guest = ['X-Guest-Token' => DOC_GUEST_TOKEN];

    app(WarehouseService::class)->ensureDefault();
    $this->main = Warehouse::query()->firstOrFail();
    $this->shoe = Product::query()->create(['name' => 'Runner', 'sku' => 'RUN-1', 'barcode' => '5901234123457', 'price' => '40', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->main, $this->shoe, null, '10', 'adjustment_in');
    $this->filesBefore = documentTenantFiles();
});

afterEach(function (): void {
    foreach (array_diff(documentTenantFiles(), $this->filesBefore) as $file) {
        File::delete($file);
        @rmdir(dirname($file));
    }
});

/**
 * @return list<string>
 */
function documentTenantFiles(): array
{
    $root = base_path('storage/tenants/test-tenant-a/app');

    return is_dir($root) ? array_map(static fn ($f): string => $f->getPathname(), File::allFiles($root)) : [];
}

function documentOrder(Product $product, string $total, bool $pay = true, bool $isTest = false): Order
{
    $order = app(OrderService::class)->createOrder([
        'currency_code' => 'NGN',
        'is_test' => $isTest,
        'guest_token' => DOC_GUEST_TOKEN,
        'customer_name' => 'Ada Obi',
        'customer_email' => 'ada@shop.test',
        'lines' => [['product' => $product, 'variant' => null, 'warehouse' => $product->isPhysical() ? test()->main : null, 'quantity' => '1', 'unit_price' => $total, 'price_source' => 'base', 'line_total' => $total]],
        'totals' => ['subtotal' => $total, 'total' => $total],
        'shipping_address' => ['name' => 'Ada Obi', 'line1' => '1 Marina'],
    ]);

    if ($pay) {
        app(OrderPaymentLedgerService::class)->recordPayment($order, ['payment_method' => 'cash', 'amount' => $total], test()->owner);
    }

    return $order->refresh();
}

it('numbers invoices from the default template on confirmation and renders them for staff and the buyer', function (): void {
    $first = documentOrder($this->shoe, '40');
    $second = documentOrder($this->shoe, '40');
    $rehearsal = documentOrder($this->shoe, '40', true, true);
    $unpaid = documentOrder($this->shoe, '40', false);

    expect($first->invoice_number)->toBe('INV-000001')
        ->and($second->invoice_number)->toBe('INV-000002')
        ->and($rehearsal->invoice_number)->toBe('TEST-'.$rehearsal->order_number)
        ->and($unpaid->invoice_number)->toBeNull()
        ->and(InvoiceTemplate::query()->where('is_default', true)->value('last_number'))->toBe(2);

    $pdf = $this->tenantJson('GET', "/api/admin/orders/{$first->id}/invoice", [], $this->staff)->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue()
        ->and($pdf->headers->get('Cache-Control'))->toContain('no-store');

    // The buyer, by guest token; anyone else gets 404; unconfirmed is 422.
    $this->tenantJson('GET', "/api/orders/{$first->id}/invoice", [], $this->guest)->assertOk();
    $this->tenantJson('GET', "/api/orders/{$first->id}/invoice", [], ['X-Guest-Token' => str_repeat('x', 40)])->assertNotFound();
    $this->tenantJson('GET', "/api/orders/{$unpaid->id}/invoice", [], $this->guest)->assertStatus(422)->assertJsonPath('meta.error_code', 'invoice_not_ready');

    // A thermal template renders printable HTML.
    $thermal = $this->tenantJson('POST', '/api/admin/invoice-templates', ['name' => 'Till', 'invoice_type' => '80mm', 'show_barcode' => true], $this->staff)
        ->assertCreated()->assertJsonPath('data.is_default', false)->json('data');
    $html = $this->tenantJson('GET', "/api/admin/orders/{$first->id}/invoice?template_id={$thermal['id']}", [], $this->staff)->assertOk();
    expect($html->headers->get('Content-Type'))->toContain('text/html')
        ->and((string) $html->getContent())->toContain('INV-000001')->toContain('Runner');

    // Packing slips follow the setting.
    $slip = $this->tenantJson('GET', "/api/admin/orders/{$first->id}/packing-slip", [], $this->staff)->assertOk();
    expect($slip->headers->get('Content-Type'))->toBe('application/pdf');
    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('packing_slip_enabled', false);
    $this->tenantJson('GET', "/api/admin/orders/{$first->id}/packing-slip", [], $this->staff)->assertNotFound();
});

it('refuses ZATCA invoices without a VAT number and encodes the QR as TLV', function (): void {
    $order = documentOrder($this->shoe, '40');
    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('saudi_zatca_enabled', true);

    $this->tenantJson('GET', "/api/admin/orders/{$order->id}/invoice", [], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'vat_registration_required');

    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('vat_registration_number', '300000000000003');
    $this->tenantJson('GET', "/api/admin/orders/{$order->id}/invoice", [], $this->staff)->assertOk();

    $tlv = base64_decode(app(InvoiceTemplateService::class)->zatcaPayload('Shop', '300000000000003', Carbon::parse('2026-01-02 03:04:05', 'UTC'), '115', '15'));
    $fields = [];
    for ($i = 0; $i < strlen($tlv);) {
        $tag = ord($tlv[$i]);
        $length = ord($tlv[$i + 1]);
        $fields[$tag] = substr($tlv, $i + 2, $length);
        $i += 2 + $length;
    }
    expect($fields)->toBe([1 => 'Shop', 2 => '300000000000003', 3 => '2026-01-02T03:04:05Z', 4 => '115.0000', 5 => '15.0000']);
});

it('keeps exactly one default template and carries the counter to a new default', function (): void {
    documentOrder($this->shoe, '40');
    $default = InvoiceTemplate::query()->where('is_default', true)->firstOrFail();
    $this->tenantJson('DELETE', "/api/admin/invoice-templates/{$default->id}", [], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'default_template');

    $new = $this->tenantJson('POST', '/api/admin/invoice-templates', ['name' => 'Branded', 'invoice_type' => 'a4', 'prefix' => 'BR-', 'primary_color' => '#AA0000'], $this->staff)
        ->assertCreated()->json('data');
    $this->tenantJson('POST', '/api/admin/invoice-templates', ['name' => 'Bad', 'invoice_type' => 'a5'], $this->staff)->assertStatus(422);
    $this->tenantJson('PATCH', "/api/admin/invoice-templates/{$new['id']}/set-default", [], $this->staff)
        ->assertOk()->assertJsonPath('data.is_default', true)->assertJsonPath('data.last_number', 1);

    expect(documentOrder($this->shoe, '40')->invoice_number)->toBe('BR-000002');
    $this->tenantJson('DELETE', "/api/admin/invoice-templates/{$default->id}", [], $this->staff)->assertOk();
    $this->tenantJson('GET', '/api/admin/invoice-templates', [], $this->staff)->assertOk()->assertJsonCount(1, 'data');
});

it('manages sticker layouts, searches and prints labels, and manages receipt printers', function (): void {
    $layouts = $this->tenantJson('GET', '/api/admin/barcode-settings', [], $this->staff)->assertOk()->assertJsonCount(1, 'data')->json('data');
    $this->tenantJson('DELETE', "/api/admin/barcode-settings/{$layouts[0]['id']}", [], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'last_barcode_setting');

    $layout = [
        'name' => 'Too wide', 'label_layout' => 'continuous', 'top_margin_inches' => 0.2, 'left_margin_inches' => 0.2, 'sticker_width_inches' => 3,
        'sticker_height_inches' => 1, 'paper_width_inches' => 8, 'paper_height_inches' => 11, 'stickers_per_row' => 3, 'row_distance_inches' => 0,
        'column_distance_inches' => 0.1, 'stickers_per_sheet' => 30,
    ];
    $this->tenantJson('POST', '/api/admin/barcode-settings', $layout, $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'stickers_do_not_fit');
    $dymo = $this->tenantJson('POST', '/api/admin/barcode-settings', [...$layout, 'name' => 'Dymo', 'label_layout' => 'dymo', 'stickers_per_row' => 1, 'stickers_per_sheet' => 1], $this->staff)
        ->assertCreated()->json('data');

    $found = $this->tenantJson('GET', '/api/admin/print-barcode/search?q=Runn&warehouse_id='.$this->main->id, [], $this->staff)->assertOk()->json('data');
    expect($found)->toHaveCount(1)
        ->and($found[0]['quantity'])->toBe('10.000')
        ->and($found[0]['barcode'])->toBe('5901234123457')
        ->and($found[0]['price'])->toBe('40.0000');

    $sheet = $this->tenantJson('POST', '/api/admin/print-barcode/generate', [
        'items' => [['product_id' => $this->shoe->id, 'quantity' => 4]], 'show_business_name' => true, 'show_promotional_price' => true,
    ], $this->staff)->assertOk();
    expect($sheet->headers->get('Content-Type'))->toBe('application/pdf')->and(str_starts_with((string) $sheet->getContent(), '%PDF'))->toBeTrue();

    $this->tenantJson('POST', '/api/admin/barcode-settings/generate', ['items' => [['product_id' => $this->shoe->id, 'quantity' => 2]], 'barcode_setting_id' => $dymo['id']], $this->staff)->assertOk();
    $this->tenantJson('POST', '/api/admin/barcode-settings/generate', ['items' => [['product_id' => $this->shoe->id, 'quantity' => 2001]]], $this->staff)->assertStatus(422);

    $this->tenantJson('PATCH', "/api/admin/barcode-settings/{$dymo['id']}/set-default", [], $this->staff)->assertOk()->assertJsonPath('data.is_default', true);
    $this->tenantJson('DELETE', "/api/admin/barcode-settings/{$dymo['id']}", [], $this->staff)->assertOk();
    $this->tenantJson('GET', '/api/admin/barcode-settings', [], $this->staff)->assertOk()->assertJsonPath('data.0.is_default', true);

    // Receipt printers: network printers need an address; lowest active id wins.
    $this->tenantJson('POST', '/api/admin/receipt-printers', ['name' => 'Front', 'warehouse_id' => $this->main->id, 'connection_type' => 'network'], $this->staff)
        ->assertStatus(422)->assertJsonValidationErrors(['ip_address', 'port']);
    $front = $this->tenantJson('POST', '/api/admin/receipt-printers', ['name' => 'Front', 'warehouse_id' => $this->main->id, 'connection_type' => 'network', 'ip_address' => '192.168.1.20', 'port' => 9100], $this->staff)
        ->assertCreated()->assertJsonPath('data.characters_per_line', 42)->json('data');
    $back = $this->tenantJson('POST', '/api/admin/receipt-printers', ['name' => 'Back', 'warehouse_id' => $this->main->id, 'connection_type' => 'usb', 'ip_address' => '10.0.0.1'], $this->staff)
        ->assertCreated()->assertJsonPath('data.ip_address', null)->json('data');

    tenancy()->initialize($this->tenant);
    expect(app(ReceiptPrinterService::class)->getPrinterForWarehouse($this->main)?->id)->toBe($front['id']);
    $this->tenantJson('PATCH', "/api/admin/receipt-printers/{$front['id']}/deactivate", [], $this->staff)->assertOk()->assertJsonPath('data.is_active', false);
    tenancy()->initialize($this->tenant);
    expect(app(ReceiptPrinterService::class)->getPrinterForWarehouse($this->main)?->id)->toBe($back['id']);
    $this->tenantJson('GET', '/api/admin/receipt-printers', [], $this->staff)->assertOk()->assertJsonCount(2, 'data');
});

it('grants digital downloads on confirmation, enforces limits and ownership, and revokes on cancellation', function (): void {
    $ebook = Product::query()->create(['name' => 'Guide', 'price' => '5', 'product_type' => 'digital', 'is_active' => true]);
    app(ProductService::class)->attachDigitalFile($ebook, UploadedFile::fake()->create('guide.pdf', 20, 'application/pdf'), ['download_limit' => 2, 'expires_after_days' => 30]);

    $unpaid = documentOrder($ebook, '5', false);
    expect(DigitalDownloadGrant::query()->count())->toBe(0);

    $order = documentOrder($ebook, '5');
    $grant = DigitalDownloadGrant::query()->firstOrFail();
    expect($order->status)->toBe(Order::DELIVERED)
        ->and($grant->download_limit)->toBe(2)
        ->and($grant->expires_at?->isSameDay($order->confirmed_at->copy()->addDays(30)))->toBeTrue();

    $this->tenantJson('GET', '/api/account/downloads', [], $this->guest)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.file_name', 'guide.pdf')->assertJsonPath('data.0.status', 'available');
    $this->tenantJson('GET', '/api/account/downloads', [], ['X-Guest-Token' => str_repeat('y', 40)])->assertOk()->assertJsonCount(0, 'data');
    $this->tenantJson('GET', "/api/downloads/{$grant->id}", [], ['X-Guest-Token' => str_repeat('y', 40)])->assertNotFound();

    $file = $this->tenantJson('GET', "/api/downloads/{$grant->id}", [], $this->guest)->assertOk();
    expect($file->headers->get('Content-Disposition'))->toContain('guide.pdf');
    $this->tenantJson('GET', "/api/downloads/{$grant->id}", [], $this->guest)->assertOk();
    $this->tenantJson('GET', "/api/downloads/{$grant->id}", [], $this->guest)->assertStatus(410)->assertJsonPath('meta.error_code', 'download_limit_reached');

    tenancy()->initialize($this->tenant);
    expect($grant->refresh()->download_count)->toBe(2);

    // A digital-only order is delivered at confirmation, so it cannot be
    // cancelled; a full refund revokes its grants.
    app(OrderPaymentService::class)->refundOrder($order->refresh(), null, 'Refund', $this->owner, 'refund-doc-1');
    expect($grant->refresh()->revoked_at)->not->toBeNull()
        ->and(DigitalDownloadGrant::query()->where('order_item_id', $unpaid->items()->value('id'))->exists())->toBeFalse();
    $this->tenantJson('GET', '/api/account/downloads', [], $this->guest)->assertOk()->assertJsonPath('data.0.status', 'download_revoked');
});
