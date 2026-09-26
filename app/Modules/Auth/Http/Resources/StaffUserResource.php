<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class StaffUserResource extends JsonResource
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
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'preferences' => [
                'date_format' => $this->preferences['date_format'] ?? null,
                'time_format' => $this->preferences['time_format'] ?? null,
            ],
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
