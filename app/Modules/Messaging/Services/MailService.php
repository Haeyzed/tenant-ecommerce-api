<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Mail\TemplatedMail;
use App\Modules\Settings\Services\TenantSettingsService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email transport (spec §16.2). Landlord mail always uses the platform
 * mailer. Tenant mail uses the platform mailer with the store's name and
 * reply-to, or the tenant's own provider, built per message with
 * Mail::build(): the global mailer configuration is never mutated.
 */
final class MailService
{
    public function __construct(private readonly TenantSettingsService $settings) {}

    /**
     * @param  bool  $platform  force the platform mailer and identity (landlord
     *                          notifications sent while a tenant context is active)
     */
    public function send(Mailable $mailable, mixed $recipient, bool $platform = false): void
    {
        if ($platform || ! tenancy()->initialized) {
            $mailable->from((string) config('mail.from.address'), (string) config('mail.from.name'));
            Mail::mailer()->to($recipient)->send($mailable);

            return;
        }

        $custom = $this->settings->getMailConfig();

        if ($custom !== null) {
            $mailable->from((string) $custom['from_address'], (string) ($custom['from_name'] ?? $this->settings->get('store_name')));
        } else {
            $mailable->from((string) config('mail.from.address'), (string) $this->settings->get('store_name'));
        }

        $replyTo = $this->settings->get('store_contact_email');

        if (filled($replyTo)) {
            $mailable->replyTo((string) $replyTo);
        }

        $this->resolveMailer()->to($recipient)->send($mailable);
    }

    public function resolveMailer(): Mailer
    {
        $custom = tenancy()->initialized ? $this->settings->getMailConfig() : null;

        return $custom === null ? Mail::mailer() : Mail::build(self::transportConfig($custom));
    }

    /**
     * Sends a test message with a configuration before it is saved.
     *
     * @param  array<string, mixed>  $config
     */
    public function testCustomConfig(array $config, ?string $to = null): bool
    {
        $to ??= (string) $this->settings->get('store_contact_email');

        try {
            $mail = new TemplatedMail('Test email', 'Your custom email settings work. Messages from your store will be sent with them.');
            $mail->from((string) $config['from_address'], (string) ($config['from_name'] ?? $this->settings->get('store_name')));

            Mail::build(self::transportConfig($config))->to($to)->send($mail);

            return true;
        } catch (Throwable $e) {
            // The message may contain the host but never the credentials.
            Log::info('Custom mail configuration test failed.', ['driver' => $config['mail_driver'] ?? null, 'error' => $e::class]);

            return false;
        }
    }

    /**
     * Maps custom_mail_settings (§13.4) to a Laravel mailer configuration.
     * For SES, username and password hold the access key and secret and
     * host holds the region.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function transportConfig(array $config): array
    {
        return match ($config['mail_driver'] ?? 'smtp') {
            'sendmail' => ['transport' => 'sendmail', 'path' => '/usr/sbin/sendmail -bs -i'],
            'ses' => [
                'transport' => 'ses',
                'key' => $config['username'] ?? null,
                'secret' => $config['password'] ?? null,
                'region' => $config['host'] ?? 'us-east-1',
            ],
            default => [
                'transport' => 'smtp',
                'scheme' => ($config['encryption'] ?? null) === 'ssl' ? 'smtps' : 'smtp',
                'host' => $config['host'] ?? null,
                'port' => (int) ($config['port'] ?? 587),
                'username' => $config['username'] ?? null,
                'password' => $config['password'] ?? null,
                'timeout' => 15,
            ],
        };
    }
}
