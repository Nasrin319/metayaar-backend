<?php

namespace App\Http\Requests\Api\V1\Otp;

use Illuminate\Contracts\Validation\ValidationRule;

class LoginWithCodeRequest extends OtpRequest
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
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
