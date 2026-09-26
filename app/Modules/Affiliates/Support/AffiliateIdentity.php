<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Support;

/**
 * Keyed IP hashing and email normalisation for the affiliate fraud rules
 * (spec §21A.7). Raw IP addresses are never stored in affiliate records.
 */
final class AffiliateIdentity
{
    private const array GMAIL_DOMAINS = ['gmail.com', 'googlemail.com'];

    /**
     * HMAC-SHA256 of the IP with a key derived from the application key,
     * so the hash cannot be reversed by enumerating the IPv4 space.
     */
    public static function ipHash(?string $ip): string
    {
        return hash_hmac('sha256', (string) $ip, hash('sha256', 'affiliate-ip|'.config('app.key')));
    }

    /**
     * Lower-case, "+tag" removed, and dots removed for Gmail addresses.
     */
    public static function normalizeEmail(string $email): string
    {
        [$local, $domain] = self::split($email);

        return $local.'@'.$domain;
    }

    public static function domain(string $email): string
    {
        return self::split($email)[1];
    }

    public static function isGmail(string $domain): bool
    {
        return in_array($domain, self::GMAIL_DOMAINS, true);
    }

    /**
     * @return list<string>
     */
    public static function gmailDomains(): array
    {
        return self::GMAIL_DOMAINS;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function split(string $email): array
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        $local = $at === false ? $email : substr($email, 0, $at);
        $domain = $at === false ? '' : substr($email, $at + 1);

        $local = explode('+', $local, 2)[0];

        if (self::isGmail($domain)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return [$local, $domain];
    }
}
