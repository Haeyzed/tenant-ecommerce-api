<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyEmailRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:64'],
            'hash' => ['required', 'string', 'size:40'],
            'expires' => ['required', 'integer'],
            'signature' => ['required', 'string', 'size:64'],
        ];
    }
}
