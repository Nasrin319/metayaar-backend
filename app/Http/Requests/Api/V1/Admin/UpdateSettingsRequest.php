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
            'otp_ttl_minutes' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'otp_resend_seconds' => ['sometimes', 'integer', 'min:10', 'max:600'],
            'otp_max_attempts' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'login_attempts_per_minute' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'otp_requests_per_minute' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'comments_per_minute' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'session_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
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
            'otp_ttl_minutes.*' => 'مدت اعتبار کد باید عددی بین ۱ تا ۶۰ دقیقه باشد.',
            'otp_resend_seconds.*' => 'فاصله ارسال دوباره کد باید عددی بین ۱۰ تا ۶۰۰ ثانیه باشد.',
            'otp_max_attempts.*' => 'تعداد تلاش برای هر کد باید عددی بین ۱ تا ۲۰ باشد.',
            'login_attempts_per_minute.*' => 'تعداد تلاش ورود در دقیقه باید عددی بین ۱ تا ۱۰۰ باشد.',
            'otp_requests_per_minute.*' => 'تعداد درخواست کد در دقیقه باید عددی بین ۱ تا ۶۰ باشد.',
            'comments_per_minute.*' => 'تعداد ثبت نظر در دقیقه باید عددی بین ۱ تا ۶۰ باشد.',
            'session_hours.*' => 'مدت نشست ورود باید عددی بین ۱ تا ۷۲۰ ساعت باشد.',
        ];
    }
}
