<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Jobs\PostAccountingEntry;
use App\Modules\Accounting\Models\AccountingPostingRequest;
use App\Modules\Orders\Models\Order;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The accounting outbox (spec §57.3, A-43). Business services call record()
 * inside their own transaction: the request row commits with the business
 * change, and PostAccountingEntry posts it after commit, so an accounting
 * problem never blocks a sale and a posting is never lost. A no-op unless
 * `accounting` is enabled, and for test orders (§40.8).
 */
final readonly class AccountingOutbox
{
    public function __construct(
        private FeatureAccessService $features,
        private TenantSettingsService $settings,
    ) {}

    /**
     * @param  string  $method  an AccountingService posting method
     * @param  array<string, mixed>|null  $payload  snapshotted arguments (amounts at the time of the event)
     * @return int|null the request id, or null when nothing was recorded
     */
    public function record(string $method, Model $record, DateTimeInterface $eventDate, string $postingKey, ?array $payload = null): ?int
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant || ! $this->enabled($tenant) || $this->isTest($record)) {
            return null;
        }

        $timezone = (string) ($this->settings->get('timezone') ?: 'UTC');
        $inserted = DB::connection('tenant')->table('accounting_posting_requests')->insertOrIgnore([
            'posting_key' => $postingKey,
            'method' => $method,
            'reference_type' => $record->getMorphClass(),
            'reference_id' => $record->getKey(),
            'event_date' => Carbon::instance(Carbon::parse($eventDate))->setTimezone($timezone)->toDateString(),
            'payload' => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => AccountingPostingRequest::PENDING,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return null;
        }

        $id = (int) AccountingPostingRequest::query()->where('posting_key', $postingKey)->value('id');
        PostAccountingEntry::dispatch((string) $tenant->getTenantKey(), $id)->afterCommit();

        return $id;
    }

    /**
     * The next free version of a posting key, for re-posts after an edit
     * (§57.3 "Update and delete of manual source records").
     */
    public function nextVersion(string $baseKey): int
    {
        return DB::connection('tenant')->table('accounting_posting_requests')
            ->where(static fn ($q) => $q->where('posting_key', $baseKey)->orWhere('posting_key', 'like', $baseKey.':v%'))
            ->count() + 1;
    }

    /**
     * The newest posting key of a record's posting (the base key or its
     * latest :vN re-post).
     */
    public function currentKey(string $baseKey): ?string
    {
        return DB::connection('tenant')->table('accounting_posting_requests')
            ->where(static fn ($q) => $q->where('posting_key', $baseKey)->orWhere('posting_key', 'like', $baseKey.':v%'))
            ->orderByDesc('id')->value('posting_key');
    }

    public function enabled(Tenant $tenant): bool
    {
        return $this->features->state($tenant, 'accounting') === ModuleState::Enabled;
    }

    private function isTest(Model $record): bool
    {
        if ($record instanceof Order) {
            return $record->is_test;
        }

        $order = $record->getAttribute('order_id') === null ? null : Order::withTrashed()->find($record->getAttribute('order_id'));

        return $order?->is_test ?? false;
    }
}
