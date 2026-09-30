<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Shared\Media\MediaDisks;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One storefront presentation setting (spec §13.5). Every key is public.
 * The share image keeps its file on the setting's own row (BG-12).
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property string $type
 */
class StorefrontSetting extends Model implements AuditableContract, HasMedia
{
    use Auditable;
    use InteractsWithMedia;

    protected $fillable = ['key', 'value', 'type'];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->singleFile()->useDisk(MediaDisks::PUBLIC);
    }
}
