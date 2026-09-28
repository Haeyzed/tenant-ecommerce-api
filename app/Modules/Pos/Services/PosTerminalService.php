<?php

declare(strict_types=1);

namespace App\Modules\Pos\Services;

use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Pos\Models\PosRegister;
use App\Modules\Pos\Models\PosTerminalCharge;
use App\Modules\Pos\Terminals\PosTerminalGatewayFactory;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Payments\PaymentGatewayException;
use App\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Card-terminal charges (spec §51.4, A-35): initiated and verified through
 * their own routes before the sale is submitted. The provider is called
 * outside any transaction; an unknown outcome stays pending and is polled.
 */
final readonly class PosTerminalService
{
    public function __construct(
        private PosTerminalGatewayFactory $factory,
        private CurrencyService $currencies,
        private TenantSettingsService $settings,
    ) {}

    public function initiateCharge(PosRegister $register, string $amount, ?User $by = null): PosTerminalCharge
    {
        Validator::make(['amount' => $amount], ['amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4']])->validate();
        $this->assertLiveMode();

        if (! $register->is_active) {
            throw ApiException::unprocessable('register_inactive', 'This register is inactive.');
        }

        $driver = $this->factory->forRegister($register);
        $currency = $this->currencies->baseCurrency();

        $charge = new PosTerminalCharge;
        $charge->forceFill([
            'pos_register_id' => $register->id,
            'provider' => $driver->provider(),
            'reference' => 'PTC-'.Str::upper(Str::random(16)),
            'amount' => Money::round(Money::normalize($amount), $currency),
            'currency_code' => $currency,
            'status' => PosTerminalCharge::PENDING,
            'initiated_by_user_id' => $by?->id,
        ])->save();

        try {
            $result = $driver->initiateCharge((string) $charge->amount, $currency, $charge->reference, ['description' => 'Sale at '.$register->name]);
        } catch (PaymentGatewayException $e) {
            if ($e->pending) {
                // The terminal may have the charge: poll it.
                return $charge;
            }

            $charge->forceFill(['status' => PosTerminalCharge::FAILED, 'failure_reason' => 'terminal_rejected'])->save();

            throw new ApiException('terminal_error', 'The terminal provider refused the charge. Check the terminal and try again.', 502);
        }

        $charge->forceFill([
            'provider_reference' => $result['provider_reference'],
            'status' => $result['status'],
            'failure_reason' => $result['failure_reason'] === null ? null : mb_substr($result['failure_reason'], 0, 255),
        ])->save();

        return $charge;
    }

    /**
     * Asks the provider for a pending charge's outcome; a settled charge is
     * returned as it is.
     */
    public function verifyCharge(PosRegister $register, string $reference): PosTerminalCharge
    {
        $charge = PosTerminalCharge::query()->where('pos_register_id', $register->id)->where('reference', $reference)->first()
            ?? throw new ApiException('terminal_charge_not_found', 'No terminal charge with this reference on this register.', 404);

        if ($charge->status !== PosTerminalCharge::PENDING) {
            return $charge;
        }

        try {
            $result = $this->factory->forRegister($register)->verifyCharge($charge->reference, $charge->provider_reference);
        } catch (PaymentGatewayException) {
            return $charge;
        }

        if ($result['status'] === 'successful' && ($result['amount'] !== null && (Money::cmp($result['amount'], (string) $charge->amount) !== 0
            || ($result['currency_code'] !== null && $result['currency_code'] !== $charge->currency_code)))) {
            // Money the terminal took for another amount is never accepted silently.
            $result = [...$result, 'status' => 'failed', 'failure_reason' => 'amount_mismatch'];
        }

        DB::connection('tenant')->transaction(function () use ($charge, $result): void {
            /** @var PosTerminalCharge $locked */
            $locked = PosTerminalCharge::query()->lockForUpdate()->findOrFail($charge->id);

            if ($locked->status === PosTerminalCharge::PENDING && $result['status'] !== 'pending') {
                $locked->forceFill([
                    'status' => $result['status'],
                    'provider_reference' => $result['provider_reference'] ?? $locked->provider_reference,
                    'card_last4' => $result['card_last4'],
                    'failure_reason' => $result['failure_reason'] === null ? null : mb_substr($result['failure_reason'], 0, 255),
                ])->save();
            }

            $charge->setRawAttributes($locked->getAttributes(), true);
        });

        return $charge;
    }

    /**
     * For the sale commit (§51.3 step 4), inside its transaction: the charge
     * (re-verified by the caller before the transaction) is successful, for
     * this amount and not yet used. Locked until the sale commits.
     */
    public function claimForSale(PosRegister $register, string $reference, string $amount): PosTerminalCharge
    {
        /** @var PosTerminalCharge|null $charge */
        $charge = PosTerminalCharge::query()->where('pos_register_id', $register->id)->where('reference', $reference)->lockForUpdate()->first();

        return match (true) {
            $charge === null => throw ApiException::unprocessable('terminal_charge_not_found', 'No terminal charge with this reference on this register.'),
            $charge->status !== PosTerminalCharge::SUCCESSFUL => throw ApiException::unprocessable('terminal_charge_not_successful', 'The card payment is not confirmed yet.', ['status' => $charge->status]),
            $charge->order_payment_id !== null => throw ApiException::conflict('terminal_charge_used', 'This card payment already paid another sale.'),
            Money::cmp((string) $charge->amount, Money::normalize($amount)) !== 0 => throw ApiException::unprocessable('terminal_charge_amount_mismatch', 'The card payment is for another amount.', ['charged' => (string) $charge->amount]),
            default => $charge,
        };
    }

    /**
     * Card terminals have no test mode in v1 (§40.8): refused in test mode.
     */
    public function assertLiveMode(): void
    {
        if ((string) $this->settings->get('payment_mode', 'test') === 'test') {
            throw ApiException::unprocessable('payment_mode_mismatch', 'Card terminals take real money: switch the store to live mode to use them.');
        }
    }
}
