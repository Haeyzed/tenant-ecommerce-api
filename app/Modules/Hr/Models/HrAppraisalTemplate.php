<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable appraisal form (spec §58.6). Its criteria weights must sum
 * to 100 before it can be used.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property-read Collection<int, HrAppraisalTemplateCriterion> $criteria
 */
class HrAppraisalTemplate extends Model
{
    protected $connection = 'tenant';

    protected $table = 'hr_appraisal_templates';

    protected $fillable = [];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * @return HasMany<HrAppraisalTemplateCriterion, $this>
     */
    public function criteria(): HasMany
    {
        return $this->hasMany(HrAppraisalTemplateCriterion::class, 'appraisal_template_id')->orderBy('sort_order')->orderBy('id');
    }

    public function totalWeight(): string
    {
        return $this->criteria->reduce(static fn (string $sum, HrAppraisalTemplateCriterion $c): string => bcadd($sum, (string) $c->weight, 4), '0');
    }
}
