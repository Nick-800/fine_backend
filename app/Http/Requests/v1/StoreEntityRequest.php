<?php

declare(strict_types=1);

namespace App\Http\Requests\v1;

use App\Enums\EntityRoleType;
use App\Enums\EntityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'entity_type' => ['required', Rule::enum(EntityType::class)],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],

            // Optional Primary Contact
            'contact' => ['nullable', 'array'],
            'contact.contact_name' => ['nullable', 'string', 'max:255'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'contact.phone' => ['nullable', 'string', 'max:50'],
            'contact.address' => ['nullable', 'string'],
            'contact.city' => ['nullable', 'string', 'max:100'],
            'contact.country' => ['nullable', 'string', 'max:100'],

            // Optional Initial Roles
            'roles' => ['nullable', 'array'],
            'roles.*.role_type' => ['required', Rule::enum(EntityRoleType::class)],
            'roles.*.operating_unit_id' => ['nullable', 'uuid', 'exists:operating_units,id'],
        ];
    }
}
