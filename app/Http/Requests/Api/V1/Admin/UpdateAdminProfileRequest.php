<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Rules\PlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Emails are stored lowercase, see App\Http\Requests\Api\V1\LoginRequest.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new PlainText],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('admins', 'email')->ignore($this->user()->id)],
        ];
    }
}
