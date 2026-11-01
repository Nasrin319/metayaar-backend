<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\Permission;
use App\Models\Admin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffRoleRequest extends FormRequest
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
            'name' => [
                'required', 'string', 'max:50', 'regex:/^[\pL\pN _-]+$/u',
                Rule::unique('roles')->where('guard_name', Admin::GUARD)->ignore($this->route('role')),
            ],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['distinct', Rule::enum(Permission::class)],
        ];
    }
}
