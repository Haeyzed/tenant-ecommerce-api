<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Limit registry (spec §11.8)
|--------------------------------------------------------------------------
|
| kind: count (live row count), storage (sum), rate (per-minute throttle)
| unlimited_allowed: whether a plan or override may store null
| default: value given to a new plan's row by PlanService::createPlan()
|
*/

return [
    'max_users' => ['kind' => 'count', 'unlimited_allowed' => true, 'default' => 2, 'label' => 'Staff accounts'],
    'max_products' => ['kind' => 'count', 'unlimited_allowed' => true, 'default' => 250, 'label' => 'Products'],
    'max_warehouses' => ['kind' => 'count', 'unlimited_allowed' => true, 'default' => 1, 'label' => 'Locations'],
    'max_orders_per_month' => ['kind' => 'count', 'unlimited_allowed' => true, 'default' => 500, 'label' => 'Orders per month'],
    'max_storage_mb' => ['kind' => 'storage', 'unlimited_allowed' => false, 'default' => 2048, 'label' => 'Storage (MB)'],
    'max_pos_registers' => ['kind' => 'count', 'unlimited_allowed' => true, 'default' => 0, 'label' => 'POS registers'],
    'max_custom_domains' => ['kind' => 'count', 'unlimited_allowed' => false, 'default' => 0, 'label' => 'Custom domains'],
    'max_custom_fields' => ['kind' => 'count', 'unlimited_allowed' => false, 'default' => 10, 'label' => 'Custom fields'],
    'max_employees' => ['kind' => 'count', 'unlimited_allowed' => true, 'default' => 0, 'label' => 'Employees'],
    'max_sellers' => ['kind' => 'count', 'unlimited_allowed' => true, 'default' => 0, 'label' => 'Sellers'],
    'max_api_requests_per_minute' => ['kind' => 'rate', 'unlimited_allowed' => false, 'default' => 600, 'label' => 'API requests per minute'],
];
