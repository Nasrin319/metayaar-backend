<?php

namespace App\Http\Requests\Api\V1\Otp;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

class ResetPasswordWithCodeRequest extends OtpRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->destinationRules(),
            'code' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
