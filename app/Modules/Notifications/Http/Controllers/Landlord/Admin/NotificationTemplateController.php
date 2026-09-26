<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Http\Controllers\Concerns\ManagesTemplates;

/**
 * Platform notification templates (spec §17.7); platform users only.
 */
final class NotificationTemplateController extends Controller
{
    use ManagesTemplates;
}
