<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class SetupCampaignRequest extends FormRequest
{
    /**
     * All authenticated users may call this endpoint.
     * Company-level isolation is enforced in the controller, not here.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ── Template ──────────────────────────────────────────────────────
            'template'                      => ['required', 'array'],
            'template.name'                 => ['required', 'string', 'max:150'],
            'template.language'             => ['nullable', 'string', 'max:20'],
            'template.category'             => ['nullable', 'integer', 'exists:categories,id'],
            'template.media_type'           => ['nullable', 'string', 'in:image,video,document'],
            'template.media'                => ['nullable', 'string', 'max:1000'],
            'template.header'               => ['nullable', 'string'],
            'template.body'                 => ['nullable', 'string'],
            'template.footer'               => ['nullable', 'string'],
            'template.button'               => ['nullable', 'string', 'max:150'],
            'template.button_action_type'   => ['nullable', 'string', 'in:url,phone,quick_reply'],
            'template.button_value'         => ['nullable', 'string'],
            'template.status'               => ['nullable', 'string', 'in:draft,pending,approved,rejected,inactive'],

            // ── Campaign ──────────────────────────────────────────────────────
            'campaign'                      => ['required', 'array'],
            'campaign.name'                 => ['required', 'string', 'max:150'],
            'campaign.description'          => ['nullable', 'string'],
            'campaign.scheduled_at'         => ['nullable', 'date'],

            // ── Contact input (exactly one must be present – see withValidator) ─
            'contacts_file'                 => ['nullable', 'file', 'mimes:xlsx,csv', 'max:10240'],
            'contact'                       => ['nullable', 'array'],
            'contact.name'                  => ['nullable', 'string', 'max:150'],
            'contact.country_code'          => ['nullable', 'string', 'max:10'],
            'contact.phone'                 => ['nullable', 'string', 'max:30'],
            'contact.email'                 => ['nullable', 'email', 'max:150'],
            'phone_numbers'                 => ['nullable', 'string'],
        ];
    }

    /**
     * Enforce the mutual-exclusion rule: exactly one contact-input format
     * must be present.  A request that supplies more than one format, or
     * none at all, is rejected with a clear validation error message.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $hasFile    = $this->hasFile('contacts_file');
            $hasContact = $this->filled('contact');
            $hasPhones  = $this->filled('phone_numbers');

            $count = (int) $hasFile + (int) $hasContact + (int) $hasPhones;

            if ($count === 0) {
                $v->errors()->add(
                    'contact_input',
                    'You must provide exactly one contact input: contacts_file, contact, or phone_numbers.'
                );
            } elseif ($count > 1) {
                $v->errors()->add(
                    'contact_input',
                    'Only one contact input format is allowed per request. Provide contacts_file, contact, or phone_numbers – not multiple.'
                );
            }

            // Validate that a single-contact submission has at least a phone number.
            if ($hasContact && ! $this->filled('contact.phone')) {
                $v->errors()->add(
                    'contact.phone',
                    'A single contact must have a phone number.'
                );
            }

            // Validate that phone_numbers is not empty after splitting.
            if ($hasPhones) {
                $entries = array_filter(
                    array_map('trim', explode(',', $this->input('phone_numbers', ''))),
                    fn (string $s) => $s !== ''
                );

                if (empty($entries)) {
                    $v->errors()->add(
                        'phone_numbers',
                        'phone_numbers must contain at least one non-empty phone number.'
                    );
                }
            }
        });
    }

    /**
     * Return the contact input type detected in this request.
     * Only call after validation has passed.
     */
    public function contactInputType(): string
    {
        if ($this->hasFile('contacts_file')) {
            return 'file';
        }

        if ($this->filled('contact')) {
            return 'single';
        }

        return 'phone_numbers';
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
