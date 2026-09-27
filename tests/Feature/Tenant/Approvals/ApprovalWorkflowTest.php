<?php

declare(strict_types=1);

use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductQuestion;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\OrderPaymentLedgerService;
use App\Modules\Returns\Models\ReturnReason;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Shipping\Services\ShipmentService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'premium');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');

    $staff = function (string $name, string $role): array {
        $user = User::query()->create(['name' => $name, 'email' => strtolower($name).'@a.test', 'password' => 'Secret123', 'is_active' => true]);
        $user->assignRole($role);

        return [$user, ['Authorization' => 'Bearer '.$user->createToken('t', ['staff'])->plainTextToken]];
    };

    [$this->owner, $this->ownerAuth] = $staff('Owner', 'owner');
    [$this->manager, $this->managerAuth] = $staff('Manager', 'manager');
    [$this->finance, $this->financeAuth] = $staff('Finance', 'accountant');
    [$this->clerk, $this->clerkAuth] = $staff('Clerk', 'staff');

    app(WarehouseService::class)->ensureDefault();
    $this->main = Warehouse::query()->firstOrFail();
    $this->shoe = Product::query()->create(['name' => 'Runner', 'price' => '40', 'cost_price' => '25', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->main, $this->shoe, null, '20', 'adjustment_in');
    $this->reason = ReturnReason::query()->create(['label' => 'Too small', 'requires_photo' => false]);

    $this->tenantJson('POST', '/api/admin/modules/approval_workflows/enable', [], $this->ownerAuth)->assertOk();
});

/**
 * A delivered guest order of $quantity shoes at 40 each.
 */
function approvalOrder(int $quantity): Order
{
    tenancy()->initialize(test()->tenant);
    $total = (string) (40 * $quantity);
    $order = app(OrderService::class)->createOrder([
        'currency_code' => 'NGN',
        'guest_token' => 'guest-token-approvals-0123456789abcdef0',
        'customer_name' => 'Ada Obi',
        'customer_email' => 'ada@shop.test',
        'lines' => [['product' => test()->shoe, 'variant' => null, 'warehouse' => test()->main, 'quantity' => (string) $quantity, 'unit_price' => '40', 'price_source' => 'base', 'line_total' => $total]],
        'totals' => ['subtotal' => $total, 'total' => $total],
        'shipping_address' => ['name' => 'Ada Obi', 'line1' => '1 Marina', 'country_id' => 1],
    ]);
    app(OrderPaymentLedgerService::class)->recordPayment($order, ['payment_method' => 'cash', 'amount' => $total], test()->owner);
    $shipments = app(ShipmentService::class);
    $shipment = $shipments->createShipmentsForOrder($order)->first();
    $shipment->forceFill(['tracking_number' => 'T-'.$order->id])->save();
    $shipments->markDispatched($shipment);
    $shipments->markDelivered($shipment);

    return $order->refresh();
}

function requestReturnOf(Order $order, int $quantity): array
{
    return test()->tenantJson('POST', "/api/orders/{$order->id}/returns", [
        'items' => [['order_item_id' => $order->items()->value('id'), 'quantity' => $quantity]], 'reason_id' => test()->reason->id, 'resolution_type' => 'refund',
    ], ['X-Guest-Token' => 'guest-token-approvals-0123456789abcdef0'])->assertCreated()->json('data');
}

