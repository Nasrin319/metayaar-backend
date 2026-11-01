<?php

namespace App\Http\Requests\Api\V1\Otp;

use App\Enums\OtpPurpose;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class SendOtpRequest extends OtpRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->destinationRules(),
            'purpose' => ['required', Rule::in(OtpPurpose::publicValues())],
        ];
    }

    public function purpose(): OtpPurpose
    {
        return $this->enum('purpose', OtpPurpose::class);
    }
}
