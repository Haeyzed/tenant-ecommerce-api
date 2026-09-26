<?php

declare(strict_types=1);

use App\Modules\PlatformSupport\Http\Controllers\Landlord\Admin\ConversationController;
use Illuminate\Support\Facades\Route;

/*
| The platform helpdesk inbox (spec §21.4).
*/

Route::middleware('landlord.admin')->prefix('admin/platform-support/conversations')->name('landlord.platform-support.')->group(function (): void {
    Route::get('/', [ConversationController::class, 'index'])->name('index');
    Route::get('{conversation}', [ConversationController::class, 'show'])->name('show');
    Route::patch('{conversation}', [ConversationController::class, 'update'])->name('update');
    Route::post('{conversation}/messages', [ConversationController::class, 'sendMessage'])->name('messages.store');
    Route::post('{conversation}/notes', [ConversationController::class, 'addNote'])->name('notes.store');
});
