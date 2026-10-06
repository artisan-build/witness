<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\PdfFontCoverage;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class SupportedPdfText implements ValidationRule
{
    public function __construct(private string $font = PdfFontCoverage::TEXT) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ! resolve(PdfFontCoverage::class)->supports($this->font, $value)) {
            $fail('The :attribute contains characters that cannot appear in a completed PDF.');
        }
    }
}
