<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Http\Controllers\Concerns\ManagesMatrix;

/**
 * Platform notification channel matrix (spec §17.7).
 */
final class NotificationMatrixController extends Controller
{
    use ManagesMatrix;
}
