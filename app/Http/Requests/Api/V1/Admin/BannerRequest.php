<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Rules\PlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BannerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120', new PlainText],
            'image' => [
                $this->route('banner') ? 'nullable' : 'required',
                'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=800,max_width=5000,max_height=5000',
            ],
            // a page of this site or a web address, never a javascript: link
            'link_url' => ['nullable', 'string', 'max:500', 'regex:~^(https?://|/(?!/))~i'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', Rule::when($this->filled('starts_at'), 'after:starts_at')],
            'position' => ['required', 'integer', 'min:0', 'max:999'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * The validated fields to store. Dates arrive with their own offset, and the model would drop it
     * without converting, so they are moved to the app's timezone first.
     *
     * @return array<string, mixed>
     */
    public function bannerAttributes(): array
    {
        return [
            ...$this->safe()->only(['title', 'link_url', 'position', 'is_active']),
            'starts_at' => $this->date('starts_at')?->setTimezone(config('app.timezone')),
            'ends_at' => $this->date('ends_at')?->setTimezone(config('app.timezone')),
        ];
    }
}
