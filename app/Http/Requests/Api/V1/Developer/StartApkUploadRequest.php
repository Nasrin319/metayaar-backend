<?php

namespace App\Http\Requests\Api\V1\Developer;

use App\Models\ApkUpload;
use App\Enums\ListingType;
use Closure;
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
            'filename' => [
                'required', 'string', 'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $windows = $this->route('listing')?->type === ListingType::Windows;
                    $allowed = $windows ? ['exe', 'zip'] : ['apk'];

                    if (! in_array(strtolower(pathinfo((string) $value, PATHINFO_EXTENSION)), $allowed, true)) {
                        $fail($windows ? 'برای اپ ویندوز فقط فایل exe یا zip پذیرفته می‌شود.' : 'فقط فایل apk پذیرفته می‌شود.');
                    }
                },
            ],
            'size' => ['required', 'integer', 'min:1', 'max:'.ApkUpload::MAX_SIZE],
        ];
    }
}
