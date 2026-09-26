<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Services\NotificationTemplateService;
use Illuminate\Database\Seeder;

/**
 * Inserts missing tenant notification templates and channel rows (spec
 * §17.4). Never overwrites a tenant's customisation.
 */
final class NotificationTemplateSeeder extends Seeder
{
    public function __construct(private readonly NotificationTemplateService $templates) {}

    public function run(): void
    {
        $this->templates->seedDefaults(NotificationScope::Tenant);
    }
}
