<?php

namespace App\Http\Requests\Api\V1\Developer;

use App\Enums\ListingMedia;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListingMediaRequest extends FormRequest
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
        /** @var ListingMedia $kind */
        $kind = $this->route('kind');
        [$minWidth, $minHeight] = $kind->minimumSize();

        return [
            'image' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
                Rule::dimensions()->minWidth($minWidth)->minHeight($minHeight),
            ],
        ];
    }
}
