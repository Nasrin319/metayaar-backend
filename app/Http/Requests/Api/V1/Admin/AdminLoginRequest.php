<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\LoginRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class AdminLoginRequest extends LoginRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'captcha_token' => ['required', 'string'],
            'captcha_answer' => ['required', 'string', 'max:6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'captcha_token.*' => 'پاسخ سؤال امنیتی را وارد کنید.',
            'captcha_answer.*' => 'پاسخ سؤال امنیتی را وارد کنید.',
        ];
    }
}
