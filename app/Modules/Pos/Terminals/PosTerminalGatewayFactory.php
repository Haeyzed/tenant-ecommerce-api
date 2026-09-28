<?php

declare(strict_types=1);

namespace App\Modules\Pos\Terminals;

use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Pos\Models\PosRegister;
use App\Shared\Exceptions\ApiException;

/**
 * Builds the terminal driver of a register (spec §51.4) from its
 * terminal_provider and encrypted terminal_credentials.
 */
final class PosTerminalGatewayFactory
{
    /**
     * @var array<string, class-string<AbstractTerminalGateway>>
     */
    public const array DRIVERS = [
        'moniepoint' => MoniepointTerminalGateway::class,
        'opay' => OpayTerminalGateway::class,
        'stripe_terminal' => StripeTerminalGateway::class,
    ];

    public function forRegister(PosRegister $register): PosTerminalGatewayInterface
    {
        $provider = $register->terminal_provider;

        if ($provider === null || ! isset(self::DRIVERS[$provider])) {
            throw ApiException::unprocessable('terminal_not_configured', 'This register has no card terminal.');
        }

        $class = self::DRIVERS[$provider];

        return new $class((array) $register->terminal_credentials);
    }

    /**
     * The driver that took a card_terminal payment: its register is kept on
     * the payment row (meta.pos_register_id).
     */
    public function forPayment(OrderPayment $payment): PosTerminalGatewayInterface
    {
        $register = PosRegister::query()->find((int) ($payment->meta['pos_register_id'] ?? 0));

        if ($register === null || $register->terminal_provider !== $payment->provider) {
            throw ApiException::unprocessable('terminal_not_configured', 'The register that took this card payment no longer has its terminal.');
        }

        return $this->forRegister($register);
    }

    /**
     * @return list<string>
     */
    public static function credentialKeys(string $provider): array
    {
        return isset(self::DRIVERS[$provider]) ? self::DRIVERS[$provider]::CREDENTIALS : [];
    }
}
