<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request. The password is required for a new account
     * and optional when editing one.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $editing = $this->route('admin') !== null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('admins')->ignore($this->route('admin'))],
            'password' => [$editing ? 'nullable' : 'required', 'string', Password::defaults()],
            'roles' => ['present', 'array'],
            'roles.*' => ['distinct', Rule::exists('roles', 'name')->where('guard_name', Admin::GUARD)],
        ];
    }
}
