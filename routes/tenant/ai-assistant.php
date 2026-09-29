<?php

declare(strict_types=1);

use App\Modules\AiAssistant\Http\Controllers\Tenant\Admin\AssistantController;
use App\Modules\AiAssistant\Http\Controllers\Tenant\Admin\IntentController;
use App\Modules\AiAssistant\Http\Controllers\Tenant\Admin\QueryLogController;
use Illuminate\Support\Facades\Route;

/*
| AI business assistant (spec §62.4). Feature `ai_assistant`. Asking derives
| ai-assistant.ask; each answer also needs the permission of the equivalent
| screen (config/ai_assistant.php).
*/

Route::middleware(['tenant.admin', 'feature:ai_assistant', 'module.notice:ai_assistant'])->prefix('admin/ai-assistant')->name('tenant.admin.ai-assistant.')->group(function (): void {
    Route::post('ask', [AssistantController::class, 'ask'])->name('ask');
    Route::get('intents', [IntentController::class, 'index'])->name('intents.index');
    Route::patch('intents/{intent}/toggle', [IntentController::class, 'toggle'])->whereNumber('intent')->name('intents.toggle');
    Route::get('query-logs', [QueryLogController::class, 'index'])->name('query-logs.index');
});
