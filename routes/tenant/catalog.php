<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\Tenant\Admin\BrandController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\CategoryController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\DigitalFileController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\ProductBadgeController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\ProductBundleItemController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\ProductController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\ProductDetailsController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\ProductMediaController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\ProductOptionController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\ProductOptionValueController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\ProductQuestionController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\ProductRelationController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\ProductVariantController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\TagController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\UnitController;
use App\Modules\Catalog\Http\Controllers\Tenant\StorefrontCatalogController;
use Illuminate\Support\Facades\Route;

/*
| The catalogue (spec §27.5, §28.6, §29.8, §30.1, §31.1). Core commerce:
| no feature key, module.notice:core. Digital download grants and their
| storefront routes are added with orders (§28.4).
*/

Route::middleware(['tenant.admin', 'module.notice:core'])->prefix('admin')->name('tenant.catalog.admin.')->group(function (): void {
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::post('products', [ProductController::class, 'store'])->middleware('usage.limit:max_products')->name('products.store');
    Route::post('products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
    Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product')->name('products.show');
    Route::patch('products/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('products.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->whereNumber('product')->name('products.destroy');
    Route::post('products/{product}/duplicate', [ProductController::class, 'duplicate'])->whereNumber('product')->middleware('usage.limit:max_products')->name('products.duplicate');

    Route::prefix('products/{product}')->whereNumber('product')->name('products.')->group(function (): void {
        Route::put('categories', [ProductDetailsController::class, 'categories'])->name('categories');
        Route::put('tags', [ProductDetailsController::class, 'tags'])->name('tags');
        Route::put('specifications', [ProductDetailsController::class, 'update'])->name('specifications');

        Route::get('variants', [ProductVariantController::class, 'index'])->name('variants.index');
        Route::post('variants', [ProductVariantController::class, 'store'])->name('variants.store');
        Route::patch('variants/{variant}', [ProductVariantController::class, 'update'])->whereNumber('variant')->name('variants.update');
        Route::delete('variants/{variant}', [ProductVariantController::class, 'destroy'])->whereNumber('variant')->name('variants.destroy');

        Route::post('bundle-items', [ProductBundleItemController::class, 'store'])->name('bundle-items.store');
        Route::delete('bundle-items/{item}', [ProductBundleItemController::class, 'destroy'])->whereNumber('item')->name('bundle-items.destroy');

        Route::post('digital-files', [DigitalFileController::class, 'store'])->middleware('usage.limit:max_storage_mb')->name('digital-files.store');
        Route::delete('digital-files/{file}', [DigitalFileController::class, 'destroy'])->whereNumber('file')->name('digital-files.destroy');

        Route::post('media', [ProductMediaController::class, 'store'])->middleware('usage.limit:max_storage_mb')->name('media.store');
        Route::post('media/reorder', [ProductMediaController::class, 'reorder'])->name('media.reorder');
        Route::delete('media/{media}', [ProductMediaController::class, 'destroy'])->whereNumber('media')->name('media.destroy');
        Route::post('media/{media}/featured', [ProductMediaController::class, 'setFeatured'])->whereNumber('media')->name('media.featured');

        Route::post('relations', [ProductRelationController::class, 'store'])->name('relations.store');
        Route::delete('relations/{relation}', [ProductRelationController::class, 'destroy'])->whereNumber('relation')->name('relations.destroy');

        Route::post('badges', [ProductBadgeController::class, 'store'])->name('badges.store');
        Route::delete('badges/{badge}', [ProductBadgeController::class, 'destroy'])->whereNumber('badge')->name('badges.destroy');
    });

    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::post('categories/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
    Route::patch('categories/{category}', [CategoryController::class, 'update'])->whereNumber('category')->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->whereNumber('category')->name('categories.destroy');
    Route::post('categories/{category}/image', [CategoryController::class, 'image'])->whereNumber('category')->middleware('usage.limit:max_storage_mb')->name('categories.image');

    Route::get('brands', [BrandController::class, 'index'])->name('brands.index');
    Route::post('brands', [BrandController::class, 'store'])->name('brands.store');
    Route::patch('brands/{brand}', [BrandController::class, 'update'])->whereNumber('brand')->name('brands.update');
    Route::delete('brands/{brand}', [BrandController::class, 'destroy'])->whereNumber('brand')->name('brands.destroy');
    Route::post('brands/{brand}/image', [BrandController::class, 'image'])->whereNumber('brand')->middleware('usage.limit:max_storage_mb')->name('brands.image');

    Route::get('product-options', [ProductOptionController::class, 'index'])->name('options.index');
    Route::post('product-options', [ProductOptionController::class, 'store'])->name('options.store');
    Route::patch('product-options/{option}', [ProductOptionController::class, 'update'])->whereNumber('option')->name('options.update');
    Route::delete('product-options/{option}', [ProductOptionController::class, 'destroy'])->whereNumber('option')->name('options.destroy');
    Route::post('product-options/{option}/values', [ProductOptionValueController::class, 'store'])->whereNumber('option')->name('options.values.store');
    Route::delete('product-options/{option}/values/{value}', [ProductOptionValueController::class, 'destroy'])->whereNumber(['option', 'value'])->name('options.values.destroy');

    Route::get('units', [UnitController::class, 'index'])->name('units.index');
    Route::post('units', [UnitController::class, 'store'])->name('units.store');
    Route::patch('units/{unit}', [UnitController::class, 'update'])->whereNumber('unit')->name('units.update');
    Route::delete('units/{unit}', [UnitController::class, 'destroy'])->whereNumber('unit')->name('units.destroy');

    Route::get('tags', [TagController::class, 'index'])->name('tags.index');
    Route::post('tags', [TagController::class, 'store'])->name('tags.store');
    Route::delete('tags/{tag}', [TagController::class, 'destroy'])->whereNumber('tag')->name('tags.destroy');

    Route::get('product-questions', [ProductQuestionController::class, 'index'])->name('questions.index');
    Route::post('product-questions/{question}/approve', [ProductQuestionController::class, 'approve'])->whereNumber('question')->name('questions.approve');
    Route::post('product-questions/{question}/answers', [ProductQuestionController::class, 'answer'])->whereNumber('question')->name('questions.answers');
    Route::post('product-answers/{answer}/approve', [ProductQuestionController::class, 'approveAnswer'])->whereNumber('answer')->name('answers.approve');
    Route::delete('product-questions/{question}', [ProductQuestionController::class, 'destroy'])->whereNumber('question')->name('questions.destroy');
});

Route::middleware(['tenant.public', 'module.notice:core'])->name('tenant.catalog.')->group(function (): void {
    $slug = '[A-Za-z0-9-]+';

    Route::get('products', [StorefrontCatalogController::class, 'products'])->name('products.index');
    Route::get('products/{product}', [StorefrontCatalogController::class, 'show'])->where('product', $slug)->middleware('auth.as.optional:customer')->name('products.show');
    Route::get('products/{product}/related', [StorefrontCatalogController::class, 'related'])->where('product', $slug)->name('products.related');
    Route::get('products/{product}/questions', [StorefrontCatalogController::class, 'questions'])->where('product', $slug)->name('products.questions.index');
    Route::post('products/{product}/questions', [StorefrontCatalogController::class, 'askQuestion'])->where('product', $slug)->middleware('auth.as:customer')->name('products.questions.store');

    Route::get('categories', [StorefrontCatalogController::class, 'categories'])->name('categories.index');
    Route::get('categories/{category}', [StorefrontCatalogController::class, 'category'])->where('category', $slug)->name('categories.show');
    Route::get('categories/{category}/products', [StorefrontCatalogController::class, 'categoryProducts'])->where('category', $slug)->name('categories.products');

    Route::get('brands', [StorefrontCatalogController::class, 'brands'])->name('brands.index');
    Route::get('brands/{brand}', [StorefrontCatalogController::class, 'brand'])->where('brand', $slug)->name('brands.show');
    Route::get('brands/{brand}/products', [StorefrontCatalogController::class, 'brandProducts'])->where('brand', $slug)->name('brands.products');

    Route::get('tags', [StorefrontCatalogController::class, 'tags'])->name('tags.index');
});
