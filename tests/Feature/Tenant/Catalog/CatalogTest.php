<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Plans\Models\PlanLimit;
use App\Modules\Plans\Services\PlanLimitService;
use App\Modules\Users\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->auth = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];

    // Uploads land on the test tenant's real disk (the tenancy filesystem
    // bootstrapper re-roots disks), next to git-tracked fixtures.
    $this->filesBefore = catalogTenantFiles();
});

afterEach(function (): void {
    foreach (array_diff(catalogTenantFiles(), $this->filesBefore) as $file) {
        File::delete($file);
        @rmdir(dirname($file));
        @rmdir(dirname($file, 2));
    }
});

/**
 * @return list<string>
 */
function catalogTenantFiles(): array
{
    $root = base_path('storage/tenants/test-tenant-a/app');

    return is_dir($root) ? array_map(static fn ($f): string => $f->getPathname(), File::allFiles($root)) : [];
}

function catalogProduct(array $overrides = []): array
{
    return test()->tenantJson('POST', '/api/admin/products', array_merge(['name' => 'Plain Tee', 'price' => '10.00'], $overrides), test()->auth)
        ->assertCreated()->json('data');
}

function catalogCustomerToken(string $email = 'ada@shop.test'): string
{
    return test()->tenantJson('POST', '/api/auth/register', [
        'name' => 'Ada Obi', 'email' => $email, 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
    ])->assertCreated()->json('data.token');
}

it('manages products with codes unique across products and variants, a fixed type and inactive duplicates', function (): void {
    $parent = $this->tenantJson('POST', '/api/admin/categories', ['name' => 'Clothing'], $this->auth)->assertCreated()->json('data.id');
    $child = $this->tenantJson('POST', '/api/admin/categories', ['name' => 'Shirts', 'parent_id' => $parent], $this->auth)->assertCreated()->json('data.id');
    $brand = $this->tenantJson('POST', '/api/admin/brands', ['name' => 'Acme'], $this->auth)->assertCreated()->json('data.id');

    $tee = catalogProduct(['sku' => 'TEE-1', 'cost_price' => '4.00', 'category_ids' => [$parent, $child], 'primary_category_id' => $child, 'brand_id' => $brand]);
    expect($tee['slug'])->toBe('plain-tee')
        ->and(collect($tee['categories'])->firstWhere('is_primary', true)['id'])->toBe($child)
        ->and($tee['brand']['name'])->toBe('Acme');

    $this->tenantJson('PATCH', "/api/admin/products/{$tee['id']}", ['product_type' => 'digital'], $this->auth)->assertStatus(422)->assertJsonValidationErrors('product_type');
    $this->tenantJson('POST', '/api/admin/products', ['name' => 'Other', 'price' => '5', 'sku' => 'TEE-1'], $this->auth)->assertStatus(422)->assertJsonValidationErrors('sku');
    $this->tenantJson('POST', '/api/admin/categories', ['name' => 'Loop', 'parent_id' => 999999], $this->auth)->assertStatus(422);
    $this->tenantJson('PATCH', "/api/admin/categories/{$parent}", ['parent_id' => $child], $this->auth)->assertStatus(422)->assertJsonValidationErrors('parent_id');
    $this->tenantJson('DELETE', "/api/admin/categories/{$parent}", [], $this->auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'category_has_children');

    // A parent category lists its subcategories' products.
    $this->tenantJson('GET', "/api/admin/products?category_id={$parent}", [], $this->auth)->assertOk()->assertJsonPath('data.0.id', $tee['id']);

    $copy = $this->tenantJson('POST', "/api/admin/products/{$tee['id']}/duplicate", [], $this->auth)->assertCreated()->json('data');
    expect($copy['is_active'])->toBeFalse()
        ->and($copy['sku'])->toBe('TEE-1-COPY')
        ->and($copy['name'])->toBe('Plain Tee (copy)')
        ->and($copy['slug'])->not->toBe('plain-tee')
        ->and(count($copy['categories']))->toBe(2);

    $results = $this->tenantJson('POST', '/api/admin/products/bulk', ['action' => 'activate', 'ids' => [$copy['id'], 999999]], $this->auth)->assertOk()->json('data.results');
    expect($results[0]['status'])->toBe('ok')->and($results[1]['error'])->toBe('not_found');

    // A deleted product keeps its SKU reserved.
    $this->tenantJson('DELETE', "/api/admin/products/{$tee['id']}", [], $this->auth)->assertOk();
    $this->tenantJson('POST', '/api/admin/products', ['name' => 'Reuse', 'price' => '5', 'sku' => 'TEE-1'], $this->auth)->assertStatus(422);
});

