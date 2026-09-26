<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Models\ContactSubmission;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Plans\Services\ModuleActivationService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Users\Models\User;
use Database\Seeders\Landlord\LandlordCmsSeeder;
use Database\Seeders\Landlord\PlatformAccessSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Notification::fake();
    Bus::fake();
    Storage::fake('public');
    Storage::fake('local');

    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');

    tenancy()->initialize($this->tenant);
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->auth = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];
});

/*
 * The tenancy filesystem bootstrapper re-roots disks on every tenant
 * request, so Storage::fake cannot isolate tenant files: remove exactly
 * what these tests write for the test tenant.
 */
afterEach(function (): void {
    File::delete(base_path('storage/tenants/test-tenant-a/app/sitemap.xml'));
    File::deleteDirectory(base_path('storage/tenants/test-tenant-a/app/public'));
});

it('seeds the storefront structure and serves the published homepage', function (): void {
    tenancy()->initialize($this->tenant);
    expect(CmsPage::query()->whereNotNull('system_key')->pluck('system_key')->sort()->values()->all())
        ->toBe(['cookie_policy', 'home', 'privacy_policy', 'refund_policy', 'shipping_policy', 'terms']);

    $this->tenantJson('GET', '/api/cms/home')->assertOk()
        ->assertJsonPath('data.is_homepage', true)
        ->assertJsonPath('data.sections.0.section_type', 'product_carousel')
        ->assertJsonMissingPath('data.status');

    // Policy pages are drafts until the tenant writes them.
    $this->tenantJson('GET', '/api/cms/pages/terms')->assertNotFound();
    $this->tenantJson('GET', '/api/cms/menus/header')->assertOk()->assertJsonPath('data.items', []);
});

it('manages pages and sections within the tenant scope', function (): void {
    $id = $this->tenantJson('POST', '/api/admin/cms/pages', ['title' => 'About us', 'body' => 'We sell shoes.'], $this->auth)
        ->assertCreated()->assertJsonPath('data.slug', 'about-us')->assertJsonPath('data.status', 'draft')->json('data.id');

    $this->tenantJson('PUT', "/api/admin/cms/pages/{$id}/sections", ['sections' => [
        ['section_type' => 'pricing_table', 'settings' => ['default_interval' => 'monthly']],
    ]], $this->auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'section_type_not_allowed');

    $this->tenantJson('PUT', "/api/admin/cms/pages/{$id}/sections", ['sections' => [
        ['section_type' => 'hero', 'settings' => ['cta_url' => 'javascript:alert(1)']],
    ]], $this->auth)->assertStatus(422)->assertJsonValidationErrors(['sections.0.settings.heading', 'sections.0.settings.cta_url']);

    $mediaId = $this->tenantJson('POST', "/api/admin/cms/pages/{$id}/media", ['image' => UploadedFile::fake()->image('hero.jpg', 800, 400)], $this->auth)
        ->assertCreated()->json('data.id');

    $this->tenantJson('PUT', "/api/admin/cms/pages/{$id}/sections", ['sections' => [
        ['section_type' => 'hero', 'settings' => ['heading' => 'Welcome', 'media_id' => $mediaId, 'extra' => 'dropped']],
        ['section_type' => 'rich_text', 'settings' => ['body' => 'Story'], 'is_active' => false],
    ]], $this->auth)->assertOk()->assertJsonCount(2, 'data.sections')->assertJsonMissingPath('data.sections.0.settings.extra');

    $this->tenantJson('POST', "/api/admin/cms/pages/{$id}/publish", [], $this->auth)->assertOk()->assertJsonPath('data.status', 'published');

    $this->tenantJson('GET', '/api/cms/pages/about-us')->assertOk()
        ->assertJsonCount(1, 'data.sections')
        ->assertJsonPath('data.sections.0.settings.heading', 'Welcome')
        ->assertJsonPath('data.sections.0.settings.media_url', fn ($url): bool => is_string($url) && $url !== '');

    tenancy()->initialize($this->tenant);
    $terms = CmsPage::query()->where('system_key', 'terms')->value('id');
    $this->tenantJson('DELETE', "/api/admin/cms/pages/{$terms}", [], $this->auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'system_page');
    $this->tenantJson('DELETE', "/api/admin/cms/pages/{$id}", [], $this->auth)->assertOk();
});

