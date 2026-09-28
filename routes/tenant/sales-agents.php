<?php

declare(strict_types=1);

use App\Modules\SalesAgents\Http\Controllers\Tenant\Admin\SalesAgentCommissionController;
use App\Modules\SalesAgents\Http\Controllers\Tenant\Admin\SalesAgentController;
use Illuminate\Support\Facades\Route;

/*
| Sales agents (spec §52.3). Feature `sales_agents`, back office only: the
| agent code is a field on checkout and the POS. Marking earned commissions
| paid stays available while the module winds down (config/modules.php).
| Addition to §52.3: PATCH orders/{order}/sales-agent, staff attribution
| before confirmation (§52.2).
*/

Route::middleware(['tenant.admin', 'feature:sales_agents', 'module.notice:sales_agents'])->prefix('admin')->name('tenant.admin.')->group(function (): void {
    Route::get('sales-agents', [SalesAgentController::class, 'index'])->name('sales-agents.index');
    Route::post('sales-agents', [SalesAgentController::class, 'store'])->name('sales-agents.store');
    Route::patch('sales-agents/{agent}', [SalesAgentController::class, 'update'])->whereNumber('agent')->name('sales-agents.update');
    Route::delete('sales-agents/{agent}', [SalesAgentController::class, 'destroy'])->whereNumber('agent')->name('sales-agents.destroy');
    Route::get('sales-agents/{agent}/commissions', [SalesAgentCommissionController::class, 'index'])->whereNumber('agent')->name('sales-agents.commissions.index');
    Route::get('sales-agents/{agent}/balance', [SalesAgentCommissionController::class, 'balance'])->whereNumber('agent')->name('sales-agents.balance');

    Route::patch('sales-agent-commissions/{commission}/approve', [SalesAgentCommissionController::class, 'approve'])->whereNumber('commission')->name('sales-agent-commissions.approve');
    Route::patch('sales-agent-commissions/{commission}/mark-paid', [SalesAgentCommissionController::class, 'markPaid'])->whereNumber('commission')->name('sales-agent-commissions.mark-paid');

    Route::patch('orders/{order}/sales-agent', [SalesAgentController::class, 'attribute'])->whereNumber('order')->name('orders.sales-agent');
});
