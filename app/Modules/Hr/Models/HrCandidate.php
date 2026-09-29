<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Shared\Media\MediaDisks;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * An applicant (spec §58.7): never logs in. The latest résumé is kept on
 * the private disk.
 *
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $linkedin_url
 * @property string|null $source
 */
class HrCandidate extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $connection = 'tenant';

    protected $table = 'hr_candidates';

    protected $fillable = [];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('resume')->useDisk(MediaDisks::PRIVATE)->singleFile();
    }
}
