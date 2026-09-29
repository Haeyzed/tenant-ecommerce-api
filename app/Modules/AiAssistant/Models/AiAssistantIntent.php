<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A question the assistant can answer (spec §62.1). handler is a key in
 * config/ai_assistant.php, never a class or method name.
 *
 * @property int $id
 * @property string $intent_key
 * @property list<string> $sample_phrases
 * @property string $handler
 * @property string|null $required_feature
 * @property bool $is_active
 */
class AiAssistantIntent extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'sample_phrases' => 'array',
        'is_active' => 'boolean',
    ];
}
