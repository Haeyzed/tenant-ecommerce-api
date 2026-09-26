<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Services\NotificationTemplateService;
use Illuminate\Database\Seeder;

/**
 * Landlord notification templates and channel rows; missing keys only.
 */
final class NotificationTemplateSeeder extends Seeder
{
    public function __construct(private readonly NotificationTemplateService $templates) {}

    public function run(): void
    {
        $this->templates->seedDefaults(NotificationScope::Landlord);
    }
}
