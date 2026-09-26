<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Access\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformUser
 */
final class PlatformUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'is_active' => $this->is_active,
            'preferences' => [
                'date_format' => $this->preferences['date_format'] ?? null,
                'time_format' => $this->preferences['time_format'] ?? null,
            ],
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
