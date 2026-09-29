<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\HrCandidate;
use App\Shared\Media\StorageQuota;
use App\Shared\Media\UploadRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Applicants (spec §58.7). One candidate per email across applications;
 * a newer résumé replaces the older one.
 */
final readonly class HrCandidateService
{
    public function __construct(private StorageQuota $quota) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCandidate(array $data, UploadedFile $resume): HrCandidate
    {
        $validated = $this->validate($data, true);

        return DB::connection('tenant')->transaction(function () use ($validated, $resume): HrCandidate {
            $candidate = new HrCandidate;
            $candidate->forceFill($validated)->save();
            $this->storeResume($candidate, $resume);

            return $candidate;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCandidate(HrCandidate $candidate, array $data, ?UploadedFile $resume = null): HrCandidate
    {
        $validated = $this->validate($data, false);
        unset($validated['email']);
        $candidate->forceFill($validated)->save();

        if ($resume !== null) {
            $this->storeResume($candidate, $resume);
        }

        return $candidate;
    }

    /**
     * The candidate with this email, or a new one. The public careers form
     * never changes an existing candidate: anyone can type an address, so
     * only staff edit candidates. A résumé is added only when none is kept.
     *
     * @param  array<string, mixed>  $data
     */
    public function findOrCreateByEmail(string $email, array $data, UploadedFile $resume): HrCandidate
    {
        $email = strtolower(trim($email));
        $existing = HrCandidate::query()->where('email', $email)->first();

        if ($existing === null) {
            try {
                return $this->createCandidate([...$data, 'email' => $email], $resume);
            } catch (UniqueConstraintViolationException) {
                // Applied twice at once.
                $existing = HrCandidate::query()->where('email', $email)->firstOrFail();
            }
        }

        if (! $existing->hasMedia('resume')) {
            $this->storeResume($existing, $resume);
        }

        return $existing;
    }

    private function storeResume(HrCandidate $candidate, UploadedFile $resume): void
    {
        Validator::make(['resume' => $resume], ['resume' => UploadRules::document()])->validate();
        $this->quota->assertAllows($resume);

        $candidate->addMedia($resume)
            ->usingFileName(Str::uuid().'.'.$resume->guessExtension())
            ->usingName(mb_substr($resume->getClientOriginalName(), 0, 200))
            ->toMediaCollection('resume');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        $validated = Validator::make($data, [
            'first_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'last_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'email' => [$creating ? 'required' : 'sometimes', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'linkedin_url' => ['sometimes', 'nullable', 'url:https', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'max:60'],
        ])->validate();

        if (isset($validated['email'])) {
            $validated['email'] = strtolower((string) $validated['email']);
        }

        return $validated;
    }
}
