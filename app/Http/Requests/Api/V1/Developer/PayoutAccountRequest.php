<?php

namespace App\Http\Requests\Api\V1\Developer;

use App\Rules\IranianNationalId;
use App\Rules\IranianSheba;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The developer's payout details: the bank account sales are paid to, and who holds it.
 */
class PayoutAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $digitsOnly = fn (string $key): ?string => is_string($this->input($key))
            ? preg_replace('/[\s\-]/', '', self::latinDigits($this->input($key)))
            : $this->input($key);

        $sheba = $digitsOnly('sheba');
        if (is_string($sheba)) {
            $sheba = strtoupper($sheba);
            // the IR prefix is optional when typing
            $sheba = preg_match('/^\d{24}$/', $sheba) ? "IR{$sheba}" : $sheba;
        }

        $cardNumber = $digitsOnly('card_number');

        $this->merge([
            'sheba' => $sheba,
            'national_id' => $digitsOnly('national_id'),
            'card_number' => $cardNumber === '' ? null : $cardNumber,
            'postal_code' => $digitsOnly('postal_code'),
        ]);
    }

    /**
     * Organizations are paid to the organization's account, identified by its 11-digit national ID.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'holder_name' => ['required', 'string', 'max:255'],
            'national_id' => ['required', 'string', new IranianNationalId(organization: $this->user()->isOrganization())],
            'bank_name' => ['required', 'string', 'max:100'],
            'sheba' => ['required', 'string', new IranianSheba],
            'card_number' => ['nullable', 'string', 'regex:/^\d{16}$/'],
            'postal_code' => ['required', 'string', 'regex:/^\d{10}$/'],
            'address' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'card_number.regex' => 'شماره کارت باید ۱۶ رقم باشد.',
            'postal_code.regex' => 'کد پستی باید ۱۰ رقم باشد.',
        ];
    }

    private static function latinDigits(string $value): string
    {
        return strtr(trim($value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}
