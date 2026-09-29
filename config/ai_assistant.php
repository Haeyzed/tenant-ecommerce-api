<?php

declare(strict_types=1);

use App\Modules\AiAssistant\Support\AssistantHandlers;

/*
|--------------------------------------------------------------------------
| AI business assistant (spec §62.2)
|--------------------------------------------------------------------------
|
| "handlers" maps each handler key to a method of AssistantHandlers and the
| permission the equivalent screen needs: only these entries can ever run.
| "intents" are the seeded questions, inserted when the module is enabled
| and kept current by the defaults sync (an admin's on/off choice is kept).
| Adding an intent is a handler entry plus a seeded row.
|
*/

return [

    'handlers' => [
        'today_sales' => ['method' => 'todaySales', 'permission' => 'orders.view'],
        'daily_snapshot' => ['method' => 'dailySnapshot', 'permission' => 'orders.view'],
        'top_selling_products' => ['method' => 'topSellingProducts', 'permission' => 'orders.view'],
        'pending_orders' => ['method' => 'pendingOrders', 'permission' => 'orders.view'],
        'low_stock' => ['method' => 'lowStock', 'permission' => 'inventory.view'],
        'out_of_stock' => ['method' => 'outOfStock', 'permission' => 'inventory.view'],
        'today_purchases' => ['method' => 'todayPurchases', 'permission' => 'purchase-orders.view'],
        'outstanding_supplier_payments' => ['method' => 'outstandingSupplierPayments', 'permission' => 'supplier-payments.outstanding'],
        'today_expenses' => ['method' => 'todayExpenses', 'permission' => 'expenses.view'],
    ],

    'handler_class' => AssistantHandlers::class,

    'intents' => [
        ['intent_key' => 'today_sales', 'handler' => 'today_sales', 'required_feature' => null,
            'sample_phrases' => ["today's sales", 'sales today', 'how much did we sell today', 'revenue today', "today's revenue", 'what did we make today']],
        ['intent_key' => 'daily_snapshot', 'handler' => 'daily_snapshot', 'required_feature' => null,
            'sample_phrases' => ['daily snapshot', "today's summary", 'summary for today', 'how is business today', 'how are we doing today', 'daily report']],
        ['intent_key' => 'top_selling_products', 'handler' => 'top_selling_products', 'required_feature' => null,
            'sample_phrases' => ['top selling products', 'best sellers', 'best selling products', 'what sells best', 'most popular products', 'top products']],
        ['intent_key' => 'pending_orders', 'handler' => 'pending_orders', 'required_feature' => null,
            'sample_phrases' => ['pending orders', 'orders to ship', 'orders waiting', 'open orders', 'unfulfilled orders', 'orders to process']],
        ['intent_key' => 'low_stock', 'handler' => 'low_stock', 'required_feature' => null,
            'sample_phrases' => ['low stock', 'running low', 'items running out', 'what needs restocking', 'stock is low', 'reorder']],
        ['intent_key' => 'out_of_stock', 'handler' => 'out_of_stock', 'required_feature' => null,
            'sample_phrases' => ['out of stock', 'sold out', 'no stock left', 'items with no stock']],
        ['intent_key' => 'today_purchases', 'handler' => 'today_purchases', 'required_feature' => 'purchasing',
            'sample_phrases' => ["today's purchases", 'purchases today', 'what did we buy today', 'purchase orders today']],
        ['intent_key' => 'outstanding_supplier_payments', 'handler' => 'outstanding_supplier_payments', 'required_feature' => 'purchasing',
            'sample_phrases' => ['outstanding supplier payments', 'what do we owe suppliers', 'supplier balances', 'unpaid suppliers', 'supplier debts']],
        ['intent_key' => 'today_expenses', 'handler' => 'today_expenses', 'required_feature' => 'expenses',
            'sample_phrases' => ["today's expenses", 'expenses today', 'what did we spend today', 'spending today']],
    ],

    'fallback' => 'I can help with sales, purchases, expenses, stock and order questions. Try asking about one of those.',

];
