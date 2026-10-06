<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PendingFileDeletion;
use Com\Tecnick\Pdf\Tcpdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PdfDocumentService
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    /** @return array{bytes: string, name: string, size: int, pages: int, sha256: string} */
    public function inspect(UploadedFile $file): array
    {
        if (! $file->isValid() || $file->getSize() === false || $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['document' => 'The PDF must be no larger than 20 MiB.']);
        }

        $bytes = file_get_contents($file->getRealPath());

        if ($bytes === false || $bytes === '' || ! str_starts_with($bytes, '%PDF-')) {
            throw ValidationException::withMessages(['document' => 'Choose a valid PDF document.']);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if ($mime !== 'application/pdf') {
            throw ValidationException::withMessages(['document' => 'The uploaded content is not a PDF.']);
        }

        try {
            $pdf = new Tcpdf;
            $source = $pdf->setImportSourceData($bytes, ['strict_limits' => true]);
            $pages = $pdf->getSourcePageCount($source);
        } catch (Throwable $exception) {
            $message = str_contains(strtolower($exception->getMessage()), 'encrypt')
                ? 'Encrypted or password-protected PDFs are not supported.'
                : 'The PDF could not be read safely.';

            throw ValidationException::withMessages(['document' => $message]);
        }

        if ($pages < 1 || $pages > 100) {
            throw ValidationException::withMessages(['document' => 'The PDF must contain between 1 and 100 pages.']);
        }

        return [
            'bytes' => $bytes,
            'name' => $file->getClientOriginalName(),
            'size' => strlen($bytes),
            'pages' => $pages,
            'sha256' => hash('sha256', $bytes),
        ];
    }

    /** @param array{bytes: string, name: string, size: int, pages: int, sha256: string} $pdf */
    public function store(array $pdf, int $teamId): string
    {
        $path = 'documents/'.$teamId.'/'.Str::uuid().'.pdf';

        if (! Storage::put($path, $pdf['bytes'], ['visibility' => 'private'])) {
            throw ValidationException::withMessages(['document' => 'The PDF could not be stored. Please try again.']);
        }

        return $path;
    }

    public function copy(string $sourcePath, int $teamId): string
    {
        $bytes = Storage::get($sourcePath);
        $path = 'documents/'.$teamId.'/'.Str::uuid().'.pdf';

        if (! Storage::put($path, $bytes, ['visibility' => 'private'])) {
            throw ValidationException::withMessages(['document' => 'The PDF could not be copied. Please try again.']);
        }

        return $path;
    }

    public function deleteOrQueue(string $path): bool
    {
        return $this->deleteFromDiskOrQueue((string) config('filesystems.default'), $path);
    }

    private function deleteFromDiskOrQueue(string $disk, string $path): bool
    {
        $pending = PendingFileDeletion::query()->firstOrCreate(['disk' => $disk, 'path' => $path]);

        try {
            $deleted = Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            $deleted = false;
        }

        if ($deleted) {
            $pending->delete();

            return true;
        }

        $pending->increment('attempts');
        $pending->update(['last_attempted_at' => now()]);

        return false;
    }

    public function retryPending(): void
    {
        PendingFileDeletion::query()->orderBy('id')->eachById(function (PendingFileDeletion $pending): void {
            $this->deleteFromDiskOrQueue($pending->disk, $pending->path);
        }, 100);
    }
}
