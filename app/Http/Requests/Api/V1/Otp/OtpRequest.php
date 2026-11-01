<?php

namespace App\Http\Requests\Api\V1\Otp;

use App\Enums\OtpChannel;
use App\Models\User;
use App\Support\Mobile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Base for requests that name a channel and a destination (an email address or a mobile number).
 * Mobiles are normalized to 09xxxxxxxxx before validation.
 */
abstract class OtpRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $destination = $this->input('destination');
        if (! is_string($destination)) {
            return;
        }

        $this->merge([
            'destination' => $this->input('channel') === OtpChannel::Mobile->value
                ? Mobile::normalize($destination)
                : mb_strtolower(trim($destination)),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function destinationRules(): array
    {
        $channel = OtpChannel::tryFrom((string) $this->input('channel'));

        return [
            'channel' => ['required', Rule::enum(OtpChannel::class)],
            'destination' => $channel?->destinationRules() ?? ['required', 'string'],
        ];
    }

    public function channel(): OtpChannel
    {
        return $this->enum('channel', OtpChannel::class);
    }

    public function destination(): string
    {
        return $this->validated('destination');
    }

    /**
     * The account the destination belongs to, if any.
     */
    public function account(): ?User
    {
        return User::where($this->channel() === OtpChannel::Email ? 'email' : 'mobile', $this->destination())->first();
    }
}