it('routes high-value returns through a two-step workflow, any_one then all', function (): void {
    $managerRole = Role::query()->where('name', 'manager')->value('id');
    $accountantRole = Role::query()->where('name', 'accountant')->value('id');

    $this->tenantJson('POST', '/api/admin/approval-workflows', ['name' => 'Big returns', 'module_key' => 'review', 'trigger_conditions' => ['min_amount' => 1],
        'steps' => [['name' => 'x', 'approvers' => [['approver_type' => 'role', 'role_id' => $managerRole]]]]], $this->ownerAuth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'unsupported_trigger_condition');

    $workflow = $this->tenantJson('POST', '/api/admin/approval-workflows', [
        'name' => 'High value returns', 'module_key' => 'return', 'trigger_conditions' => ['min_amount' => 100],
        'steps' => [
            ['name' => 'Manager review', 'approval_mode' => 'any_one', 'approvers' => [['approver_type' => 'role', 'role_id' => $managerRole]]],
            ['name' => 'Finance sign-off', 'approval_mode' => 'all', 'approvers' => [['approver_type' => 'user', 'user_id' => $this->clerk->id], ['approver_type' => 'role', 'role_id' => $accountantRole]]],
        ],
    ], $this->ownerAuth)->assertCreated()->assertJsonCount(2, 'data.steps')->json('data');

    // Below the threshold: the module's own flow runs unchanged.
    $small = requestReturnOf(approvalOrder(2), 2);
    $this->tenantJson('PATCH', "/api/admin/returns/{$small['id']}/approve", [], $this->ownerAuth)->assertOk();

    // 3 × 40 = 120 ≥ 100: held for approval.
    $big = requestReturnOf(approvalOrder(3), 3);
    tenancy()->initialize($this->tenant);
    $request = ApprovalRequest::query()->where('approvable_type', 'order_return')->where('approvable_id', $big['id'])->firstOrFail();
    expect($request->status)->toBe('pending')->and($request->requested_by_user_id)->toBeNull();
    Notification::assertSentTo($this->manager, TemplatedNotification::class, static fn ($n): bool => $n->key === 'approval.step_pending');
    Notification::assertNotSentTo($this->finance, TemplatedNotification::class, static fn ($n): bool => $n->key === 'approval.step_pending');

    $this->tenantJson('PATCH', "/api/admin/returns/{$big['id']}/approve", [], $this->ownerAuth)->assertStatus(409)->assertJsonPath('meta.error_code', 'approval_pending');
    $this->tenantJson('POST', "/api/admin/approval-workflows/{$workflow['id']}/steps", ['name' => 'Late', 'approvers' => [['approver_type' => 'user', 'user_id' => $this->owner->id]]], $this->ownerAuth)
        ->assertStatus(409)->assertJsonPath('meta.error_code', 'workflow_has_pending_requests');

    // Step 1 (any_one, manager role).
    $this->tenantJson('GET', '/api/admin/approvals/my-pending', [], $this->managerAuth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'return '.$big['return_number']);
    $this->tenantJson('GET', '/api/admin/approvals/my-pending', [], $this->financeAuth)->assertOk()->assertJsonCount(0, 'data');
    $this->tenantJson('POST', "/api/admin/approvals/{$request->id}/approve", [], $this->financeAuth)->assertForbidden()->assertJsonPath('meta.error_code', 'not_an_approver');
    $this->tenantJson('GET', "/api/admin/approvals/{$request->id}", [], $this->clerkAuth)->assertNotFound();
    $this->tenantJson('POST', "/api/admin/approvals/{$request->id}/approve", ['note' => 'Fine'], $this->managerAuth)->assertOk()
        ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.current_step.name', 'Finance sign-off');

    // Step 2 (all): the named clerk and any accountant.
    $this->tenantJson('POST', "/api/admin/approvals/{$request->id}/approve", [], $this->clerkAuth)->assertOk()->assertJsonPath('data.status', 'pending');
    $this->tenantJson('POST', "/api/admin/approvals/{$request->id}/approve", [], $this->clerkAuth)->assertStatus(409)->assertJsonPath('meta.error_code', 'already_decided');
    $this->tenantJson('GET', '/api/admin/approvals/my-pending', [], $this->clerkAuth)->assertOk()->assertJsonCount(0, 'data');
    $this->tenantJson('POST', "/api/admin/approvals/{$request->id}/approve", [], $this->financeAuth)->assertOk()
        ->assertJsonPath('data.status', 'approved')->assertJsonCount(3, 'data.history');

    $this->tenantJson('GET', "/api/admin/returns/{$big['id']}", [], $this->ownerAuth)->assertOk()->assertJsonPath('data.status', 'awaiting_return_shipment');
    $this->tenantJson('POST', "/api/admin/approvals/{$request->id}/approve", [], $this->financeAuth)->assertStatus(409)->assertJsonPath('meta.error_code', 'approval_resolved');
    $this->tenantJson('DELETE', "/api/admin/approval-workflows/{$workflow['id']}", [], $this->ownerAuth)->assertStatus(409)->assertJsonPath('meta.error_code', 'workflow_in_use');
});

it('holds stock adjustments unapplied, and a rejection leaves stock untouched and tells the requester', function (): void {
    $this->tenantJson('POST', '/api/admin/approval-workflows', ['name' => 'Every adjustment', 'module_key' => 'stock_adjustment',
        'steps' => [['name' => 'Owner check', 'approvers' => [['approver_type' => 'user', 'user_id' => $this->owner->id]]]]], $this->ownerAuth)->assertCreated();

    $adjust = function (int $quantity): int {
        $id = $this->tenantJson('POST', '/api/admin/stock-adjustments', ['warehouse_id' => $this->main->id], $this->managerAuth)->assertCreated()->json('data.id');
        $this->tenantJson('POST', "/api/admin/stock-adjustments/{$id}/items", ['product_id' => $this->shoe->id, 'action' => 'subtraction', 'quantity' => $quantity], $this->managerAuth)->assertCreated();
        $this->tenantJson('PATCH', "/api/admin/stock-adjustments/{$id}/submit", [], $this->managerAuth)->assertOk()->assertJsonPath('data.status', 'pending_approval');

        return $id;
    };
    $onHand = static fn (): string => (string) Inventory::query()->where('product_id', test()->shoe->id)->value('quantity');

    $rejected = $adjust(5);
    tenancy()->initialize($this->tenant);
    expect($onHand())->toBe('20.000');
    $request = ApprovalRequest::query()->where('approvable_id', $rejected)->where('approvable_type', 'stock_adjustment')->firstOrFail();
    expect($request->requested_by_user_id)->toBe($this->manager->id);

    $this->tenantJson('POST', "/api/admin/approvals/{$request->id}/reject", [], $this->ownerAuth)->assertStatus(422);
    $this->tenantJson('POST', "/api/admin/approvals/{$request->id}/reject", ['note' => 'Recount first'], $this->ownerAuth)->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->tenantJson('GET', "/api/admin/stock-adjustments/{$rejected}", [], $this->managerAuth)->assertOk()->assertJsonPath('data.status', 'rejected');
    Notification::assertSentTo($this->manager, TemplatedNotification::class, static fn ($n): bool => $n->key === 'approval.request_rejected');

    // The requester may follow their own request.
    $this->tenantJson('GET', "/api/admin/approvals/{$request->id}", [], $this->managerAuth)->assertOk()->assertJsonPath('data.history.0.note', 'Recount first');

    $approved = $adjust(4);
    tenancy()->initialize($this->tenant);
    $second = ApprovalRequest::query()->where('approvable_id', $approved)->where('approvable_type', 'stock_adjustment')->firstOrFail();
    $this->tenantJson('POST', "/api/admin/approvals/{$second->id}/approve", [], $this->ownerAuth)->assertOk()->assertJsonPath('data.status', 'approved');

    tenancy()->initialize($this->tenant);
    expect($onHand())->toBe('16.000');
    Notification::assertSentTo($this->manager, TemplatedNotification::class, static fn ($n): bool => $n->key === 'approval.request_approved');
});

