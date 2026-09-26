<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A sticker-sheet layout for label stock (spec §43.4). Dimensions are in
 * inches.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $label_layout continuous | dymo
 * @property bool $is_default
 * @property string $top_margin_inches
 * @property string $left_margin_inches
 * @property string $sticker_width_inches
 * @property string $sticker_height_inches
 * @property string $paper_width_inches
 * @property string $paper_height_inches
 * @property int $stickers_per_row
 * @property string $row_distance_inches
 * @property string $column_distance_inches
 * @property int $stickers_per_sheet
 */
class BarcodeSetting extends Model
{
    public const array DIMENSIONS = ['top_margin_inches', 'left_margin_inches', 'sticker_width_inches', 'sticker_height_inches', 'paper_width_inches',
        'paper_height_inches', 'row_distance_inches', 'column_distance_inches'];

    protected $connection = 'tenant';

    protected $fillable = ['name', 'description', 'label_layout', 'stickers_per_row', 'stickers_per_sheet', ...self::DIMENSIONS];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'stickers_per_row' => 'integer',
            'stickers_per_sheet' => 'integer',
            ...array_fill_keys(self::DIMENSIONS, 'decimal:3'),
        ];
    }
}
