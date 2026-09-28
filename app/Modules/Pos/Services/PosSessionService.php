<?php

declare(strict_types=1);

namespace App\Modules\Pos\Services;

use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Pos\Models\PosRegister;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Cash sessions (spec §51.1, §51.5). expected_cash = opening float + the
 * cash taken in the session (cash refunds are negative rows); the variance
 * is what the cashier counted against it.
 */
final readonly class PosSessionService
{
    public function __construct(
        private TenantSettingsService $settings,
        private NotificationDispatchService $notifications,
        private CurrencyService $currencies,
    ) {}

    public function openSession(PosRegister $register, User $cashier, string $openingCash): PosSession
    {
        Validator::make(['opening_cash_float' => $openingCash], ['opening_cash_float' => ['required', 'numeric', 'min:0', 'decimal:0,4']])->validate();

        if (! $register->is_active) {
            throw ApiException::unprocessable('register_inactive', 'This register is inactive.');
        }

        try {
            return DB::connection('tenant')->transaction(function () use ($register, $cashier, $openingCash): PosSession {
                $session = new PosSession;
                $session->forceFill([
                    'pos_register_id' => $register->id,
                    'opened_by_user_id' => $cashier->id,
                    'opening_cash_float' => Money::round(Money::normalize($openingCash), $this->currencies->baseCurrency()),
                    'status' => PosSession::OPEN,
                    'opened_at' => now(),
                ])->save();

                return $session->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('session_already_open', 'This register already has an open session.');
        }
    }

    public function closeSession(PosSession $session, string $closingCash, User $by): PosSession
    {
        Validator::make(['closing_cash_float' => $closingCash], ['closing_cash_float' => ['required', 'numeric', 'min:0', 'decimal:0,4']])->validate();

        $closed = DB::connection('tenant')->transaction(function () use ($session, $closingCash, $by): PosSession {
            /** @var PosSession $locked */
            $locked = PosSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($locked->status !== PosSession::OPEN) {
                throw ApiException::invalidTransition($locked->status, PosSession::CLOSED);
            }

            $locked->forceFill([
                'status' => PosSession::CLOSED,
                'closed_by_user_id' => $by->id,
                'closed_at' => now(),
                'closing_cash_float' => Money::round(Money::normalize($closingCash), $this->currencies->baseCurrency()),
            ]);
            $this->applyCashTotals($locked);
            $locked->save();

            return $locked;
        });

        $this->flagVariance($closed->load('register'));

        return $closed;
    }

    /**
     * An offline sale synced into a session that has since closed (§51.6):
     * its cash was in the drawer when it was counted, so the expected cash
     * and the variance are recomputed.
     */
    public function refreshClosedTotals(PosSession $session): void
    {
        /** @var PosSession $locked */
        $locked = PosSession::query()->lockForUpdate()->findOrFail($session->id);

        if ($locked->status === PosSession::CLOSED) {
            $this->applyCashTotals($locked);
            $locked->save();
        }
    }

    public function getCurrentSession(PosRegister $register): ?PosSession
    {
        return PosSession::query()->with('openedBy:id,name')->where('pos_register_id', $register->id)->where('status', PosSession::OPEN)->first();
    }

    /**
     * Totals by method, sale count and variance (§51.8).
     *
     * @return array{payments_by_method: array<string, string>, cash_in: string, sales_count: int, voided_count: int, sales_total: string, expected_cash: string, cash_variance: string|null}
     */
    public function getSessionSummary(PosSession $session): array
    {
        $byMethod = OrderPayment::query()->where('pos_session_id', $session->id)->where('status', OrderPayment::SUCCESSFUL)
            ->groupBy('payment_method')->selectRaw('payment_method, SUM(amount_paid) AS total')->pluck('total', 'payment_method')
            ->map(static fn (mixed $v): string => Money::normalize((string) $v))->all();
        $sales = Order::query()->where('pos_session_id', $session->id);

        return [
            'payments_by_method' => $byMethod,
            'cash_in' => $byMethod['cash'] ?? Money::normalize(0),
            'sales_count' => (clone $sales)->where('status', '!=', Order::REFUNDED)->count(),
            'voided_count' => (clone $sales)->where('status', Order::REFUNDED)->whereNotNull('cancelled_at')->count(),
            'sales_total' => Money::normalize((string) (clone $sales)->where('status', '!=', Order::REFUNDED)->sum('total')),
            'expected_cash' => $session->expected_cash !== null ? (string) $session->expected_cash : $this->expectedCash($session),
            'cash_variance' => $session->cash_variance === null ? null : (string) $session->cash_variance,
        ];
    }

    /**
     * @param  array{register_id?: int, cashier_id?: int, status?: string, from?: string, to?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, PosSession>
     */
    public function listSessions(array $filters = []): LengthAwarePaginator
    {
        return PosSession::query()->with(['register:id,name', 'openedBy:id,name', 'closedBy:id,name'])
            ->when(isset($filters['register_id']), static fn ($q) => $q->where('pos_register_id', $filters['register_id']))
            ->when(isset($filters['cashier_id']), static fn ($q) => $q->where('opened_by_user_id', $filters['cashier_id']))
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['from']), static fn ($q) => $q->whereDate('opened_at', '>=', $filters['from']))
            ->when(isset($filters['to']), static fn ($q) => $q->whereDate('opened_at', '<=', $filters['to']))
            ->orderByDesc('opened_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    private function expectedCash(PosSession $session): string
    {
        $cash = (string) OrderPayment::query()->where('pos_session_id', $session->id)->where('payment_method', 'cash')
            ->where('status', OrderPayment::SUCCESSFUL)->sum('amount_paid');

        return Money::add((string) $session->opening_cash_float, Money::normalize($cash));
    }

    private function applyCashTotals(PosSession $session): void
    {
        $expected = $this->expectedCash($session);

        $session->forceFill([
            'expected_cash' => $expected,
            'cash_variance' => Money::sub((string) $session->closing_cash_float, $expected),
        ]);
    }

    /**
     * pos.session_variance_flagged when |variance| > pos_cash_variance_threshold.
     */
    private function flagVariance(PosSession $session): void
    {
        $threshold = $this->settings->get('pos_cash_variance_threshold');
        $variance = (string) $session->cash_variance;
        $absolute = str_starts_with($variance, '-') ? substr($variance, 1) : $variance;

        if ($threshold === null || Money::cmp($absolute, Money::normalize((string) $threshold)) <= 0) {
            return;
        }

        $this->notifications->dispatch('pos.session_variance_flagged', $session, [
            'register_name' => $session->register->name,
            'session_id' => $session->id,
            'variance' => Money::format($variance, $this->currencies->baseCurrency()),
        ]);
    }
}
