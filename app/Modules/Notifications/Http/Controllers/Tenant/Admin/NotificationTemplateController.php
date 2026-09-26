<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Http\Controllers\Concerns\ManagesTemplates;

/**
 * Tenant notification templates (spec §17.7).
 */
final class NotificationTemplateController extends Controller
{
    use ManagesTemplates;
}
