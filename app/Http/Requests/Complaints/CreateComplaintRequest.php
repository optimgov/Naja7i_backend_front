<?php

namespace App\Http\Requests\Complaints;

use App\Models\ComplaintThread;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateComplaintRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'subject' => is_string($this->input('subject')) ? trim($this->input('subject')) : $this->input('subject'),
            'body' => is_string($this->input('body')) ? trim($this->input('body')) : $this->input('body'),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(ComplaintThread::CATEGORIES)],
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'idempotency_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }
}
