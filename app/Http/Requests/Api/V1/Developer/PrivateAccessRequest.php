<?php

namespace App\Http\Requests\Api\V1\Developer;

use App\Models\Setting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PrivateAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Addresses are compared in lower case, so the same one typed twice only counts once.
     */
    protected function prepareForValidation(): void
    {
        if (is_array($emails = $this->input('emails'))) {
            $this->merge(['emails' => array_values(array_unique(array_map(
                fn (mixed $email): mixed => is_string($email) ? mb_strtolower(trim($email)) : $email,
                $emails,
            ), SORT_REGULAR))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_private' => ['required', 'boolean'],
            'emails' => ['present', 'array', 'max:'.Setting::integer('private_listing_max_emails')],
            'emails.*' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'emails.max' => 'حداکثر :max ایمیل می‌توانید برای هر اپ اضافه کنید.',
            'emails.*' => 'ایمیل «:input» معتبر نیست.',
        ];
    }
}
