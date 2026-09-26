<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Concerns;

use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Notifications\Support\NotificationCatalog;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Template routes of spec §17.7, for the scope of the current context.
 */
trait ManagesTemplates
{
    public function index(Request $request, NotificationTemplateService $templates, NotificationCatalog $catalog): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'audience' => ['sometimes', 'nullable', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_active', $filters)) {
            $filters['is_active'] = $request->boolean('is_active');
        }

        $scope = NotificationScope::current();

        return APIResponse::success($templates->listTemplates($filters, $scope)
            ->map(fn (NotificationTemplate $t): array => $this->presentTemplate($t, $templates, $catalog, $scope))
            ->values());
    }

    public function update(Request $request, string $key, NotificationTemplateService $templates, NotificationCatalog $catalog): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'string', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $scope = NotificationScope::current();
        $template = $templates->updateTemplate($key, $validated, $scope);

        return APIResponse::success($this->presentTemplate($template, $templates, $catalog, $scope), 'Template updated');
    }

    public function reset(string $key, NotificationTemplateService $templates, NotificationCatalog $catalog): JsonResponse
    {
        $scope = NotificationScope::current();

        return APIResponse::success($this->presentTemplate($templates->resetToDefault($key, $scope), $templates, $catalog, $scope), 'Template reset');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTemplate(NotificationTemplate $template, NotificationTemplateService $templates, NotificationCatalog $catalog, NotificationScope $scope): array
    {
        $variables = $catalog->has($template->key, $scope) ? $catalog->variables($template->key, $scope) : [];
        $samples = array_combine($variables, array_map(static fn (string $v): string => '['.$v.']', $variables)) ?: [];

        return [
            'key' => $template->key,
            'subject' => $template->subject,
            'body' => $template->body,
            'target_audience' => $template->target_audience,
            'is_active' => $template->is_active,
            'is_mandatory' => $template->is_mandatory,
            'is_customized' => $template->is_customized,
            'channels' => $template->channelMatrix(),
            'variables' => $variables,
            'preview' => $templates->renderContent($template->key, $template->subject, $template->body, $samples),
        ];
    }
}
