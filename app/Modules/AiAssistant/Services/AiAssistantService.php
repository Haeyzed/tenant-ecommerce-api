<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Services;

use App\Modules\AiAssistant\Models\AiAssistantIntent;
use App\Modules\AiAssistant\Models\AiAssistantQueryLog;
use App\Modules\AiAssistant\Support\IntentMatcher;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * The AI business assistant (spec §62.3): a question is matched against the
 * active intents whose feature is enabled, and answered by the registered
 * handler with the asker's permissions. No external AI is called.
 */
final readonly class AiAssistantService
{
    public function __construct(
        private IntentMatcher $matcher,
        private FeatureAccessService $features,
    ) {}

    /**
     * @return array{matched: bool, intent_key: string|null, answer: string, data: array<string, mixed>|null}
     */
    public function askQuestion(string $rawQuery, User $user): array
    {
        Validator::make(['question' => $rawQuery], ['question' => ['required', 'string', 'max:500']])->validate();

        $intent = $this->matcher->match($rawQuery, $this->candidates());
        $result = match (true) {
            $intent === null => ['matched' => false, 'intent_key' => null, 'answer' => (string) config('ai_assistant.fallback'), 'data' => null],
            default => ['matched' => true, 'intent_key' => $intent->intent_key, ...$this->answer($intent, $user)],
        };

        $log = new AiAssistantQueryLog;
        $log->forceFill([
            'user_id' => $user->id,
            'raw_query' => $rawQuery,
            'matched_intent_key' => $result['intent_key'],
            'response_summary' => $result['matched'] ? mb_substr($result['answer'], 0, 255) : null,
        ])->save();

        return $result;
    }

    /**
     * @return Collection<int, AiAssistantIntent>
     */
    public function listIntents(): Collection
    {
        return AiAssistantIntent::query()->orderBy('id')->get();
    }

    public function toggleIntent(AiAssistantIntent $intent, bool $active): AiAssistantIntent
    {
        $intent->forceFill(['is_active' => $active])->save();

        return $intent;
    }

    /**
     * @param  array{matched?: bool, intent_key?: string, user_id?: int, from?: string, to?: string, per_page?: int}  $filters  matched=false lists unmatched questions
     * @return LengthAwarePaginator<int, AiAssistantQueryLog>
     */
    public function getQueryLogs(array $filters = []): LengthAwarePaginator
    {
        return AiAssistantQueryLog::query()->with('user:id,name')
            ->when(array_key_exists('matched', $filters), static fn ($q) => $filters['matched'] ? $q->whereNotNull('matched_intent_key') : $q->whereNull('matched_intent_key'))
            ->when(isset($filters['intent_key']), static fn ($q) => $q->where('matched_intent_key', $filters['intent_key']))
            ->when(isset($filters['user_id']), static fn ($q) => $q->where('user_id', $filters['user_id']))
            ->when(isset($filters['from']), static fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), static fn ($q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * Seeds and refreshes the catalogue intents (§62.2): missing intents are
     * inserted; existing ones get the current phrases, handler and feature;
     * an admin's on/off choice is kept.
     */
    public function seedIntents(): void
    {
        foreach ((array) config('ai_assistant.intents') as $seed) {
            $intent = AiAssistantIntent::query()->where('intent_key', $seed['intent_key'])->first() ?? (new AiAssistantIntent)->forceFill(['is_active' => true]);
            $intent->forceFill([
                'intent_key' => $seed['intent_key'],
                'sample_phrases' => $seed['sample_phrases'],
                'handler' => $seed['handler'],
                'required_feature' => $seed['required_feature'],
            ])->save();
        }
    }

    /**
     * Active intents whose feature (if any) is enabled.
     *
     * @return Collection<int, AiAssistantIntent>
     */
    private function candidates(): Collection
    {
        $tenant = tenant();

        return AiAssistantIntent::query()->where('is_active', true)->orderBy('id')->get()
            ->filter(fn (AiAssistantIntent $i): bool => $i->required_feature === null
                || ($tenant instanceof Tenant && $this->features->state($tenant, $i->required_feature) === ModuleState::Enabled))
            ->values();
    }

    /**
     * Runs the registered handler, or explains the missing permission.
     *
     * @return array{answer: string, data: array<string, mixed>|null}
     */
    private function answer(AiAssistantIntent $intent, User $user): array
    {
        $handler = config('ai_assistant.handlers.'.$intent->handler)
            ?? throw new RuntimeException("The assistant handler [{$intent->handler}] is not registered.");

        if (! $user->hasPermissionTo($handler['permission'], 'staff')) {
            return ['answer' => 'You don\'t have access to that information. Ask an admin for the '.$handler['permission'].' permission.', 'data' => null];
        }

        $class = (string) config('ai_assistant.handler_class');

        return app($class)->{$handler['method']}($user);
    }
}
