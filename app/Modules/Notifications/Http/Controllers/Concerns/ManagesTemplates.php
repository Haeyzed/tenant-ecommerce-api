<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Concerns;

use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Template routes of spec §17.7, for the scope of the current context.
 */
trait ManagesTemplates
{
    public function index(Request $request, NotificationTemplateService $templates): JsonResponse
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
            ->map(fn (NotificationTemplate $t): array => $this->presentTemplate($t, $templates, $scope))
            ->values());
    }

    public function update(Request $request, string $key, NotificationTemplateService $templates): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'string', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $scope = NotificationScope::current();
        $template = $templates->updateTemplate($key, $validated, $scope);

        return APIResponse::success($this->presentTemplate($template, $templates, $scope), 'Template updated');
    }

    public function reset(string $key, NotificationTemplateService $templates): JsonResponse
    {
        $scope = NotificationScope::current();

        return APIResponse::success($this->presentTemplate($templates->resetToDefault($key, $scope), $templates, $scope), 'Template reset');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTemplate(NotificationTemplate $template, NotificationTemplateService $templates, NotificationScope $scope): array
    {
        $variables = $templates->placeholders($template->key, $scope);
        $samples = array_combine($variables, array_map(static fn (string $v): string => '['.$v.']', $variables)) ?: [];

        return [
            'key' => $template->key,
            'subject' => $template->subject,
            'body' => $template->body,
            'target_audience' => $template->target_audience,
            'is_active' => $template->is_active,
            'is_mandatory' => $template->is_mandatory,
            'is_customized' => $template->is_customized,
            /** @var array<string, bool> Channel to on/off */
            'channels' => $template->channelMatrix(),
            /** @var list<string> Placeholders the subject and body may use */
            'variables' => $variables,
            'preview' => $templates->renderContent($template->key, $template->subject, $template->body, $samples),
        ];
    }
}
