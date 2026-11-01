<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountType;
use App\Support\Mobile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // emails are stored lowercase, see LoginRequest
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
        if (is_string($this->input('mobile')) && trim($this->input('mobile')) !== '') {
            $this->merge(['mobile' => Mobile::normalize($this->input('mobile'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request. The email, and the mobile when given, are
     * proven with signup codes from POST auth/otp; see App\Support\Otp.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'email_code' => ['required', 'string', 'digits:6'],
            'mobile' => ['nullable', 'string', 'regex:/^09\d{9}$/', 'unique:users,mobile'],
            'mobile_code' => ['required_with:mobile', 'nullable', 'string', 'digits:6'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'account_type' => ['sometimes', Rule::enum(AccountType::class)],
            'organization_name' => ['exclude_unless:account_type,organization', 'required', 'string', 'max:255'],
            'organization_national_id' => ['exclude_unless:account_type,organization', 'nullable', 'string', 'regex:/^\d{10,11}$/'],
            'organization_phone' => ['exclude_unless:account_type,organization', 'nullable', 'string', 'max:20'],
        ];
    }

    public function accountType(): AccountType
    {
        return $this->enum('account_type', AccountType::class) ?? AccountType::Individual;
    }
}
