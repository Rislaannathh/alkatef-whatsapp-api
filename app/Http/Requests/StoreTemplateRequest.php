<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare inputs for validation.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->button)) {
            $decoded = json_decode($this->button, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->merge(['button' => $decoded]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'language' => ['nullable', 'string', 'max:20'],
            'category' => ['nullable', 'integer', 'exists:categories,id'],
            'media_type' => ['nullable', 'string', 'in:image,video,document'],
            'media' => ['nullable', 'string', 'max:1000'],
            'header' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'footer' => ['nullable', 'string'],
            'button' => ['nullable', 'array'],
            'status' => ['nullable', 'string', 'in:draft,pending,approved,rejected,inactive'],
        ];
    }

    /**
     * Override failed-validation response to match the project's JSON format.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
