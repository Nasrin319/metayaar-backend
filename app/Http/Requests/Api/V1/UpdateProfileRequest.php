<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Role;
use App\Rules\PlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Emails are stored lowercase; accept them typed with capitals or spaces (phones capitalize the
     * first letter), since PostgreSQL compares them case-sensitively.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email'))) ?: null]);
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
            'email' => [
                // only developers must keep one
                Rule::requiredIf(fn (): bool => $this->user()->hasRole(Role::Developer->value)),
                'nullable',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
        ];
    }
}
