<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use Database\Seeders\Landlord\PlatformAccessSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->seed(PlatformAccessSeeder::class);
    $editor = PlatformUser::query()->create(['name' => 'Ed', 'email' => 'ed@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $editor->assignRole('content-editor');
    $this->auth = ['Authorization' => 'Bearer '.$editor->createToken('t', ['platform'])->plainTextToken];
    // Content editors draft; publishing is a separate permission (super-admin here).
    $root = PlatformUser::query()->create(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $root->assignRole('super-admin');
    $this->root = ['Authorization' => 'Bearer '.$root->createToken('t', ['platform'])->plainTextToken];
});

it('drafts, shows, edits and publishes a version, retiring the previous one', function (): void {
    $draft = fn (string $version) => $this->landlordJson('POST', '/api/admin/legal-documents', [
        'document_type' => 'privacy_policy', 'version' => $version, 'title' => 'Privacy', 'body' => 'Text '.$version,
    ], $this->auth)->assertCreated()->json('data.id');

    $first = $draft('2026-01');
    $this->landlordJson('GET', "/api/admin/legal-documents/{$first}", [], $this->auth)
        ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', '2026-01');

    $this->landlordJson('PATCH', "/api/admin/legal-documents/{$first}", ['title' => 'Privacy policy'], $this->auth)
        ->assertOk()->assertJsonPath('data.title', 'Privacy policy');
    $this->landlordJson('POST', "/api/admin/legal-documents/{$first}/publish", [], $this->root)->assertOk()->assertJsonPath('data.status', 'published');

    // Published versions are immutable; a new version retires the old one.
    $this->landlordJson('PATCH', "/api/admin/legal-documents/{$first}", ['title' => 'X'], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'legal_document_immutable');

    $second = $draft('2026-02');
    $this->landlordJson('POST', "/api/admin/legal-documents/{$second}/publish", [], $this->auth)->assertForbidden();
    $this->landlordJson('POST', "/api/admin/legal-documents/{$second}/publish", [], $this->root)->assertOk();
    $this->landlordJson('GET', "/api/admin/legal-documents/{$first}", [], $this->auth)->assertOk()->assertJsonPath('data.status', 'retired');

    $this->landlordJson('GET', "/api/admin/legal-documents/{$second}/acceptances?page=1&per_page=10", [], $this->auth)->assertOk();
});

it('keeps the document view behind the legal permission', function (): void {
    $support = PlatformUser::query()->create(['name' => 'Sam', 'email' => 'sam@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $support->assignRole('support-staff');
    $id = $this->landlordJson('POST', '/api/admin/legal-documents', [
        'document_type' => 'terms_of_service', 'version' => 'v9', 'title' => 'Terms', 'body' => 'Body',
    ], $this->auth)->json('data.id');

    $this->landlordJson('GET', "/api/admin/legal-documents/{$id}", [], ['Authorization' => 'Bearer '.$support->createToken('t', ['platform'])->plainTextToken])
        ->assertForbidden();
});
