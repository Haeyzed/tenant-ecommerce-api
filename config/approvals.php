<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\ProductQuestion;
use App\Modules\Catalog\Services\ProductQuestionService;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Returns\Models\OrderReturn;
use App\Modules\Returns\Services\ReturnService;
use App\Modules\Reviews\Models\ProductReview;
use App\Modules\Reviews\Services\ReviewService;

/*
|--------------------------------------------------------------------------
| Approval module key registry (spec §60.1)
|--------------------------------------------------------------------------
|
| module_key => label, the record model, the owning service (implements
| App\Contracts\Approvable), the feature the module needs ('core' = always)
| and the trigger condition keys it supports (A-51: others accept only
| null, "every record"). Modules add their key when they are built:
| purchase_order (§49), seller_application (§50), leave_request (§58.4).
|
*/

return [

    'return' => [
        'label' => 'Returns',
        'model' => OrderReturn::class,
        'service' => ReturnService::class,
        'feature' => 'core',
        'conditions' => ['min_amount'],
    ],

    'stock_adjustment' => [
        'label' => 'Stock adjustments',
        'model' => StockAdjustment::class,
        'service' => StockAdjustmentService::class,
        'feature' => 'core',
        'conditions' => [],
    ],

    'review' => [
        'label' => 'Product reviews',
        'model' => ProductReview::class,
        'service' => ReviewService::class,
        'feature' => 'core',
        'conditions' => [],
    ],

    'product_question' => [
        'label' => 'Product questions',
        'model' => ProductQuestion::class,
        'service' => ProductQuestionService::class,
        'feature' => 'core',
        'conditions' => [],
    ],

];
