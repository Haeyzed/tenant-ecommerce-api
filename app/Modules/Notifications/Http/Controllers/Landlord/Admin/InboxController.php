<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Http\Controllers\Concerns\ServesNotificationInbox;

/**
 * The platform user's own in-app notifications (BG-08): landlord templates
 * addressed to platform users with the database channel land here.
 */
final class InboxController extends Controller
{
    use ServesNotificationInbox;
}
