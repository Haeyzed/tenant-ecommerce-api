<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Services\MailService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/admin/settings/mail/test (spec §16.2): sends a test message
 * with a configuration before the tenant saves it.
 */
final class MailSettingsController extends Controller
{
    public function test(Request $request, MailService $mail): JsonResponse
    {
        $config = $request->validate([
            'mail_driver' => ['required', 'in:smtp,sendmail,ses'],
            'host' => ['required_unless:mail_driver,sendmail', 'nullable', 'string', 'max:255'],
            'port' => ['required_if:mail_driver,smtp', 'nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['nullable', 'in:tls,ssl'],
            'from_address' => ['required', 'email:rfc', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'to' => ['sometimes', 'email:rfc', 'max:255'],
        ]);

        $sent = $mail->testCustomConfig($config, $config['to'] ?? $request->user()?->email);

        return APIResponse::success(['sent' => $sent], $sent ? 'Test email sent' : 'The test email could not be sent with these settings');
    }
}
