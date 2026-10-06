<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EnvelopeStatus;
use App\Models\Document;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;
use RuntimeException;

final class VerificationController
{
    public function index(): View
    {
        return view('verify.index');
    }

    public function store(Request $request): View
    {
        $validated = $request->validate([
            'verification_document' => ['required', File::types(['pdf'])->max(20 * 1024)],
        ]);
        $upload = $validated['verification_document'];
        $path = $upload->getRealPath();
        if ($path === false) {
            throw new RuntimeException('The verification upload could not be read.');
        }

        $sha256 = hash_file('sha256', $path);
        $document = Document::query()
            ->where('final_sha256', $sha256)
            ->whereHas('envelope', fn ($query) => $query->where('status', EnvelopeStatus::Completed->value))
            ->with('envelope.signers:id,envelope_id,name,position')
            ->first();

        return view('verify.result', [
            'envelope' => $document?->envelope,
        ]);
    }
}
