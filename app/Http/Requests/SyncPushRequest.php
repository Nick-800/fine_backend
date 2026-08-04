<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SyncPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:100'],
            'outbox' => ['required', 'array', 'min:1'],
            'outbox.*.action_id' => ['required', 'string'],
            'outbox.*.table' => ['required', 'string', 'max:100'],
            'outbox.*.record_id' => ['required', 'uuid'],
            'outbox.*.operation' => ['required', 'string', 'in:create,update,delete'],
            'outbox.*.base_version' => ['required', 'integer', 'min:1'],
            'outbox.*.data' => ['required', 'array'],
        ];
    }
}
