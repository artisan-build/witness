<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EnvelopeStatus;
use App\Enums\MailDeliveryPurpose;
use App\Enums\MailDeliveryStatus;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\MailDelivery;
use App\Models\Template;
use App\Support\SelectedTeam;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class DocumentController
{
    public function __construct(private SelectedTeam $selectedTeam) {}

    public function envelope(Request $request, int $envelope): StreamedResponse
    {
        $user = $this->user($request);
        $document = Envelope::query()
            ->forTeam($this->selectedTeam->get())
            ->where('sender_id', $user->getKey())
            ->with('document')
            ->findOrFail($envelope)
            ->document;

        abort_if($document === null || ! Storage::exists($document->storage_path), 404);

        return Storage::response($document->storage_path, $document->original_name, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $document->original_name).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function template(Request $request, int $template): StreamedResponse
    {
        $user = $this->user($request);
        $record = Template::query()
            ->forTeam($this->selectedTeam->get())
            ->where('sender_id', $user->getKey())
            ->findOrFail($template);

        abort_unless(Storage::exists($record->storage_path), 404);

        return Storage::response($record->storage_path, $record->original_name, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $record->original_name).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function final(Request $request, int $envelope): Response
    {
        $user = $this->user($request);
        $record = Envelope::query()
            ->forTeam($this->selectedTeam->get())
            ->where('sender_id', $user->getKey())
            ->where('status', EnvelopeStatus::Completed->value)
            ->with('document')
            ->findOrFail($envelope);

        abort_if($record->document === null, 404);

        return $this->finalResponse($record->document, 'completed-'.$record->getKey().'.pdf');
    }

    public function recipient(MailDelivery $delivery): Response
    {
        abort_unless(
            $delivery->purpose === MailDeliveryPurpose::FinalDocument
            && $delivery->status !== MailDeliveryStatus::Pending,
            404,
        );
        $envelope = Envelope::query()
            ->whereKey($delivery->envelope_id)
            ->where('status', EnvelopeStatus::Completed->value)
            ->with('document')
            ->firstOrFail();
        abort_if($envelope->document === null, 404);

        return $this->finalResponse($envelope->document, 'completed-'.$envelope->getKey().'.pdf');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function finalResponse(Document $document, string $filename): Response
    {
        $path = $document->final_storage_path;
        abort_if($path === null || $document->final_sha256 === null || ! Storage::exists($path), 404);
        $bytes = Storage::get($path);
        abort_unless(hash_equals($document->final_sha256, hash('sha256', $bytes)), 404);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $filename).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
