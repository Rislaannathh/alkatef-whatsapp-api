<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class MediaFileOrString implements ValidationRule, DataAwareRule
{
    /**
     * All of the data under validation.
     *
     * @var array<string, mixed>
     */
    protected array $data = [];

    /**
     * Explicit media type field key if known, e.g. 'template.media_type' or 'media_type'.
     */
    protected ?string $mediaTypeKey;

    /**
     * Maximum file size in kilobytes (default: 50MB = 51200KB).
     */
    protected int $maxKilobytes;

    public function __construct(?string $mediaTypeKey = null, int $maxKilobytes = 51200)
    {
        $this->mediaTypeKey = $mediaTypeKey;
        $this->maxKilobytes = $maxKilobytes;
    }

    /**
     * Set the data under validation.
     *
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;
        return $this;
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value)) {
            if (mb_strlen($value) > 1000) {
                $fail("The {$attribute} string must not exceed 1000 characters.");
            }
            return;
        }

        if ($value instanceof UploadedFile) {
            if (! $value->isValid()) {
                $fail("The uploaded {$attribute} file is invalid.");
                return;
            }

            $sizeInKb = (int) ceil($value->getSize() / 1024);
            if ($sizeInKb > $this->maxKilobytes) {
                $maxMb = (int) round($this->maxKilobytes / 1024);
                $fail("The {$attribute} file must not be greater than {$maxMb}MB.");
                return;
            }

            $extension = strtolower($value->getClientOriginalExtension());
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            $videoExtensions = ['mp4', '3gp', 'mov', 'mkv', 'avi'];
            $docExtensions   = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv'];
            $allAllowed      = array_merge($imageExtensions, $videoExtensions, $docExtensions);

            $mediaType = null;
            if ($this->mediaTypeKey !== null) {
                $mediaType = data_get($this->data, $this->mediaTypeKey);
            } else {
                if (str_contains($attribute, '.')) {
                    $prefix = substr($attribute, 0, strrpos($attribute, '.'));
                    $mediaType = data_get($this->data, "{$prefix}.media_type");
                } else {
                    $mediaType = data_get($this->data, 'media_type');
                }
            }

            if ($mediaType === 'image' && ! in_array($extension, $imageExtensions, true)) {
                $fail("The {$attribute} must be an image file (" . implode(', ', $imageExtensions) . ').');
                return;
            }

            if ($mediaType === 'video' && ! in_array($extension, $videoExtensions, true)) {
                $fail("The {$attribute} must be a video file (" . implode(', ', $videoExtensions) . ').');
                return;
            }

            if ($mediaType === 'document' && ! in_array($extension, $docExtensions, true)) {
                $fail("The {$attribute} must be a document file (" . implode(', ', $docExtensions) . ').');
                return;
            }

            if (! in_array($extension, $allAllowed, true)) {
                $fail("The {$attribute} must be a valid image, video, or document file.");
                return;
            }

            return;
        }

        $fail("The {$attribute} must be a valid file (image, video, document) or string.");
    }
}
