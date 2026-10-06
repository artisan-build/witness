<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\SignerStatus;
use App\Events\EnvelopeLifecycleRecorded;
use App\Exceptions\SigningAccessDenied;
use App\Models\Envelope;
use App\Models\Field;
use App\Models\Signer;
use App\Services\PdfFontCoverage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SaveSignerFields
{
    public function __construct(private PdfFontCoverage $fontCoverage) {}

    /** @param array<string|int, mixed> $responses */
    public function handle(
        Signer $boundSigner,
        #[\SensitiveParameter] array $responses,
        ?string $ipAddress,
        ?string $userAgent,
    ): Signer {
        $createdPaths = [];
        $replacedPaths = [];

        try {
            $signer = DB::transaction(function () use (
                $boundSigner,
                $responses,
                $ipAddress,
                $userAgent,
                &$createdPaths,
                &$replacedPaths,
            ): Signer {
                $envelope = Envelope::query()->lockForUpdate()->findOrFail($boundSigner->envelope_id);
                $signer = Signer::query()->lockForUpdate()->findOrFail($boundSigner->getKey());
                $fields = Field::query()
                    ->where('envelope_id', $envelope->getKey())
                    ->where('signer_id', $signer->getKey())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($envelope->status !== EnvelopeStatus::Sent || $envelope->expires_at?->isPast()
                    || $signer->consented_at === null
                    || $signer->otp_verified_at === null
                    || $signer->status === SignerStatus::Signed
                    || $signer->status === SignerStatus::Declined) {
                    throw new SigningAccessDenied;
                }

                $ownedIds = $fields->modelKeys();
                foreach (array_keys($responses) as $fieldId) {
                    if (! ctype_digit((string) $fieldId) || ! in_array((int) $fieldId, $ownedIds, true)) {
                        throw new SigningAccessDenied;
                    }
                }

                foreach ($fields as $field) {
                    $response = $responses[(string) $field->getKey()] ?? $responses[$field->getKey()] ?? null;

                    if ($field->type !== FieldType::DateSigned && $response === null) {
                        continue;
                    }

                    if ($response !== null && ! is_array($response)) {
                        throw ValidationException::withMessages(['fields' => 'A field response was not valid.']);
                    }

                    if ($this->isEmptySignatureResponse($field, $response)) {
                        continue;
                    }

                    $value = $this->normalize($field, $response ?? [], $createdPaths);
                    $oldPath = $field->value['path'] ?? null;

                    DB::statement("SET LOCAL witness.field_response_write = 'on'");
                    Field::query()->whereKey($field)->update([
                        'value' => json_encode($value, JSON_THROW_ON_ERROR),
                        'completed_at' => $field->completed_at ?? now(),
                        'updated_at' => now(),
                    ]);
                    DB::statement("SET LOCAL witness.field_response_write = 'off'");

                    if ($field->completed_at === null) {
                        EnvelopeLifecycleRecorded::record(
                            $envelope,
                            AuditEventType::FieldCompleted,
                            AuditActorType::Signer,
                            $signer->getKey(),
                            $ipAddress,
                            $userAgent,
                            ['field_id' => $field->getKey(), 'field_type' => $field->type->value],
                        );
                    }

                    if (is_string($oldPath) && ($value['path'] ?? null) !== $oldPath) {
                        $replacedPaths[] = $oldPath;
                    }
                }

                return $signer->refresh()->load('fields');
            });
        } catch (\Throwable $exception) {
            foreach ($createdPaths as $path) {
                Storage::delete($path);
            }

            throw $exception;
        }

        foreach ($replacedPaths as $path) {
            Storage::delete($path);
        }

        return $signer;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<string>  $createdPaths
     * @return array<string, bool|string>
     */
    private function normalize(Field $field, array $response, array &$createdPaths): array
    {
        return match ($field->type) {
            FieldType::DateSigned => ['kind' => 'date', 'value' => now()->utc()->toDateString()],
            FieldType::Text => ['kind' => 'text', 'value' => $this->plainText($response['text'] ?? null, 2000, PdfFontCoverage::TEXT)],
            FieldType::Checkbox => ['kind' => 'checkbox', 'checked' => filter_var($response['checked'] ?? false, FILTER_VALIDATE_BOOL)],
            FieldType::Signature, FieldType::Initials => $this->signatureValue($field, $response, $createdPaths),
        };
    }

    private function plainText(mixed $value, int $maximum, string $font): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages(['fields' => 'Enter a value for every completed text field.']);
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $maximum || strip_tags($value) !== $value || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            throw ValidationException::withMessages(['fields' => 'A text field contains unsupported content.']);
        }
        if (! $this->fontCoverage->supports($font, $value)) {
            throw ValidationException::withMessages(['fields' => 'A field contains characters that cannot appear in the completed PDF.']);
        }

        return $value;
    }

    /** @param array<string, mixed>|null $response */
    private function isEmptySignatureResponse(Field $field, ?array $response): bool
    {
        if (! in_array($field->type, [FieldType::Signature, FieldType::Initials], true) || $response === null) {
            return false;
        }

        return match ($response['kind'] ?? null) {
            'typed' => ! isset($response['typed'])
                || (is_string($response['typed']) && trim($response['typed']) === ''),
            'drawn' => ! isset($response['drawn'])
                || (is_string($response['drawn']) && trim($response['drawn']) === ''),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<string>  $createdPaths
     * @return array<string, string>
     */
    private function signatureValue(Field $field, array $response, array &$createdPaths): array
    {
        if (($response['kind'] ?? null) === 'typed') {
            return [
                'kind' => 'typed',
                'text' => $this->plainText($response['typed'] ?? null, 100, PdfFontCoverage::SCRIPT),
            ];
        }

        if (($response['kind'] ?? null) !== 'drawn' || ! is_string($response['drawn'] ?? null)) {
            throw ValidationException::withMessages(['fields' => 'Choose a typed or drawn signature.']);
        }

        $encoded = $response['drawn'];
        if (! str_starts_with($encoded, 'data:image/png;base64,')) {
            throw ValidationException::withMessages(['fields' => 'The drawn signature must be a PNG image.']);
        }

        $bytes = base64_decode(substr($encoded, 22), true);
        $dimensions = is_string($bytes) ? @getimagesizefromstring($bytes) : false;

        if (! is_string($bytes)
            || strlen($bytes) > 512 * 1024
            || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")
            || $dimensions === false
            || $dimensions['mime'] !== 'image/png'
            || $dimensions[0] > 2000
            || $dimensions[1] > 1000
            || ! $this->isCompletePng($bytes)) {
            throw ValidationException::withMessages(['fields' => 'The drawn signature image is not valid.']);
        }

        $path = 'signatures/'.$field->envelope_id.'/'.Str::uuid().'.png';
        if (! Storage::put($path, $bytes)) {
            throw ValidationException::withMessages(['fields' => 'The drawn signature could not be stored.']);
        }
        $createdPaths[] = $path;

        return ['kind' => 'drawn', 'path' => $path, 'sha256' => hash('sha256', $bytes)];
    }

    private function isCompletePng(string $bytes): bool
    {
        $offset = 8;
        $length = strlen($bytes);
        $sawImageData = false;

        while ($offset + 12 <= $length) {
            $chunkLength = unpack('Nlength', substr($bytes, $offset, 4));
            if ($chunkLength === false) {
                return false;
            }

            $dataLength = $chunkLength['length'];
            $chunkEnd = $offset + 12 + $dataLength;
            if ($chunkEnd > $length) {
                return false;
            }

            $type = substr($bytes, $offset + 4, 4);
            $data = substr($bytes, $offset + 8, $dataLength);
            $storedCrc = substr($bytes, $offset + 8 + $dataLength, 4);
            $calculatedCrc = pack('H*', hash('crc32b', $type.$data));

            if (! hash_equals($storedCrc, $calculatedCrc)) {
                return false;
            }

            if ($offset === 8) {
                if ($type !== 'IHDR' || $dataLength !== 13) {
                    return false;
                }
            } elseif ($type === 'IHDR') {
                return false;
            }

            if ($type === 'IDAT') {
                $sawImageData = true;
            }

            $offset = $chunkEnd;
            if ($type === 'IEND') {
                return $dataLength === 0 && $sawImageData && $offset === $length;
            }
        }

        return false;
    }
}
