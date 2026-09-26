<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Services;

use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateClick;
use App\Modules\Affiliates\Support\AffiliateIdentity;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Referral clicks and the signed referral token (spec §21A.3). The token
 * is opaque, carries no personal data and cannot be forged.
 */
final readonly class AffiliateTrackingService
{
    public function __construct(
        private AffiliateService $affiliates,
        private PlatformSettingsService $settings,
    ) {}

    /**
     * Records a click on an approved affiliate's code; a second click by
     * the same visitor on the same affiliate within 24 hours reuses the
     * first row and gets a fresh token.
     *
     * @param  array{visitor_id?: string|null, landing_path?: string|null, referrer?: string|null, utm_source?: string|null, utm_medium?: string|null, utm_campaign?: string|null}  $context
     * @return array{referral_token: string, visitor_id: string, expires_at: string, click: AffiliateClick}
     */
    public function recordClick(string $code, array $context, Request $request): array
    {
        $affiliate = $this->affiliates->programmeEnabled() ? $this->affiliates->resolveCode($code) : null;

        if ($affiliate === null) {
            throw new ApiException('affiliate_code_not_found', 'This referral code is not valid.', 404);
        }

        $visitorId = isset($context['visitor_id']) && Str::isUuid((string) $context['visitor_id'])
            ? strtolower((string) $context['visitor_id'])
            : (string) Str::uuid();

        $click = AffiliateClick::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('visitor_id', $visitorId)
            ->where('created_at', '>=', now()->subHours((int) config('affiliates.click_dedupe_hours', 24)))
            ->latest('id')
            ->first()
            ?? $this->createClick($affiliate, $visitorId, $context, $request);

        $expiresAt = CarbonImmutable::now()->addDays((int) $this->settings->get('affiliate_cookie_days', 30));

        return [
            'referral_token' => $this->issueToken($click, $expiresAt),
            'visitor_id' => $visitorId,
            'expires_at' => $expiresAt->toIso8601String(),
            'click' => $click,
        ];
    }

    /**
     * A click created at registration for a `ref` code (source
     * registration_code, §21A.4).
     */
    public function recordRegistrationClick(Affiliate $affiliate, Request $request): AffiliateClick
    {
        return $this->createClick($affiliate, (string) Str::uuid(), ['landing_path' => '/register'], $request);
    }

    /**
     * {click_id, affiliate_id, expires_at}, base64url-encoded and signed
     * with an HMAC keyed from the application key.
     */
    public function issueToken(AffiliateClick $click, ?CarbonImmutable $expiresAt = null): string
    {
        $expiresAt ??= CarbonImmutable::now()->addDays((int) $this->settings->get('affiliate_cookie_days', 30));
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'c' => $click->id,
            'a' => $click->affiliate_id,
            'e' => $expiresAt->getTimestamp(),
        ])), '+/', '-_'), '=');

        return $payload.'.'.$this->sign($payload);
    }

    /**
     * The click of a valid, unexpired token whose affiliate still matches;
     * null for anything else. Never throws: attribution never blocks a
     * registration.
     */
    public function parseToken(string $token): ?AffiliateClick
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2 || ! hash_equals($this->sign($parts[0]), $parts[1])) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);

        if (! is_array($data) || ! isset($data['c'], $data['a'], $data['e']) || (int) $data['e'] < now()->getTimestamp()) {
            return null;
        }

        $click = AffiliateClick::query()->with('affiliate')->find((int) $data['c']);

        return $click !== null && $click->affiliate_id === (int) $data['a'] ? $click : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function createClick(Affiliate $affiliate, string $visitorId, array $context, Request $request): AffiliateClick
    {
        $path = isset($context['landing_path']) ? (string) parse_url((string) $context['landing_path'], PHP_URL_PATH) : null;
        $referrerHost = isset($context['referrer']) ? parse_url((string) $context['referrer'], PHP_URL_HOST) : null;

        /** @var AffiliateClick $click */
        $click = AffiliateClick::query()->create([
            'affiliate_id' => $affiliate->id,
            'visitor_id' => $visitorId,
            'landing_path' => $path !== null && $path !== '' ? mb_substr($path, 0, 512) : null,
            'referrer_host' => is_string($referrerHost) ? mb_substr(strtolower($referrerHost), 0, 255) : null,
            'utm_source' => $this->utm($context['utm_source'] ?? null),
            'utm_medium' => $this->utm($context['utm_medium'] ?? null),
            'utm_campaign' => $this->utm($context['utm_campaign'] ?? null),
            'ip_hash' => AffiliateIdentity::ipHash($request->ip()),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512) ?: null,
        ]);

        return $click;
    }

    private function utm(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 255) : null;
    }

    private function sign(string $payload): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, hash('sha256', 'affiliate-referral-token|'.config('app.key')), true)), '+/', '-_'), '=');
    }
}
