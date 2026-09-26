<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Auth\Support\DisplayPreferences;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePreferencesRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return DisplayPreferences::rules();
    }
}
