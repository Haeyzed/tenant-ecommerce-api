<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Services;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Legal\Services\LegalDocumentService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Affiliate accounts and their lifecycle (spec §21A.2).
 */
final readonly class AffiliateService
{
    public const string CODE_PATTERN = '/^[A-Z0-9]{4,20}$/';

    public function __construct(
        private PlatformSettingsService $settings,
        private LegalDocumentService $legal,
        private NotificationDispatchService $notifications,
    ) {}

    public function programmeEnabled(): bool
    {
        return (bool) $this->settings->get('affiliate_program_enabled', false);
    }

    /**
     * A new application, pending until the email is verified and a manager
     * reviews it. A rejected email may apply again after 90 days.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data, Request $request): Affiliate
    {
        $agreement = $this->legal->current('affiliate_agreement');

        if (! $this->programmeEnabled() || $agreement === null) {
            throw new ApiException('affiliate_program_unavailable', 'The affiliate programme is not accepting applications.', 503);
        }

        $data['email'] = strtolower(trim((string) ($data['email'] ?? '')));

        $validated = validator($data, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'website_url' => ['sometimes', 'nullable', 'url:https,http', 'max:255'],
            'country_id' => ['sometimes', 'nullable', 'integer', Rule::exists('landlord.countries', 'id')],
            'promotion_methods' => ['required', 'string', 'max:2000'],
            'accepted_legal_document_ids' => ['required', 'array', 'min:1'],
            'accepted_legal_document_ids.*' => ['integer'],
        ])->validate();

        if (! in_array($agreement->id, array_map('intval', $validated['accepted_legal_document_ids']), true)) {
            throw ApiException::unprocessable('legal_version_outdated', 'Please accept the current affiliate agreement.', [
                'current' => [['id' => $agreement->id, 'document_type' => $agreement->document_type, 'version' => $agreement->version, 'title' => $agreement->title]],
            ]);
        }

        $affiliate = DB::connection('landlord')->transaction(function () use ($validated, $agreement, $request): Affiliate {
            /** @var Affiliate|null $existing */
            $existing = Affiliate::query()->where('email', $validated['email'])->lockForUpdate()->first();

            if ($existing !== null && ! $this->mayReapply($existing)) {
                throw ValidationException::withMessages(['email' => ['An affiliate account with this email already exists.']]);
            }

            $affiliate = $existing ?? new Affiliate;
            $affiliate->fill([
                'name' => trim($validated['name']),
                'email' => $validated['email'],
                'password' => $validated['password'],
                'phone' => $validated['phone'] ?? null,
                'company_name' => $validated['company_name'] ?? null,
                'website_url' => $validated['website_url'] ?? null,
                'country_id' => $validated['country_id'] ?? null,
                'promotion_methods' => $validated['promotion_methods'],
            ]);
            $affiliate->forceFill([
                'public_id' => $existing?->public_id ?? (string) Str::uuid(),
                'status' => Affiliate::PENDING,
                'email_verified_at' => null,
                'rejection_reason' => null,
                'status_reason' => null,
            ])->save();

            $this->legal->recordAcceptances([$agreement->id], [
                'affiliate_id' => $affiliate->id, 'name' => $affiliate->name, 'email' => $affiliate->email,
            ], 'affiliate_application', $request);

            return $affiliate;
        });

        $affiliate->sendEmailVerificationNotification();
        $this->notifications->dispatch('affiliate.application_received', $affiliate, ['name' => $affiliate->name]);

        return $affiliate;
    }

    /**
     * Once the email is verified the application is shown for review.
     */
    public function announceApplication(Affiliate $affiliate): void
    {
        if ($affiliate->status !== Affiliate::PENDING) {
            return;
        }

        $managers = PlatformUser::query()->withPlatformRole('affiliate-manager')->where('is_active', true)->get();

        if ($managers->isNotEmpty()) {
            $this->notifications->dispatch('affiliate.application_submitted', $managers, [
                'affiliate_name' => $affiliate->name,
                'affiliate_email' => $affiliate->email,
            ], data: ['affiliate_id' => $affiliate->id]);
        }
    }

    public function approve(Affiliate $affiliate, ?string $code, PlatformUser $by): Affiliate
    {
        $affiliate = $this->transition($affiliate, [Affiliate::PENDING], Affiliate::APPROVED, function (Affiliate $locked) use ($code, $by): void {
            if ($locked->email_verified_at === null) {
                throw ApiException::unprocessable('affiliate_email_unverified', 'The applicant has not verified their email yet.');
            }

            $locked->forceFill([
                'referral_code' => $code !== null ? $this->assertCodeAvailable($code, $locked->id) : ($locked->referral_code ?? $this->generateCode()),
                'approved_at' => now(),
                'approved_by' => $by->id,
            ]);
        });

        ActivityRecorder::landlord('affiliates', 'Affiliate approved', $affiliate, ['referral_code' => $affiliate->referral_code], $by);

        $this->notifications->dispatch('affiliate.approved', $affiliate, [
            'name' => $affiliate->name,
            'referral_link' => (string) $affiliate->referralLink(),
        ]);

        return $affiliate;
    }

    public function reject(Affiliate $affiliate, string $reason, PlatformUser $by): Affiliate
    {
        $affiliate = $this->transition($affiliate, [Affiliate::PENDING], Affiliate::REJECTED, static function (Affiliate $locked) use ($reason): void {
            $locked->forceFill(['rejection_reason' => $reason]);
        });

        ActivityRecorder::landlord('affiliates', 'Affiliate application rejected', $affiliate, ['reason' => $reason], $by);
        $this->notifications->dispatch('affiliate.rejected', $affiliate, ['name' => $affiliate->name, 'reason' => $reason]);

        return $affiliate;
    }

    public function suspend(Affiliate $affiliate, string $reason, PlatformUser $by): Affiliate
    {
        $affiliate = $this->transition($affiliate, [Affiliate::APPROVED], Affiliate::SUSPENDED, static function (Affiliate $locked) use ($reason): void {
            $locked->forceFill(['status_reason' => $reason]);
        });

        ActivityRecorder::landlord('affiliates', 'Affiliate suspended', $affiliate, ['reason' => $reason], $by);
        $this->notifications->dispatch('affiliate.suspended', $affiliate, ['name' => $affiliate->name, 'reason' => $reason]);

        return $affiliate;
    }

    public function reinstate(Affiliate $affiliate, PlatformUser $by): Affiliate
    {
        $affiliate = $this->transition($affiliate, [Affiliate::SUSPENDED], Affiliate::APPROVED, static function (Affiliate $locked): void {
            $locked->forceFill(['status_reason' => null]);
        });

        ActivityRecorder::landlord('affiliates', 'Affiliate reinstated', $affiliate, [], $by);

        return $affiliate;
    }

    public function close(Affiliate $affiliate, string $reason, PlatformUser $by): Affiliate
    {
        $affiliate = $this->transition($affiliate, [Affiliate::APPROVED, Affiliate::SUSPENDED], Affiliate::CLOSED, static function (Affiliate $locked) use ($reason): void {
            $locked->forceFill(['status_reason' => $reason]);
        });

        $affiliate->tokens()->delete();
        ActivityRecorder::landlord('affiliates', 'Affiliate closed', $affiliate, ['reason' => $reason], $by);

        return $affiliate;
    }

    /**
     * Null returns the affiliate to the programme default. Existing
     * commissions keep their snapshotted rate.
     */
    public function setCommissionRate(Affiliate $affiliate, ?string $rate, string $reason, PlatformUser $by): Affiliate
    {
        if ($rate !== null && (! is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100)) {
            throw ValidationException::withMessages(['commission_rate' => ['The rate must be between 0 and 100.']]);
        }

        $before = $affiliate->commission_rate;
        $affiliate->forceFill(['commission_rate' => $rate])->save();

        ActivityRecorder::landlord('affiliates', 'Affiliate commission rate changed', $affiliate, [
            'from' => $before, 'to' => $rate, 'reason' => $reason,
        ], $by);

        return $affiliate->refresh();
    }

    /**
     * The old code stops resolving at once; tokens already issued keep
     * working because they carry the affiliate id.
     */
    public function changeReferralCode(Affiliate $affiliate, string $code, PlatformUser $by): Affiliate
    {
        if (! in_array($affiliate->status, [Affiliate::APPROVED, Affiliate::SUSPENDED], true)) {
            throw ApiException::unprocessable('affiliate_not_active', 'Only approved or suspended affiliates have a referral code.');
        }

        $old = $affiliate->referral_code;
        $affiliate->forceFill(['referral_code' => $this->assertCodeAvailable($code, $affiliate->id)])->save();
        $this->forgetCode($old);

        ActivityRecorder::landlord('affiliates', 'Affiliate referral code changed', $affiliate, ['from' => $old, 'to' => $affiliate->referral_code], $by);

        return $affiliate;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateProfile(Affiliate $affiliate, array $data): Affiliate
    {
        $validated = validator($data, [
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'website_url' => ['sometimes', 'nullable', 'url:https,http', 'max:255'],
        ])->validate();

        $affiliate->fill($validated)->save();

        return $affiliate;
    }

    /**
     * Write-only details, changed only with the current password; the
     * change is announced and blocks automatic payouts for 72 hours.
     *
     * @param  array<string, mixed>  $data
     */
    public function updatePayoutDetails(Affiliate $affiliate, array $data, string $currentPassword): Affiliate
    {
        if (! Hash::check($currentPassword, $affiliate->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is not correct.']]);
        }

        $method = (string) ($data['payout_method'] ?? '');
        $rules = match ($method) {
            'bank_transfer' => [
                'details.account_name' => ['required', 'string', 'max:160'],
                'details.account_number' => ['required_without:details.iban', 'nullable', 'string', 'max:64'],
                'details.bank_name' => ['required', 'string', 'max:160'],
                'details.bank_code' => ['sometimes', 'nullable', 'string', 'max:32'],
                'details.iban' => ['sometimes', 'nullable', 'string', 'max:64'],
                'details.swift' => ['sometimes', 'nullable', 'string', 'max:16'],
            ],
            'paypal' => ['details.paypal_email' => ['required', 'email:rfc', 'max:255']],
            'other' => ['details.instructions' => ['required', 'string', 'max:1000']],
            default => [],
        };

        $validated = validator($data, ['payout_method' => ['required', Rule::in(Affiliate::PAYOUT_METHODS)], 'details' => ['required', 'array'], ...$rules])->validate();

        $details = array_filter(
            array_intersect_key((array) $validated['details'], array_flip(array_map(static fn (string $k): string => substr($k, 8), array_keys($rules)))),
            static fn ($v): bool => $v !== null && $v !== '',
        );

        $affiliate->forceFill([
            'payout_method' => $method,
            'payout_details' => array_map('strval', $details),
            'payout_details_updated_at' => now(),
        ])->save();

        ActivityRecorder::landlord('affiliates', 'Affiliate payout details changed', $affiliate, ['payout_method' => $method], $affiliate);

        $this->notifications->dispatch('affiliate.payout_details_changed', $affiliate, [
            'name' => $affiliate->name,
            'changed_at' => now()->toDayDateTimeString(),
        ]);

        return $affiliate;
    }

    /**
     * The rate that applies to a new commission now.
     */
    public function effectiveRate(Affiliate $affiliate): string
    {
        return bcadd((string) ($affiliate->commission_rate ?? $this->settings->get('affiliate_default_commission_rate', '20')), '0', 4);
    }

    /**
     * The approved affiliate a code belongs to (case-insensitive), cached
     * for ten minutes and busted on every status or code change.
     */
    public function resolveCode(string $code): ?Affiliate
    {
        $code = strtoupper(trim($code));

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            return null;
        }

        $id = Cache::store('landlord')->remember('affiliate-code:'.$code, now()->addMinutes((int) config('affiliates.code_cache_minutes', 10)),
            static fn (): int => (int) Affiliate::query()->where('referral_code', $code)->where('status', Affiliate::APPROVED)->value('id'));

        if ($id === 0) {
            return null;
        }

        $affiliate = Affiliate::query()->find($id);

        return $affiliate?->isApproved() && $affiliate->referral_code === $code ? $affiliate : null;
    }

    /**
     * Payout details as shown anywhere: never the full account number.
     *
     * @return array{method: string|null, details: array<string, string>, updated_at: string|null}
     */
    public function maskedPayoutDetails(Affiliate $affiliate): array
    {
        $masked = [];

        foreach ((array) $affiliate->payout_details as $key => $value) {
            $masked[$key] = in_array($key, ['account_number', 'iban'], true) ? '…'.mb_substr((string) $value, -4) : (string) $value;
        }

        return [
            'method' => $affiliate->payout_method,
            'details' => $masked,
            'updated_at' => $affiliate->payout_details_updated_at?->toIso8601String(),
        ];
    }

    private function mayReapply(Affiliate $existing): bool
    {
        return $existing->status === Affiliate::REJECTED
            && $existing->updated_at->lte(now()->subDays((int) config('affiliates.reapply_after_days', 90)));
    }

    /**
     * Locks the affiliate, checks the allowed source states and applies the
     * change; a concurrent transition gets 422 invalid_transition.
     *
     * @param  list<string>  $from
     * @param  callable(Affiliate): void  $apply
     */
    private function transition(Affiliate $affiliate, array $from, string $to, callable $apply): Affiliate
    {
        $updated = DB::connection('landlord')->transaction(static function () use ($affiliate, $from, $to, $apply): Affiliate {
            /** @var Affiliate $locked */
            $locked = Affiliate::query()->whereKey($affiliate->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ApiException::invalidTransition($locked->status, $to);
            }

            $apply($locked);
            $locked->forceFill(['status' => $to])->save();

            return $locked;
        });

        $this->forgetCode($updated->referral_code);

        return $updated;
    }

    private function assertCodeAvailable(string $code, int $affiliateId): string
    {
        $code = strtoupper(trim($code));

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw ValidationException::withMessages(['referral_code' => ['Use 4 to 20 letters and digits.']]);
        }

        if (Affiliate::query()->where('referral_code', $code)->whereKeyNot($affiliateId)->exists()) {
            throw ValidationException::withMessages(['referral_code' => ['This referral code is taken.']]);
        }

        return $code;
    }

    private function generateCode(): string
    {
        $length = (int) config('affiliates.referral_code_length', 8);

        do {
            // No 0/O or 1/I, so codes survive being read aloud or retyped.
            $code = '';

            for ($i = 0; $i < $length; $i++) {
                $code .= '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 31)];
            }
        } while (Affiliate::query()->where('referral_code', $code)->exists());

        return $code;
    }

    private function forgetCode(?string $code): void
    {
        if ($code !== null) {
            Cache::store('landlord')->forget('affiliate-code:'.$code);
        }
    }
}
