<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'otp_max_sends' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'otp_lockout_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'otp_max_sends.*' => 'تعداد ارسال کد باید عددی بین ۱ تا ۱۰۰ باشد.',
            'otp_lockout_hours.*' => 'مدت مسدودی باید عددی بین ۱ تا ۷۲۰ ساعت باشد.',
        ];
    }
}
