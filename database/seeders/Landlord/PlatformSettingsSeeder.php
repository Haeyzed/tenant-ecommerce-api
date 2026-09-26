<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Modules\Settings\Services\PlatformSettingsService;
use Illuminate\Database\Seeder;

/**
 * Every platform setting with its default; inserts absent keys only (§7.6).
 */
final class PlatformSettingsSeeder extends Seeder
{
    public function __construct(private readonly PlatformSettingsService $settings) {}

    public function run(): void
    {
        $this->settings->seedDefaults();
    }
}
