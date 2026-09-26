<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/**
 * Turns a template's audience plus the dispatch argument into recipients
 * (spec §17.3, Assumption A-10).
 *
 * The argument is either explicit recipients (a notifiable model, an
 * AnonymousNotifiable, or a list of them) or the triggering record from
 * which each audience value is resolved.
 */
final class AudienceResolver
{
    /**
     * Recipient class => audience value. Explicit recipients whose audience
     * the template does not target are dropped, so narrowing an audience in
     * the matrix is honoured for every caller.
     *
     * @var array<string, string>
     */
    private const array RECIPIENT_AUDIENCES = [
        User::class => 'admin',
        PlatformUser::class => 'platform_user',
        Tenant::class => 'tenant',
        'customer' => 'customer',
        'seller' => 'seller',
        'supplier' => 'supplier',
        'sales_agent' => 'sales_agent',
        'affiliate' => 'affiliate',
    ];

    /**
     * Extra addresses for the "admin" audience of specific keys (§17.3).
     *
     * @var array<string, string>
     */
    private const array ADMIN_EXTRA_SETTINGS = [
        'order.new_order_received' => 'order_notification_email',
        'inventory.low_stock' => 'low_stock_notification_recipients',
    ];

    /**
     * @param  list<string>  $audience
     * @return list<object> notifiable recipients
     */
    public function resolve(string $key, array $audience, mixed $subject): array
    {
        if ($this->isExplicit($subject)) {
            $recipients = is_iterable($subject) ? [...$subject] : [$subject];

            return array_values(array_filter(
                $recipients,
                fn (object $recipient): bool => $recipient instanceof AnonymousNotifiable
                    || in_array($this->audienceOf($recipient), $audience, true),
            ));
        }

        $recipients = [];

        foreach ($audience as $value) {
            array_push($recipients, ...$this->forAudience($key, $value, $subject instanceof Model ? $subject : null));
        }

        return $this->unique($recipients);
    }

    private function isExplicit(mixed $subject): bool
    {
        if ($subject instanceof AnonymousNotifiable) {
            return true;
        }

        if (is_iterable($subject)) {
            return true;
        }

        return $subject instanceof Model && $this->audienceOf($subject) !== null;
    }

    private function audienceOf(object $recipient): ?string
    {
        if (! $recipient instanceof Model) {
            return null;
        }

        return self::RECIPIENT_AUDIENCES[$recipient::class]
            ?? self::RECIPIENT_AUDIENCES[$recipient->getMorphClass()]
            ?? null;
    }

    /**
     * @return list<object>
     */
    private function forAudience(string $key, string $audience, ?Model $record): array
    {
        return match ($audience) {
            'admin' => $this->admins($key),
            'customer' => $this->related($record, 'customer', guestContact: true),
            'seller' => $this->related($record, 'seller'),
            'supplier' => $this->related($record, 'supplier'),
            'sales_agent' => $this->related($record, 'salesAgent'),
            'affiliate' => $this->related($record, 'affiliate'),
            'tenant' => $record instanceof Tenant ? [$record] : $this->related($record, 'tenant'),
            // Platform users and registrants are always named explicitly.
            default => [],
        };
    }

    /**
     * Active staff holding owner or admin, plus the configured extra
     * addresses for the key.
     *
     * @return list<object>
     */
    private function admins(string $key): array
    {
        if (! tenancy()->initialized) {
            return [];
        }

        $recipients = User::query()
            ->role(['owner', 'admin'], 'staff')
            ->where('is_active', true)
            ->get()
            ->all();

        $setting = self::ADMIN_EXTRA_SETTINGS[$key] ?? null;

        if ($setting !== null) {
            $value = app(TenantSettingsService::class)->get($setting);

            foreach (array_filter(is_array($value) ? $value : [$value]) as $email) {
                $recipients[] = Notification::route('mail', (string) $email);
            }
        }

        return $recipients;
    }

    /**
     * @return list<object>
     */
    private function related(?Model $record, string $relation, bool $guestContact = false): array
    {
        if ($record === null) {
            return [];
        }

        if (method_exists($record, $relation)) {
            $record->loadMissing($relation);
            $related = $record->getRelation($relation);

            if ($related instanceof Model) {
                return [$related];
            }
        }

        if ($guestContact) {
            // Raw attributes: strict mode forbids reading absent columns.
            $attributes = $record->getAttributes();
            $routes = array_filter([
                'mail' => $attributes['guest_email'] ?? null,
                'sms' => $attributes['guest_phone'] ?? null,
            ]);

            if ($routes !== []) {
                $anonymous = new AnonymousNotifiable;

                foreach ($routes as $channel => $route) {
                    $anonymous->route($channel, $route);
                }

                return [$anonymous];
            }
        }

        return [];
    }

    /**
     * @param  list<object>  $recipients
     * @return list<object>
     */
    private function unique(array $recipients): array
    {
        $seen = [];
        $result = [];

        foreach ($recipients as $recipient) {
            $id = $recipient instanceof Model
                ? $recipient->getMorphClass().':'.$recipient->getKey()
                : 'anonymous:'.md5(serialize($recipient instanceof AnonymousNotifiable ? $recipient->routes : spl_object_id($recipient)));

            if (! isset($seen[$id])) {
                $seen[$id] = true;
                $result[] = $recipient;
            }
        }

        return $result;
    }
}
