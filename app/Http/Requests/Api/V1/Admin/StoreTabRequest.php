<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\StoreTabRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreTabRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the slug before validating so the unique rule checks what will be stored.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('slug')) {
            $this->merge(['slug' => Str::slug((string) $this->input('slug'))]);
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
            'label' => ['required', 'string', 'max:40'],
            'slug' => ['nullable', 'string', 'max:50', Rule::unique('store_tabs')->ignore($this->route('storeTab'))],
            'rule' => ['required', Rule::enum(StoreTabRule::class)],
            'value' => [
                Rule::requiredIf(fn () => StoreTabRule::tryFrom((string) $this->input('rule'))?->needsValue() ?? false),
                'nullable', 'string', 'max:50',
            ],
            'group' => ['required', 'integer', 'min:1', 'max:20'],
            'position' => ['required', 'integer', 'min:0', 'max:999'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
