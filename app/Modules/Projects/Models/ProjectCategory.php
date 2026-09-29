<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $status
 */
class ProjectCategory extends Model
{
    public const array STATUSES = ['active', 'inactive'];

    protected $connection = 'tenant';

    protected $fillable = [];

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
