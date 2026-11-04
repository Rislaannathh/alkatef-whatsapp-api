<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AnalyticsRequest extends FormRequest
{
    /**
     * Any authenticated user may call this endpoint.
     * Company-level isolation is enforced in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Custom date range
            'from_date' => ['nullable', 'date', 'before_or_equal:to_date'],
            'to_date'   => ['nullable', 'date', 'after_or_equal:from_date'],

            // Quick-filter shorthand (mutually exclusive with custom dates)
            'quick_filter' => ['nullable', 'string', 'in:last_7_days,last_14_days,last_30_days'],
        ];
    }

    public function messages(): array
    {
        return [
            'from_date.before_or_equal' => 'The from_date must be a date before or equal to to_date.',
            'to_date.after_or_equal'    => 'The to_date must be a date after or equal to from_date.',
            'quick_filter.in'           => 'quick_filter must be one of: last_7_days, last_14_days, last_30_days.',
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
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
