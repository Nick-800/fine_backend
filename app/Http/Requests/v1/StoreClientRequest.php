<?php

declare(strict_types=1);

namespace App\Http\Requests\v1;

use App\Enums\ClientStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_id' => ['required', 'uuid', 'exists:entities,id', 'unique:clients,entity_id'],
            'operating_unit_id' => ['required', 'uuid', 'exists:operating_units,id'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'account_id' => ['nullable', 'uuid', 'exists:accounts,id'],
            'status' => ['nullable', Rule::enum(ClientStatus::class)],
        ];
    }
}
