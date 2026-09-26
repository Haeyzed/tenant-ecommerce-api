<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\PushDeviceToken;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * FCM tokens per tenant actor (spec §16.5). A token registered again moves
 * to the current actor; an actor keeps at most ten tokens.
 */
final class PushDeviceTokenService
{
    public function register(Model $actor, string $token, string $platform): PushDeviceToken
    {
        validator(compact('token', 'platform'), [
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', Rule::in(PushDeviceToken::PLATFORMS)],
        ])->validate();

        return DB::connection('tenant')->transaction(function () use ($actor, $token, $platform): PushDeviceToken {
            /** @var PushDeviceToken $row */
            $row = PushDeviceToken::query()->updateOrCreate(['token' => $token], [
                'tokenable_type' => $actor->getMorphClass(),
                'tokenable_id' => $actor->getKey(),
                'platform' => $platform,
                'last_used_at' => now(),
            ]);

            $surplus = PushDeviceToken::query()
                ->where('tokenable_type', $actor->getMorphClass())
                ->where('tokenable_id', $actor->getKey())
                ->orderByDesc('last_used_at')
                ->orderByDesc('id')
                ->skip(PushDeviceToken::MAX_PER_ACTOR)
                ->take(PHP_INT_MAX)
                ->pluck('id');

            if ($surplus->isNotEmpty()) {
                PushDeviceToken::query()->whereIn('id', $surplus)->delete();
            }

            return $row;
        });
    }

    public function unregister(Model $actor, string $token): void
    {
        PushDeviceToken::query()
            ->where('tokenable_type', $actor->getMorphClass())
            ->where('tokenable_id', $actor->getKey())
            ->where('token', $token)
            ->delete();
    }

    /**
     * @return Collection<int, PushDeviceToken>
     */
    public function tokensFor(Model $actor): Collection
    {
        return PushDeviceToken::query()
            ->where('tokenable_type', $actor->getMorphClass())
            ->where('tokenable_id', $actor->getKey())
            ->get();
    }

    public function hasTokens(Model $actor): bool
    {
        return PushDeviceToken::query()
            ->where('tokenable_type', $actor->getMorphClass())
            ->where('tokenable_id', $actor->getKey())
            ->exists();
    }
}
