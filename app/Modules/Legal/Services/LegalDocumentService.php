<?php

declare(strict_types=1);

namespace App\Modules\Legal\Services;

use App\Modules\Legal\Models\LegalAcceptance;
use App\Modules\Legal\Models\LegalDocument;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Platform legal documents and their acceptance (spec §9.2, §9.7).
 */
final readonly class LegalDocumentService
{
    public function __construct(private NotificationDispatchService $notifications) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDraft(array $data): LegalDocument
    {
        $validated = validator($data, [
            'document_type' => ['required', Rule::in(LegalDocument::TYPES)],
            'version' => ['required', 'string', 'max:32', Rule::unique('landlord.legal_documents')->where('document_type', $data['document_type'] ?? null)],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:500000'],
            'effective_at' => ['sometimes', 'nullable', 'date'],
            'required_at_registration' => ['sometimes', 'boolean'],
            'requires_reacceptance' => ['sometimes', 'boolean'],
        ])->validate();

        /** @var LegalDocument $document */
        $document = LegalDocument::query()->create($validated + ['status' => LegalDocument::DRAFT]);

        return $document;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(LegalDocument $document, array $data): LegalDocument
    {
        if ($document->status !== LegalDocument::DRAFT) {
            throw ApiException::unprocessable('legal_document_immutable', 'A published document cannot change. Create a new version instead.');
        }

        $validated = validator($data, [
            'version' => ['sometimes', 'string', 'max:32', Rule::unique('landlord.legal_documents')->where('document_type', $document->document_type)->ignore($document->id)],
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string', 'max:500000'],
            'effective_at' => ['sometimes', 'nullable', 'date'],
            'required_at_registration' => ['sometimes', 'boolean'],
            'requires_reacceptance' => ['sometimes', 'boolean'],
        ])->validate();

        $document->fill($validated)->save();

        return $document;
    }

    /**
     * Publishes a draft and retires the previous current version of its
     * type. A version requiring re-acceptance notifies every tenant owner.
     */
    public function publish(LegalDocument $document): LegalDocument
    {
        DB::connection('landlord')->transaction(function () use ($document): void {
            /** @var LegalDocument $locked */
            $locked = LegalDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== LegalDocument::DRAFT) {
                throw ApiException::invalidTransition($locked->status, LegalDocument::PUBLISHED);
            }

            LegalDocument::query()
                ->where('document_type', $locked->document_type)
                ->where('status', LegalDocument::PUBLISHED)
                ->update(['status' => LegalDocument::RETIRED]);

            $locked->forceFill([
                'status' => LegalDocument::PUBLISHED,
                'published_at' => now(),
                'effective_at' => $locked->effective_at ?? now(),
            ])->save();
        });

        $document->refresh();

        if ($document->requires_reacceptance && $document->document_type !== 'affiliate_agreement') {
            Tenant::query()->where('status', TenantStatus::Active->value)->chunkById(200, function ($tenants) use ($document): void {
                foreach ($tenants as $tenant) {
                    $this->notifications->dispatch('tenant.legal_reacceptance_required', $tenant, [
                        'owner_name' => $tenant->owner_name,
                        'document_title' => $document->title,
                        'document_version' => $document->version,
                    ]);
                }
            });
        }

        return $document;
    }

    /**
     * The latest published version whose effective date has passed.
     */
    public function current(string $type): ?LegalDocument
    {
        return LegalDocument::query()
            ->where('document_type', $type)
            ->where('status', LegalDocument::PUBLISHED)
            ->where('effective_at', '<=', now())
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return Collection<int, LegalDocument>
     */
    public function allCurrent(): Collection
    {
        return collect(LegalDocument::TYPES)
            ->map(fn (string $type): ?LegalDocument => $this->current($type))
            ->filter()
            ->values();
    }

    /**
     * The types marked required at registration, each with its current
     * version (null when none is published yet).
     *
     * @return Collection<string, LegalDocument|null>
     */
    public function currentRequiredForRegistration(): Collection
    {
        $types = LegalDocument::query()
            ->where('required_at_registration', true)
            ->where('document_type', '!=', 'affiliate_agreement')
            ->distinct()
            ->pluck('document_type');

        return $types->mapWithKeys(fn (string $type): array => [$type => $this->current($type)]);
    }

    /**
     * Whether registration can open: every required type has a current
     * version (§9.2).
     */
    public function registrationDocumentsReady(): bool
    {
        $required = $this->currentRequiredForRegistration();

        return $required->isNotEmpty() && $required->every(static fn (?LegalDocument $d): bool => $d !== null);
    }

    /**
     * The accepted ids must be exactly the current required versions.
     *
     * @param  list<int>  $acceptedIds
     */
    public function assertAcceptedCurrentRequired(array $acceptedIds): void
    {
        $required = $this->currentRequiredForRegistration()->filter()->values();
        $expected = $required->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
        $given = collect($acceptedIds)->map(static fn ($id): int => (int) $id)->unique()->sort()->values()->all();

        if ($expected !== $given) {
            throw ApiException::unprocessable('legal_version_outdated', 'Please accept the current legal documents.', [
                'current' => $required->map(static fn (LegalDocument $d): array => [
                    'id' => $d->id, 'document_type' => $d->document_type, 'version' => $d->version, 'title' => $d->title,
                ])->all(),
            ]);
        }
    }

    /**
     * Writes one immutable acceptance row per document.
     *
     * @param  list<int>  $documentIds
     * @param  array{tenant_registration_id?: int|null, tenant_id?: string|null, affiliate_id?: int|null, accepted_by_user_id?: int|null, name: string, email: string}  $subject
     */
    public function recordAcceptances(array $documentIds, array $subject, string $context, Request $request): void
    {
        foreach (array_unique($documentIds) as $documentId) {
            LegalAcceptance::query()->create([
                'legal_document_id' => (int) $documentId,
                'tenant_registration_id' => $subject['tenant_registration_id'] ?? null,
                'tenant_id' => $subject['tenant_id'] ?? null,
                'affiliate_id' => $subject['affiliate_id'] ?? null,
                'accepted_by_user_id' => $subject['accepted_by_user_id'] ?? null,
                'accepted_by_name' => $subject['name'],
                'accepted_by_email' => strtolower($subject['email']),
                'context' => $context,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
                'accepted_at' => now(),
            ]);
        }
    }

    /**
     * Current versions requiring re-acceptance that the tenant has not
     * accepted.
     *
     * @return Collection<int, LegalDocument>
     */
    public function pendingForTenant(Tenant $tenant): Collection
    {
        return $this->allCurrent()
            ->filter(static fn (LegalDocument $d): bool => $d->requires_reacceptance && $d->document_type !== 'affiliate_agreement')
            ->reject(static fn (LegalDocument $d): bool => LegalAcceptance::query()
                ->where('legal_document_id', $d->id)
                ->where('tenant_id', $tenant->getTenantKey())
                ->exists())
            ->values();
    }
}
