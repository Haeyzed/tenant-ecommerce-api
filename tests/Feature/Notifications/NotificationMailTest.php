<?php

declare(strict_types=1);

use App\Modules\Messaging\Mail\TemplatedMail;
use App\Modules\Messaging\Support\MailBranding;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Notifications\Support\NotificationPresentation;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Notification;

it('resolves presentation from allow-listed variables only', function (): void {
    $p = NotificationPresentation::resolve('tenant.registration_verification', [
        'code' => '482913', 'verification_url' => 'https://example.test/verify?t=abc', 'owner_name' => 'Ada',
    ]);

    expect($p)->toBe([
        'tone' => 'default',
        'eyebrow' => 'Verification',
        'highlight' => '482913',
        'highlight_label' => 'Verification code',
        'action_url' => 'https://example.test/verify?t=abc',
        'action_text' => 'Verify email',
        'preheader' => 'Your verification code is 482913',
    ]);

    // A non-http(s) link never becomes a button, and the button text goes with it.
    $bad = NotificationPresentation::resolve('customer.password_reset', ['reset_url' => 'javascript:alert(1)']);
    expect($bad)->not->toHaveKey('action_url')->not->toHaveKey('action_text');

    expect(NotificationPresentation::resolve('module.state_changed', ['module_name' => 'POS']))->toBe([]);
});

it('renders the layout with escaping, links, button and a text part', function (): void {
    $mail = new TemplatedMail(
        'Payment received',
        "Hello <b>Ada</b>,\n\nSee https://example.test/a?x=1&y=2.\n\nThanks",
        ['tone' => 'success', 'highlight' => '₦5,000.00', 'highlight_label' => 'Paid', 'action_url' => 'https://example.test/billing', 'action_text' => 'View billing'],
        ['name' => 'Acme <Store>', 'logo_url' => null, 'footer' => 'help@acme.test', 'color' => 'red;background:url(x)', 'dir' => 'ltr'],
    );

    $html = $mail->render();

    expect($html)
        ->toContain('&lt;b&gt;Ada&lt;/b&gt;')
        ->not->toContain('<b>Ada</b>')
        ->toContain('<a href="https://example.test/a?x=1&amp;y=2"')
        ->toContain('₦5,000.00')
        ->toContain('#047857')                       // success band
        ->toContain('href="https://example.test/billing"')
        ->toContain('Acme &lt;Store&gt;')
        ->toContain('help@acme.test')
        ->not->toContain('url(x)')                   // invalid colour replaced
        ->toContain(MailBranding::DEFAULT_COLOR)
        ->not->toContain('opacity');

    $text = view('mail.notification-text', (new ReflectionMethod($mail, 'content'))->invoke($mail)->with)->render();
    expect($text)->toContain('Paid: ₦5,000.00')->toContain('View billing: https://example.test/billing')->toContain('Hello <b>Ada</b>');
});

it('still renders a plain mail without presentation or branding', function (): void {
    $html = (new TemplatedMail('Test email', 'Works.'))->render();

    expect($html)->toContain('Test email')->toContain('Works.')->toContain(config('app.name'));
});

it('brands tenant mail with the store and landlord mail with the platform', function (): void {
    app(PlatformSettingsService::class)->set('platform_name', 'ShopCloud');
    app(PlatformSettingsService::class)->set('support_email', 'support@shopcloud.test');

    tenancy()->initialize($this->createTenant('a'));
    $settings = app(TenantSettingsService::class);
    $settings->set('store_name', 'Ada Fabrics');
    $settings->set('store_contact_email', 'hello@ada.test');
    $settings->set('rtl_enabled', true);

    $branding = app(MailBranding::class);

    expect($branding->for(platform: false))->toMatchArray(['name' => 'Ada Fabrics', 'footer' => 'hello@ada.test', 'dir' => 'rtl'])
        ->and($branding->for(platform: true))->toMatchArray(['name' => 'ShopCloud', 'footer' => 'support@shopcloud.test', 'dir' => 'ltr']);

    $html = (new TemplatedMail('Hi', 'Body', [], $branding->for(platform: false)))->render();
    expect($html)->toContain('dir="rtl"')->toContain('Ada Fabrics');
});

it('attaches presentation to dispatched notifications', function (): void {
    Notification::fake();
    tenancy()->initialize($this->createTenant('a'));
    $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'secret123', 'is_active' => true]);
    $user->assignRole('owner');

    app(NotificationDispatchService::class)->dispatch('staff.password_reset', $user, [
        'name' => 'Owner', 'reset_url' => 'https://a.test/reset?token=t', 'expires_in_minutes' => 60,
    ]);

    Notification::assertSentTo($user, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->presentation['action_url'] === 'https://a.test/reset?token=t'
        && $n->presentation['action_text'] === 'Reset password'
        && ! array_key_exists('presentation', $n->toArray($user)));
});
