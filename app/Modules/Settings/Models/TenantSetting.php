<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Shared\Media\MediaDisks;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One tenant business setting (spec §13.4). Never exposed to the public.
 * Image settings (store logo, favicon) keep their file on the setting's
 * own row, in the single-file "image" collection (BG-12).
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property string $type
 */
class TenantSetting extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = ['key', 'value', 'type'];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->singleFile()->useDisk(MediaDisks::PUBLIC);
    }
}