it('resolves menus, rejecting missing targets and omitting unpublished ones', function (): void {
    tenancy()->initialize($this->tenant);
    $home = CmsPage::query()->where('system_key', 'home')->value('id');
    $terms = CmsPage::query()->where('system_key', 'terms')->value('id');
    $menu = $this->tenantJson('GET', '/api/admin/cms/menus', [], $this->auth)->json('data.0.id');

    $this->tenantJson('PUT', "/api/admin/cms/menus/{$menu}/items", ['items' => [
        ['label' => 'Shoes', 'link_type' => 'category', 'linkable_id' => 999999],
    ]], $this->auth)->assertStatus(422)->assertJsonValidationErrors('items.0.linkable_id');

    $shoes = $this->tenantJson('POST', '/api/admin/categories', ['name' => 'Shoes'], $this->auth)->assertCreated()->json('data.id');

    $this->tenantJson('PUT', "/api/admin/cms/menus/{$menu}/items", ['items' => [
        ['label' => 'Home', 'link_type' => 'page', 'linkable_id' => $home, 'children' => [
            ['label' => 'Terms', 'link_type' => 'page', 'linkable_id' => $terms],
            ['label' => 'Blog', 'link_type' => 'blog'],
        ]],
        ['label' => 'Help', 'link_type' => 'url', 'url' => 'mailto:help@a.test'],
        ['label' => 'Shoes', 'link_type' => 'category', 'linkable_id' => $shoes],
    ]], $this->auth)->assertOk();

    $key = $this->tenantJson('GET', '/api/admin/cms/menus', [], $this->auth)->json('data.0.key');
    $this->tenantJson('GET', "/api/cms/menus/{$key}")->assertOk()
        ->assertJsonPath('data.items.0.url', '/')
        ->assertJsonPath('data.items.0.children', [['label' => 'Blog', 'url' => '/blog', 'link_type' => 'blog', 'open_in_new_tab' => false, 'children' => []]])
        ->assertJsonPath('data.items.1.url', 'mailto:help@a.test')
        ->assertJsonPath('data.items.2.url', '/categories/shoes');

    // A deactivated category drops out of the public menu.
    $this->tenantJson('PATCH', "/api/admin/categories/{$shoes}", ['is_active' => false], $this->auth)->assertOk();
    $this->tenantJson('GET', "/api/cms/menus/{$key}")->assertOk()->assertJsonCount(2, 'data.items');
});

it('shows live announcements in the storefront config and gates content marketing', function (): void {
    $this->tenantJson('POST', '/api/admin/cms/banners', ['kind' => 'announcement', 'title' => 'Sale', 'body' => 'Free shipping this week', 'background_color' => '#000000'], $this->auth)->assertCreated();
    $this->tenantJson('POST', '/api/admin/cms/banners', ['kind' => 'announcement', 'title' => 'Later', 'body' => 'Soon', 'starts_at' => now()->addDay()->toIso8601String()], $this->auth)->assertCreated();
    $image = $this->tenantJson('POST', '/api/admin/cms/banners', ['kind' => 'image', 'title' => 'Hero', 'position' => 'homepage-hero'], $this->auth)->assertCreated()->json('data.id');

    $this->tenantJson('GET', '/api/storefront/config')->assertOk()->assertJsonCount(1, 'data.announcement_bar')->assertJsonPath('data.announcement_bar.0.body', 'Free shipping this week');

    // An image banner is live only once it has its image.
    $this->tenantJson('GET', '/api/cms/banners?position=homepage-hero')->assertOk()->assertJsonCount(0, 'data');
    $this->tenantJson('POST', "/api/admin/cms/banners/{$image}/image", ['image' => UploadedFile::fake()->image('b.png')], $this->auth)->assertCreated();
    $this->tenantJson('GET', '/api/cms/banners?position=homepage-hero')->assertOk()->assertJsonCount(1, 'data');

    $post = $this->tenantJson('POST', '/api/admin/cms/blog-posts', ['title' => 'Hello', 'body' => 'First post', 'tags' => ['News', 'news', 'Launch']], $this->auth)
        ->assertCreated()->assertJsonCount(2, 'data.tags')->assertJsonPath('data.author_name', 'Owner')->json('data.id');
    $this->tenantJson('POST', "/api/admin/cms/blog-posts/{$post}/publish", [], $this->auth)->assertOk();
    $this->tenantJson('GET', '/api/cms/blog-posts')->assertOk()->assertJsonCount(1, 'data');

    tenancy()->initialize($this->tenant);
    app(ModuleActivationService::class)->syncAutoModules($this->tenant);
    app(ModuleActivationService::class)->disable($this->tenant, 'content_marketing', $this->owner);

    $this->tenantJson('GET', '/api/cms/blog-posts')->assertForbidden();
    // Admin reads stay open while the module is inactive; writes do not.
    $this->tenantJson('GET', '/api/admin/cms/blog-posts', [], $this->auth)->assertOk()->assertJsonCount(1, 'data');
    $this->tenantJson('POST', '/api/admin/cms/blog-posts', ['title' => 'x', 'body' => 'y'], $this->auth)->assertForbidden();
});

