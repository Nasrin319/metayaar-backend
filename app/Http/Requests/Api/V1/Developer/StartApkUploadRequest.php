<?php

namespace App\Http\Requests\Api\V1\Developer;

use App\Models\ApkUpload;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StartApkUploadRequest extends FormRequest
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
            'filename' => ['required', 'string', 'max:255', 'ends_with:.apk,.APK'],
            'size' => ['required', 'integer', 'min:1', 'max:'.ApkUpload::MAX_SIZE],
        ];
    }
}
