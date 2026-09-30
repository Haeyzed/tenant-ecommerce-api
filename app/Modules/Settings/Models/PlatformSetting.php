<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Shared\Auditing\AuditsToLandlord;
use App\Shared\Media\MediaDisks;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One platform setting row (spec §13.2). Keys are declared in
 * config/platform_settings.php.
 *
 * @property int $id
 * @property string $group
 * @property string $key
 * @property string|null $value
 * @property string $type
 */
class PlatformSetting extends Model implements Auditable, HasMedia
{
    use AuditsToLandlord;
    use InteractsWithMedia;

    protected $connection = 'landlord';

    protected $fillable = ['group', 'key', 'value', 'type'];

    /**
     * Image settings (platform logo, favicon, share image) keep their file
     * on the setting's own row (BG-12).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->singleFile()->useDisk(MediaDisks::PUBLIC);
    }
}