it('accepts contact messages, drops honeypot spam and alerts the store admins', function (): void {
    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);

    $this->tenantJson('POST', '/api/contact', ['name' => 'Bot', 'email' => 'bot@spam.test', 'message' => 'Buy', 'website' => 'http://spam'])->assertStatus(202);
    $this->tenantJson('POST', '/api/contact', ['name' => 'Ada', 'email' => 'Ada@Example.test', 'message' => 'Do you ship to Kano?'])->assertStatus(202);

    tenancy()->initialize($this->tenant);
    expect(ContactSubmission::query()->pluck('email')->all())->toBe(['ada@example.test']);
    Notification::assertSentTo($this->owner, TemplatedNotification::class, fn ($n): bool => $n->key === 'contact.submission_received');

    $id = ContactSubmission::query()->value('id');
    $this->tenantJson('GET', "/api/admin/cms/contact-submissions/{$id}", [], $this->auth)->assertOk()->assertJsonPath('data.status', 'read');
    $this->tenantJson('GET', '/api/admin/cms/contact-submissions?status=read', [], $this->auth)->assertOk()->assertJsonCount(1, 'data');
});

it('runs the same CMS on the landlord website, isolated from tenants', function (): void {
    tenancy()->end();
    config(['app.frontend.website_url' => 'http://localhost:3002']);
    // Ending tenancy restores the real disk roots; fake the landlord disk again.
    Storage::fake('local');
    Storage::fake('public');
    $this->seed(PlatformAccessSeeder::class);
    $this->seed(LandlordCmsSeeder::class);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Landlord);
    app(PlatformSettingsService::class)->set('support_email', 'support@platform.test');

    $editor = PlatformUser::query()->create(['name' => 'Eve', 'email' => 'eve@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $editor->assignRole('content-editor');
    $auth = ['Authorization' => 'Bearer '.$editor->createToken('t', ['platform'])->plainTextToken];

    $pricing = CmsPage::on('landlord')->where('system_key', 'pricing')->value('id');
    $this->landlordJson('POST', "/api/admin/cms/pages/{$pricing}/publish", [], $auth)->assertOk();
    $this->landlordJson('GET', '/api/cms/pages/pricing')->assertOk()->assertJsonPath('data.sections.0.section_type', 'pricing_table');

    // Tenant-only sections are refused on the landlord website.
    $this->landlordJson('PUT', "/api/admin/cms/pages/{$pricing}/sections", ['sections' => [['section_type' => 'brand_strip', 'settings' => ['brand_ids' => [1]]]]], $auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'section_type_not_allowed');

    // The landlord blog needs no feature key.
    $post = $this->landlordJson('POST', '/api/admin/cms/blog-posts', ['title' => 'Launch day', 'body' => 'We are live'], $auth)->assertCreated()->json('data.id');
    $this->landlordJson('POST', "/api/admin/cms/blog-posts/{$post}/publish", [], $auth)->assertOk()->assertJsonPath('data.author_name', 'Eve');

    // A tenant never sees landlord content, and the reverse.
    $this->tenantJson('GET', '/api/cms/pages/pricing')->assertNotFound();
    $this->landlordJson('GET', '/api/cms/home')->assertNotFound();

    $this->landlordJson('POST', '/api/contact', ['name' => 'Lead', 'email' => 'lead@corp.test', 'message' => 'Pricing for 50 stores?'])->assertStatus(202);
    Notification::assertSentTo(new AnonymousNotifiable, TemplatedNotification::class, fn ($n, $channels, $notifiable): bool => $n->key === 'platform.contact_submission_received'
        && $notifiable->routes['mail'] === 'support@platform.test');

    Storage::fake('local'); // the tenant request above restored the real roots
    $xml = $this->get('http://'.$this->landlordHost().'/sitemap.xml')->assertOk()->getContent();
    expect($xml)->toContain('<loc>http://localhost:3002/pages/pricing</loc>')
        ->toContain('<loc>http://localhost:3002/blog/launch-day</loc>')
        ->not->toContain('/pages/terms');
});

it('serves the storefront sitemap without drafts or noindex pages', function (): void {
    $id = $this->tenantJson('POST', '/api/admin/cms/pages', ['title' => 'Hidden', 'robots' => 'noindex_follow'], $this->auth)->json('data.id');
    $this->tenantJson('POST', "/api/admin/cms/pages/{$id}/publish", [], $this->auth)->assertOk();

    $xml = $this->get('http://'.$this->tenantHost().'/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();

    expect($xml)->toContain('<loc>https://'.$this->tenantHost().'/</loc>')
        ->not->toContain('/pages/hidden')
        ->not->toContain('/pages/terms');
});