it('builds variants from option values without duplicate combinations', function (): void {
    $size = $this->tenantJson('POST', '/api/admin/product-options', ['name' => 'Size', 'values' => ['S', 'M']], $this->auth)->assertCreated()->json('data');
    [$small, $medium] = array_column($size['values'], 'id');

    $this->tenantJson('POST', '/api/admin/products', ['name' => 'Hoodie', 'price' => '30', 'product_type' => 'variable', 'sku' => 'HD'], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('sku');
    $hoodie = catalogProduct(['name' => 'Hoodie', 'price' => '30', 'product_type' => 'variable']);
    $simple = catalogProduct(['sku' => 'TEE-1']);

    $variant = $this->tenantJson('POST', "/api/admin/products/{$hoodie['id']}/variants", ['sku' => 'HD-S', 'price' => '32', 'option_value_ids' => [$small]], $this->auth)
        ->assertCreated()->assertJsonPath('data.options.0.value', 'S')->json('data.id');

    $this->tenantJson('POST', "/api/admin/products/{$hoodie['id']}/variants", ['sku' => 'HD-S2', 'option_value_ids' => [$small]], $this->auth)
        ->assertStatus(409)->assertJsonPath('meta.error_code', 'variant_combination_exists');
    $this->tenantJson('POST', "/api/admin/products/{$hoodie['id']}/variants", ['sku' => 'TEE-1', 'option_value_ids' => [$medium]], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('sku');
    $this->tenantJson('POST', "/api/admin/products/{$hoodie['id']}/variants", ['sku' => 'HD-SM', 'option_value_ids' => [$small, $medium]], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('option_value_ids');
    $this->tenantJson('POST', "/api/admin/products/{$simple['id']}/variants", ['sku' => 'X-1', 'option_value_ids' => [$medium]], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'product_not_variable');

    // A product's SKU cannot take a variant's code either.
    $this->tenantJson('PATCH', "/api/admin/products/{$simple['id']}", ['sku' => 'HD-S'], $this->auth)->assertStatus(422)->assertJsonValidationErrors('sku');

    // Variants are found only within their product.
    $this->tenantJson('PATCH', "/api/admin/products/{$simple['id']}/variants/{$variant}", ['price' => '1'], $this->auth)->assertNotFound();
    $this->tenantJson('DELETE', "/api/admin/product-options/{$size['id']}/values/{$small}", [], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'option_value_in_use');

    $this->tenantJson('GET', "/api/admin/products/{$hoodie['id']}/variants", [], $this->auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.price', '32.0000');
});

it('keeps bundles flat and digital files to digital products, and manages the gallery', function (): void {
    $mug = catalogProduct(['name' => 'Mug', 'sku' => 'MUG']);
    $bundle = catalogProduct(['name' => 'Gift set', 'product_type' => 'bundle', 'bundle_items' => [['child_product_id' => $mug['id'], 'quantity' => 2]]]);
    expect($bundle['bundle_items'][0]['quantity'])->toBe('2.000');

    $this->tenantJson('POST', "/api/admin/products/{$bundle['id']}/bundle-items", ['child_product_id' => $mug['id'], 'quantity' => 1], $this->auth)
        ->assertStatus(409)->assertJsonPath('meta.error_code', 'bundle_item_exists');
    $other = catalogProduct(['name' => 'Other set', 'product_type' => 'bundle']);
    $this->tenantJson('POST', "/api/admin/products/{$other['id']}/bundle-items", ['child_product_id' => $bundle['id'], 'quantity' => 1], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'bundle_child_invalid');

    $ebook = catalogProduct(['name' => 'E-book', 'product_type' => 'digital']);
    $this->tenantJson('POST', "/api/admin/products/{$ebook['id']}/digital-files", ['file' => UploadedFile::fake()->create('guide.pdf', 100, 'application/pdf'), 'download_limit' => 3], $this->auth)
        ->assertCreated()->assertJsonPath('data.download_limit', 3);
    $this->tenantJson('POST', "/api/admin/products/{$mug['id']}/digital-files", ['file' => UploadedFile::fake()->create('guide.pdf', 100, 'application/pdf')], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'product_not_digital');
    $this->tenantJson('GET', "/api/admin/products/{$ebook['id']}", [], $this->auth)->assertOk()->assertJsonCount(1, 'data.digital_files');

    $first = $this->tenantJson('POST', "/api/admin/products/{$mug['id']}/media", ['image' => UploadedFile::fake()->image('a.jpg', 400, 400)], $this->auth)->assertCreated()->json('data.id');
    $second = $this->tenantJson('POST', "/api/admin/products/{$mug['id']}/media", ['image' => UploadedFile::fake()->image('b.jpg', 400, 400)], $this->auth)->assertCreated()->json('data.id');
    $foreign = $this->tenantJson('POST', "/api/admin/products/{$ebook['id']}/media", ['image' => UploadedFile::fake()->image('c.jpg', 400, 400)], $this->auth)->assertCreated()->json('data.id');

    $this->tenantJson('POST', "/api/admin/products/{$mug['id']}/media/reorder", ['ordered_ids' => [$second, $foreign]], $this->auth)->assertStatus(422);
    $this->tenantJson('POST', "/api/admin/products/{$mug['id']}/media/reorder", ['ordered_ids' => [$second, $first]], $this->auth)->assertOk();
    $this->tenantJson('POST', "/api/admin/products/{$mug['id']}/media/{$second}/featured", [], $this->auth)->assertOk();

    $media = collect($this->tenantJson('GET', "/api/admin/products/{$mug['id']}", [], $this->auth)->assertOk()->json('data.media'));
    expect($media->where('collection', 'gallery')->sortBy('order')->pluck('id')->values()->all())->toBe([$second, $first])
        ->and($media->where('collection', 'featured'))->toHaveCount(1);

    $this->tenantJson('DELETE', "/api/admin/products/{$mug['id']}/media/{$foreign}", [], $this->auth)->assertNotFound();
});

it('serves only visible products to the storefront with filters, sorts, availability and view tracking', function (): void {
    $gifts = $this->tenantJson('POST', '/api/admin/tags', ['name' => 'Gifts'], $this->auth)->assertCreated()->json('data.id');
    $hiddenCategory = $this->tenantJson('POST', '/api/admin/categories', ['name' => 'Hidden', 'is_active' => false], $this->auth)->assertCreated()->json('data.id');
    $this->tenantJson('POST', '/api/admin/categories', ['name' => 'Home'], $this->auth)->assertCreated();

    $ebook = catalogProduct(['name' => 'E-book', 'product_type' => 'digital', 'price' => '5']);
    $mug = catalogProduct(['name' => 'Mug', 'price' => '20', 'compare_at_price' => '25', 'cost_price' => '8', 'tag_ids' => [$gifts]]);
    catalogProduct(['name' => 'Secret', 'price' => '1', 'is_active' => false]);

    $names = fn (string $query): array => array_column($this->tenantJson('GET', '/api/products'.$query)->assertOk()->json('data'), 'name');

    expect($names(''))->toEqualCanonicalizing(['E-book', 'Mug'])
        ->and($names('?sort=price_asc'))->toBe(['E-book', 'Mug'])
        ->and($names('?sort=price_desc'))->toBe(['Mug', 'E-book'])
        // Physical stock arrives with inventory; digital goods are always in stock.
        ->and($names('?in_stock=1'))->toBe(['E-book'])
        ->and($names('?tag=gifts'))->toBe(['Mug'])
        ->and($names('?badge=on_sale'))->toBe(['Mug'])
        ->and($names('?min_price=10'))->toBe(['Mug'])
        ->and($names('?search=mug'))->toBe(['Mug']);

    $this->tenantJson('GET', '/api/products?sort=cheapest')->assertStatus(422);

    $this->tenantJson('GET', '/api/products/mug')->assertOk()
        ->assertJsonPath('data.id', $mug['id'])
        ->assertJsonPath('data.price', '20.0000')
        ->assertJsonPath('data.in_stock', false)
        ->assertJsonPath('data.unit.short_code', 'pc')
        ->assertJsonPath('data.seo.meta_title', 'Mug')
        ->assertJsonMissingPath('data.cost_price')
        ->assertJsonMissingPath('data.sku');
    expect($this->tenantJson('GET', "/api/products/{$ebook['id']}")->assertOk()->json('data.badges'))->toContain('new');
    $this->tenantJson('GET', '/api/products/secret')->assertNotFound();

    // The view is recorded off the request (the queue runs synchronously here).
    tenancy()->initialize($this->tenant);
    expect(Product::query()->findOrFail($mug['id'])->view_count)->toBe(1)
        ->and(DB::connection('tenant')->table('product_views')->where('product_id', $mug['id'])->count())->toBe(1);

    $categories = array_column($this->tenantJson('GET', '/api/categories')->assertOk()->json('data'), 'name');
    expect($categories)->toBe(['Home']);
    $this->tenantJson('GET', "/api/categories/{$hiddenCategory}")->assertNotFound();
});

it('moderates questions and answers and notifies the asker', function (): void {
    $mug = catalogProduct(['name' => 'Mug']);
    $token = catalogCustomerToken();

    $this->tenantJson('POST', '/api/products/mug/questions', ['question' => 'Is it dishwasher safe?'])->assertUnauthorized();
    $this->tenantJson('POST', '/api/products/mug/questions', ['question' => 'Hi'], ['Authorization' => 'Bearer '.$token])->assertStatus(422);
    $question = $this->tenantJson('POST', '/api/products/mug/questions', ['question' => 'Is it dishwasher safe?'], ['Authorization' => 'Bearer '.$token])
        ->assertCreated()->assertJsonPath('data.is_approved', false)->json('data.id');

    Notification::assertSentTo($this->owner, TemplatedNotification::class, fn ($n): bool => $n->key === 'question.pending_moderation');
    $this->tenantJson('GET', '/api/products/mug/questions')->assertOk()->assertJsonCount(0, 'data');

    $this->tenantJson('GET', '/api/admin/product-questions?status=pending', [], $this->auth)->assertOk()->assertJsonPath('data.0.id', $question);
    $this->tenantJson('POST', "/api/admin/product-questions/{$question}/approve", [], $this->auth)->assertOk()->assertJsonPath('data.is_approved', true);
    $this->tenantJson('POST', "/api/admin/product-questions/{$question}/answers", ['answer' => 'Yes, it is.'], $this->auth)->assertCreated()
        ->assertJsonPath('data.answers.0.is_approved', true);

    tenancy()->initialize($this->tenant);
    Notification::assertSentTo(Customer::query()->where('email', 'ada@shop.test')->firstOrFail(), TemplatedNotification::class, fn ($n): bool => $n->key === 'question.answered');

    $this->tenantJson('GET', "/api/products/{$mug['id']}/questions")->assertOk()
        ->assertJsonPath('data.0.asked_by', 'Ada Obi')
        ->assertJsonPath('data.0.answers.0.answered_by', 'store')
        ->assertJsonMissingPath('data.0.is_approved')
        ->assertJsonMissingPath('data.0.product');
});

it('derives catalogue permissions from routes and enforces the product limit', function (): void {
    $role = $this->tenantJson('POST', '/api/admin/roles', ['name' => 'Catalogue viewer', 'permissions' => ['products.view']], $this->auth)->assertCreated()->json('data.name');
    tenancy()->initialize($this->tenant);
    $viewer = User::query()->create(['name' => 'Viewer', 'email' => 'viewer@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $viewer->assignRole($role);
    $viewerAuth = ['Authorization' => 'Bearer '.$viewer->createToken('t', ['staff'])->plainTextToken];

    $id = catalogProduct()['id'];
    $this->tenantJson('GET', '/api/admin/products', [], $viewerAuth)->assertOk();
    $this->tenantJson('GET', "/api/admin/products/{$id}", [], $viewerAuth)->assertOk();
    $this->tenantJson('POST', '/api/admin/products', ['name' => 'X', 'price' => '1'], $viewerAuth)->assertForbidden();
    $this->tenantJson('POST', "/api/admin/products/{$id}/duplicate", [], $viewerAuth)->assertForbidden();
    $this->tenantJson('GET', '/api/admin/categories', [], $viewerAuth)->assertForbidden();

    tenancy()->initialize($this->tenant);
    PlanLimit::query()->where('limit_key', 'max_products')->update(['limit_value' => 1]);
    app(PlanLimitService::class)->flush($this->tenant);

    $this->tenantJson('POST', '/api/admin/products', ['name' => 'Second', 'price' => '1'], $this->auth)->assertForbidden()->assertJsonPath('meta.error_code', 'limit_reached');
    $this->tenantJson('POST', "/api/admin/products/{$id}/duplicate", [], $this->auth)->assertForbidden()->assertJsonPath('meta.error_code', 'limit_reached');
});

it('stores product custom fields, exposes public ones and searches the searchable ones', function (): void {
    $this->tenantJson('POST', '/api/admin/custom-fields', ['entity_type' => 'product', 'key' => 'material', 'label' => 'Material', 'field_type' => 'text', 'is_admin_only' => false, 'is_searchable' => true], $this->auth)->assertCreated();
    $this->tenantJson('POST', '/api/admin/custom-fields', ['entity_type' => 'product', 'key' => 'supplier_ref', 'label' => 'Supplier ref', 'field_type' => 'text'], $this->auth)->assertCreated();

    $product = catalogProduct(['name' => 'Scarf', 'custom_fields' => ['material' => 'Cashmere', 'supplier_ref' => 'SUP-9']]);
    expect($product['custom_fields'])->toBe(['material' => 'Cashmere', 'supplier_ref' => 'SUP-9']);

    $this->tenantJson('GET', '/api/products/scarf')->assertOk()->assertJsonPath('data.custom_fields', ['material' => 'Cashmere']);
    $this->tenantJson('GET', '/api/products?search=cashmere')->assertOk()->assertJsonPath('data.0.id', $product['id']);
});
