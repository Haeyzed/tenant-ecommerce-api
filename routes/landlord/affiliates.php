<?php

declare(strict_types=1);

use App\Modules\Affiliates\Http\Controllers\Landlord\Admin\AffiliateCommissionController;
use App\Modules\Affiliates\Http\Controllers\Landlord\Admin\AffiliateController;
use App\Modules\Affiliates\Http\Controllers\Landlord\Admin\AffiliatePayoutController;
use App\Modules\Affiliates\Http\Controllers\Landlord\Admin\AffiliateReferralController;
use App\Modules\Affiliates\Http\Controllers\Landlord\AffiliateClickController;
use App\Modules\Affiliates\Http\Controllers\Landlord\AffiliateProgramController;
use App\Modules\Affiliates\Http\Controllers\Landlord\Auth\AffiliateAuthController;
use App\Modules\Affiliates\Http\Controllers\Landlord\Portal\CommissionController;
use App\Modules\Affiliates\Http\Controllers\Landlord\Portal\DashboardController;
use App\Modules\Affiliates\Http\Controllers\Landlord\Portal\LegalController;
use App\Modules\Affiliates\Http\Controllers\Landlord\Portal\NotificationController;
use App\Modules\Affiliates\Http\Controllers\Landlord\Portal\PayoutController;
use App\Modules\Affiliates\Http\Controllers\Landlord\Portal\ProfileController;
use App\Modules\Affiliates\Http\Controllers\Landlord\Portal\ReferralController;
use Illuminate\Support\Facades\Route;

/*
| Affiliate marketing (spec §21A.10, §10.5): public programme and click
| routes, affiliate authentication, the affiliate portal (own records
| only) and platform administration.
*/

Route::middleware('landlord.public')->name('landlord.affiliates.')->group(function (): void {
    Route::get('affiliate-program', [AffiliateProgramController::class, 'show'])->name('program');
    Route::post('affiliate-clicks', [AffiliateClickController::class, 'store'])->middleware('throttle:affiliate-clicks')->name('clicks.store');
});

Route::middleware('landlord.public')->prefix('affiliate/auth')->name('landlord.affiliate.auth.')->group(function (): void {
    Route::middleware('throttle:auth-sensitive')->group(function (): void {
        Route::post('apply', [AffiliateAuthController::class, 'apply'])->name('apply');
        Route::post('login', [AffiliateAuthController::class, 'login'])->name('login');
        Route::post('email/verify', [AffiliateAuthController::class, 'verifyEmail'])->name('email.verify');
        Route::post('password/forgot', [AffiliateAuthController::class, 'forgotPassword'])->name('password.forgot');
        Route::post('password/reset', [AffiliateAuthController::class, 'resetPassword'])->name('password.reset');
        Route::post('email/resend', [AffiliateAuthController::class, 'resendVerificationEmail'])->middleware('auth.as:affiliate')->name('email.resend');
        Route::patch('password', [AffiliateAuthController::class, 'changePassword'])->middleware('auth.as:affiliate')->name('password.change');
    });

    Route::post('logout', [AffiliateAuthController::class, 'logout'])->middleware(['auth.as:affiliate', 'throttle:api'])->name('logout');
});

Route::middleware('landlord.affiliate')->prefix('affiliate')->name('landlord.affiliate.')->group(function (): void {
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('payout-details', [ProfileController::class, 'payoutDetails'])->name('payout-details');
    Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');
    Route::get('referrals', [ReferralController::class, 'index'])->name('referrals.index');
    Route::get('commissions', [CommissionController::class, 'index'])->name('commissions.index');
    Route::get('payouts', [PayoutController::class, 'index'])->name('payouts.index');
    Route::get('payouts/{payout}', [PayoutController::class, 'show'])->where('payout', 'AFP-[A-Z0-9-]+')->name('payouts.show');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->whereUuid('id')->name('notifications.read');
    Route::get('legal-documents/pending', [LegalController::class, 'pending'])->name('legal.pending');
    Route::post('legal-acceptances', [LegalController::class, 'accept'])->name('legal.accept');
});

// Static "metrics" segments precede the {model} routes (§70.6).
Route::middleware('landlord.admin')->prefix('admin')->name('landlord.affiliates.admin.')->group(function (): void {
    Route::get('affiliates/metrics', [AffiliateController::class, 'metrics'])->name('metrics');
    Route::get('affiliates', [AffiliateController::class, 'index'])->name('index');
    Route::get('affiliates/{affiliate}', [AffiliateController::class, 'show'])->name('show');
    Route::post('affiliates/{affiliate}/approve', [AffiliateController::class, 'approve'])->name('approve');
    Route::post('affiliates/{affiliate}/reject', [AffiliateController::class, 'reject'])->name('reject');
    Route::post('affiliates/{affiliate}/suspend', [AffiliateController::class, 'suspend'])->name('suspend');
    Route::post('affiliates/{affiliate}/reinstate', [AffiliateController::class, 'reinstate'])->name('reinstate');
    Route::post('affiliates/{affiliate}/close', [AffiliateController::class, 'close'])->name('close');
    Route::patch('affiliates/{affiliate}/commission-rate', [AffiliateController::class, 'commissionRate'])->name('commission-rate');
    Route::patch('affiliates/{affiliate}/referral-code', [AffiliateController::class, 'referralCode'])->name('referral-code');

    Route::get('affiliate-referrals', [AffiliateReferralController::class, 'index'])->name('referrals.index');
    Route::post('affiliate-referrals/{referral}/reject', [AffiliateReferralController::class, 'reject'])->name('referrals.reject');
    Route::post('affiliate-referrals/{referral}/clear-flags', [AffiliateReferralController::class, 'clearFlags'])->name('referrals.clear-flags');

    Route::get('affiliate-commissions/metrics', [AffiliateCommissionController::class, 'metrics'])->name('commissions.metrics');
    Route::get('affiliate-commissions', [AffiliateCommissionController::class, 'index'])->name('commissions.index');
    Route::get('affiliate-commissions/{commission}', [AffiliateCommissionController::class, 'show'])->name('commissions.show');
    Route::post('affiliate-commissions/{commission}/approve', [AffiliateCommissionController::class, 'approve'])->name('commissions.approve');
    Route::post('affiliate-commissions/{commission}/reject', [AffiliateCommissionController::class, 'reject'])->name('commissions.reject');
    Route::post('affiliate-commissions/{commission}/reverse', [AffiliateCommissionController::class, 'reverse'])->name('commissions.reverse');

    Route::get('affiliate-payouts/metrics', [AffiliatePayoutController::class, 'metrics'])->name('payouts.metrics');
    Route::get('affiliate-payouts', [AffiliatePayoutController::class, 'index'])->name('payouts.index');
    Route::post('affiliate-payouts/generate', [AffiliatePayoutController::class, 'generate'])->name('payouts.generate');
    Route::get('affiliate-payouts/{payout}', [AffiliatePayoutController::class, 'show'])->whereNumber('payout')->name('payouts.show');
    Route::post('affiliate-payouts/{payout}/mark-paid', [AffiliatePayoutController::class, 'markPaid'])->whereNumber('payout')->middleware('idempotency')->name('payouts.mark-paid');
    Route::post('affiliate-payouts/{payout}/mark-failed', [AffiliatePayoutController::class, 'markFailed'])->whereNumber('payout')->name('payouts.mark-failed');
    Route::post('affiliate-payouts/{payout}/cancel', [AffiliatePayoutController::class, 'cancel'])->whereNumber('payout')->name('payouts.cancel');
});
