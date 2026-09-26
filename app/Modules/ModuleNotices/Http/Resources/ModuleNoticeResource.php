<?php

declare(strict_types=1);

namespace App\Modules\ModuleNotices\Http\Resources;

use App\Modules\ModuleNotices\Models\ModuleNotice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ModuleNotice
 */
final class ModuleNoticeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'module_key' => $this->module_key,
            'tenant_id' => $this->when(! tenancy()->initialized, $this->tenant_id),
            'type' => $this->type,
            'behavior' => $this->behavior,
            'title' => $this->title,
            'message' => $this->message,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_active' => $this->is_active,
        ];
    }
}
