<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\PlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Organization details from the account page: turns an individual account into an organization
 * account, or edits the details of one. Same rules as the organization fields of RegisterRequest.
 */
class UpdateOrganizationRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', new PlainText],
            'national_id' => ['nullable', 'string', 'regex:/^\d{10,11}$/'],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }
}
