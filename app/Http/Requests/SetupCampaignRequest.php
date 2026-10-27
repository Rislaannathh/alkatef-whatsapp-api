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

    protected function prepareForValidation(): void
    {
        $template = $this->input('template');
        if (is_array($template) && isset($template['button']) && is_string($template['button'])) {
            $decoded = json_decode($template['button'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $template['button'] = $decoded;
                $this->merge(['template' => $template]);
            }
        }
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
            'template.button'               => ['nullable', 'array'],
            'template.status'               => ['nullable', 'string', 'in:draft,pending,approved,rejected,inactive'],

            // ── Campaign ──────────────────────────────────────────────────────
            'campaign'                      => ['required', 'array'],
            'campaign.name'                 => ['required', 'string', 'max:150'],
            'campaign.description'          => ['nullable', 'string'],
            'campaign.scheduled_at'         => ['nullable', 'date'],

            // ── Import method (optional explicit selection) ───────────────────
            // When supplied, the value must be one of the four supported methods.
            // When omitted, the method is auto-detected from the input fields.
            // Accepted values:
            //   'excel'         – requires contacts_file (.xlsx)
            //   'csv'           – requires contacts_file (.csv)
            //   'phone_numbers' – requires phone_numbers
            //   'single'        – requires contact
            'import_method'                 => ['nullable', 'string', 'in:excel,csv,phone_numbers,single'],

            // ── Import behaviour (optional) ───────────────────────────────────
            // 'add_only'        (default) – insert new contacts, skip duplicates.
            // 'update_existing'           – insert new contacts AND update existing
            //                              ones matched by (company_id, phone).
            'import_behavior'               => ['nullable', 'string', 'in:add_only,update_existing'],

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
     * must be present.
     *
     * When import_method is explicitly supplied we additionally verify that
     * the correct input field accompanies it:
     *   excel / csv   → contacts_file required
     *   phone_numbers → phone_numbers required
     *   single        → contact required
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $hasFile    = $this->hasFile('contacts_file');
            $hasContact = $this->filled('contact');
            $hasPhones  = $this->filled('phone_numbers');
            $method     = $this->input('import_method');

            $count = (int) $hasFile + (int) $hasContact + (int) $hasPhones;

            // ── Presence check ───────────────────────────────────────────────
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

            // ── import_method cross-validation ───────────────────────────────
            if ($method !== null && $count === 1) {
                switch ($method) {
                    case 'excel':
                    case 'csv':
                        if (! $hasFile) {
                            $v->errors()->add(
                                'import_method',
                                "import_method '{$method}' requires a contacts_file upload."
                            );
                        }
                        break;

                    case 'phone_numbers':
                        if (! $hasPhones) {
                            $v->errors()->add(
                                'import_method',
                                "import_method 'phone_numbers' requires the phone_numbers field."
                            );
                        }
                        break;

                    case 'single':
                        if (! $hasContact) {
                            $v->errors()->add(
                                'import_method',
                                "import_method 'single' requires the contact object."
                            );
                        }
                        break;
                }
            }

            // ── Single-contact phone requirement ─────────────────────────────
            if ($hasContact && ! $this->filled('contact.phone')) {
                $v->errors()->add(
                    'contact.phone',
                    'A single contact must have a phone number.'
                );
            }

            // ── Phone-numbers non-empty check ─────────────────────────────────
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
     * Return the canonical contact input type for this request.
     * Only call after validation has passed.
     *
     * When import_method is explicitly set we honour it directly.
     * When absent we fall back to auto-detection.
     */
    public function contactInputType(): string
    {
        $method = $this->input('import_method');

        if ($method !== null) {
            // 'excel' and 'csv' both map to the 'file' job type.
            return match ($method) {
                'excel', 'csv' => 'file',
                default        => $method,
            };
        }

        // Auto-detect from whichever input field is present.
        if ($this->hasFile('contacts_file')) {
            return 'file';
        }

        if ($this->filled('contact')) {
            return 'single';
        }

        return 'phone_numbers';
    }

    /**
     * Return the import behaviour for this request.
     * Defaults to 'add_only' when not explicitly supplied.
     */
    public function importBehavior(): string
    {
        return $this->input('import_behavior', 'add_only');
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
