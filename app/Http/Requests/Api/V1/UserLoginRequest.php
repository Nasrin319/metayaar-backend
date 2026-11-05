<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Mobile;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A website sign-in with a password, by email or by mobile: members don't have to have an email.
 */
class UserLoginRequest extends LoginRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (is_string($this->input('mobile')) && trim($this->input('mobile')) !== '') {
            $this->merge(['mobile' => Mobile::normalize($this->input('mobile'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'email' => ['required_without:mobile', 'nullable', 'string', 'email'],
            'mobile' => ['required_without:email', 'nullable', 'string', 'regex:/^09\d{9}$/'],
        ];
    }
}
