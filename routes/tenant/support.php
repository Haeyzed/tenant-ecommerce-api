<?php

declare(strict_types=1);

use App\Modules\Dashboard\Http\Controllers\Tenant\Admin\ResourceMetricsController;
use App\Modules\Support\Http\Controllers\Tenant\Admin\ConversationController as AdminConversationController;
use App\Modules\Support\Http\Controllers\Tenant\Admin\MessageController as AdminMessageController;
use App\Modules\Support\Http\Controllers\Tenant\ConversationController;
use App\Modules\Support\Http\Controllers\Tenant\MessageController;
use App\Modules\Support\Http\Controllers\Tenant\SupportBroadcastAuthController;
use Illuminate\Support\Facades\Route;

/*
| Customer support (spec §59.4). Feature `support`. Storefront routes serve
| a signed-in customer or a guest (X-Guest-Token). Typing indicators are
| Reverb client whispers and have no route. Additions: the attachment
| downloads and the storefront broadcasting auth endpoint.
*/

Route::middleware(['tenant.storefront', 'feature:support'])->prefix('support')->name('tenant.storefront.support.')->group(function (): void {
    Route::post('broadcasting/auth', SupportBroadcastAuthController::class)->name('broadcasting.auth');

    Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::post('conversations', [ConversationController::class, 'store'])->middleware('throttle:auth-sensitive')->name('conversations.store');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->whereNumber('conversation')->name('conversations.show');
    Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])->whereNumber('conversation')->name('conversations.messages.store');
    Route::post('conversations/{conversation}/read', [MessageController::class, 'markRead'])->whereNumber('conversation')->name('conversations.read');
    Route::get('conversations/{conversation}/attachments/{attachment}', [MessageController::class, 'attachment'])->whereNumber(['conversation', 'attachment'])->name('conversations.attachments.show');
});

Route::middleware(['tenant.admin', 'feature:support', 'module.notice:support'])->prefix('admin/support')->name('tenant.admin.support.')->group(function (): void {
    Route::get('conversations', [AdminConversationController::class, 'index'])->name('conversations.index');
    Route::get('conversations/metrics', [ResourceMetricsController::class, 'metrics'])->defaults('metrics_resource', 'support-conversations')->name('conversations.metrics');
    Route::get('conversations/{conversation}', [AdminConversationController::class, 'show'])->whereNumber('conversation')->name('conversations.show');
    Route::patch('conversations/{conversation}', [AdminConversationController::class, 'update'])->whereNumber('conversation')->name('conversations.update');
    Route::post('conversations/{conversation}/messages', [AdminMessageController::class, 'store'])->whereNumber('conversation')->name('conversations.messages.store');
    Route::post('conversations/{conversation}/notes', [AdminMessageController::class, 'addNote'])->whereNumber('conversation')->name('conversations.notes');
    Route::post('conversations/{conversation}/read', [AdminMessageController::class, 'markRead'])->whereNumber('conversation')->name('conversations.read');
    Route::get('conversations/{conversation}/attachments/{attachment}', [AdminMessageController::class, 'attachment'])->whereNumber(['conversation', 'attachment'])->name('conversations.attachments.show');
});