it('moderates reviews and questions through a workflow, and runs nothing while the engine is off', function (): void {
    $customer = Customer::query()->create(['name' => 'Ada', 'email' => 'ada@shop.test', 'password' => 'Secret123']);
    $customerAuth = ['Authorization' => 'Bearer '.$customer->createToken('t', ['customer'])->plainTextToken];
    $managerRole = Role::query()->where('name', 'manager')->value('id');
    $step = ['name' => 'Moderator', 'approvers' => [['approver_type' => 'role', 'role_id' => $managerRole]]];

    $this->tenantJson('POST', '/api/admin/approval-workflows', ['name' => 'Reviews', 'module_key' => 'review', 'steps' => [$step]], $this->ownerAuth)->assertCreated();
    $questions = $this->tenantJson('POST', '/api/admin/approval-workflows', ['name' => 'Questions', 'module_key' => 'product_question', 'steps' => [$step, $step]], $this->ownerAuth)
        ->assertCreated()->json('data');

    // Reorder and remove steps (resequenced, never empty).
    [$first, $second] = array_column($questions['steps'], 'id');
    $this->tenantJson('PATCH', "/api/admin/approval-workflows/{$questions['id']}/steps/reorder", ['step_ids' => [$second, $first]], $this->ownerAuth)
        ->assertOk()->assertJsonPath('data.steps.0.id', $second);
    $this->tenantJson('DELETE', "/api/admin/approval-workflows/{$questions['id']}/steps/{$second}", [], $this->ownerAuth)
        ->assertOk()->assertJsonPath('data.steps.0.id', $first)->assertJsonPath('data.steps.0.step_order', 1);
    $this->tenantJson('DELETE', "/api/admin/approval-workflows/{$questions['id']}/steps/{$first}", [], $this->ownerAuth)->assertStatus(422);

    $review = $this->tenantJson('POST', "/api/products/{$this->shoe->id}/reviews", ['rating' => 5, 'body' => 'Great shoe'], $customerAuth)->assertCreated()->json('data.id');
    $this->tenantJson('POST', "/api/admin/reviews/{$review}/approve", [], $this->ownerAuth)->assertStatus(409);
    tenancy()->initialize($this->tenant);
    $reviewRequest = ApprovalRequest::query()->where('approvable_type', 'product_review')->where('approvable_id', $review)->firstOrFail();
    $this->tenantJson('POST', "/api/admin/approvals/{$reviewRequest->id}/approve", [], $this->managerAuth)->assertOk();
    $this->tenantJson('GET', "/api/products/{$this->shoe->id}/reviews")->assertOk()->assertJsonCount(1, 'data');

    $question = $this->tenantJson('POST', "/api/products/{$this->shoe->id}/questions", ['question' => 'Does it run large?'], $customerAuth)->assertCreated()->json('data.id');
    tenancy()->initialize($this->tenant);
    $questionRequest = ApprovalRequest::query()->where('approvable_type', 'product_question')->where('approvable_id', $question)->firstOrFail();
    $this->tenantJson('POST', "/api/admin/approvals/{$questionRequest->id}/reject", ['note' => 'Spam'], $this->managerAuth)->assertOk();
    tenancy()->initialize($this->tenant);
    expect(ProductQuestion::query()->find($question))->toBeNull();

    // Engine disabled: records follow their module's own flow again.
    $this->tenantJson('POST', '/api/admin/modules/approval_workflows/disable', [], $this->ownerAuth)->assertOk();
    $this->tenantJson('POST', "/api/products/{$this->shoe->id}/questions", ['question' => 'Is it waterproof?'], $customerAuth)->assertCreated();
    tenancy()->initialize($this->tenant);
    expect(ApprovalRequest::query()->where('approvable_type', 'product_question')->count())->toBe(1);
    $this->tenantJson('GET', '/api/admin/approval-workflows', [], $this->ownerAuth)->assertOk();
});
