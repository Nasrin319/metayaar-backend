<?php

namespace App\Http\Requests\Api\V1\Developer;

use App\Enums\Comfort;
use App\Enums\Device;
use App\Enums\LinkType;
use App\Enums\ListingType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListingRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(ListingType::class)],
            'tagline' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:10000'],
            'category' => ['nullable', 'string', 'max:50'],
            'price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            // An APK draft may start without its package name; it's required when submitting for review.
            'package_name' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-zA-Z][\w]*(\.[a-zA-Z][\w]*)+$/',
            ],
            'external_url' => [
                'nullable',
                Rule::requiredIf(fn () => $this->enum('type', ListingType::class)?->requiresExternalUrl() ?? false),
                'url:https,http',
                'max:2048',
            ],
            'version_code' => ['nullable', 'integer', 'min:1', 'max:2100000000'],
            'version_name' => ['nullable', 'string', 'max:50'],
            'seated' => ['sometimes', 'boolean'],
            'comfort' => ['sometimes', Rule::enum(Comfort::class)],
            'supported_devices' => ['nullable', 'array'],
            'supported_devices.*' => ['distinct', Rule::enum(Device::class)],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'distinct', 'max:30'],
            'links' => ['nullable', 'array', 'max:20'],
            'links.*.type' => ['required', Rule::enum(LinkType::class)],
            'links.*.url' => ['required', 'url:https,http', 'max:2048'],
            'trailer_url' => ['nullable', 'url:https', 'max:2048', 'regex:#^https://(www\.)?(youtube\.com|youtu\.be|vimeo\.com|aparat\.com)/#'],
            'early_access' => ['sometimes', 'boolean'],
        ];
    }
}
