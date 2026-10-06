<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Envelope;
use App\Models\Template;
use App\Support\SelectedTeam;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;
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
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
