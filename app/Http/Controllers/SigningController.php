<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeclineEnvelope;
use App\Actions\OpenSigningLink;
use App\Actions\RecordSigningConsent;
use App\Actions\SaveSignerFields;
use App\Actions\SignEnvelope;
use App\Actions\VerifySigningCode;
use App\Exceptions\SigningAccessDenied;
use App\Services\SigningSession;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class SigningController
{
    public function __construct(
        private SigningSession $session,
        private OpenSigningLink $openLink,
        private VerifySigningCode $verifyCode,
        private RecordSigningConsent $recordConsent,
        private SaveSignerFields $saveSignerFields,
        private SignEnvelope $signEnvelope,
        private DeclineEnvelope $declineEnvelope,
    ) {}

    public function open(Request $request, #[\SensitiveParameter] string $token): RedirectResponse|View
    {
        try {
            $binding = $this->openLink->handle($token, $request->ip(), $request->userAgent());
            $this->session->bind($request, $binding['signer'], $binding['token_hash']);

            return to_route('sign.show');
        } catch (SigningAccessDenied|ValidationException) {
            return $this->neutral();
        }
    }

    public function show(Request $request): View
    {
        try {
            $signer = $this->session->signer($request);
        } catch (SigningAccessDenied) {
            return $this->neutral();
        }

        $binding = $request->session()->get('witness.signer');

        if (! is_array($binding) || ($binding['verified'] ?? false) !== true) {
            return view('sign.challenge', compact('signer'));
        }

        if ($signer->consented_at === null) {
            return view('sign.consent', compact('signer'));
        }

        return view('sign.document', compact('signer'));
    }

    public function verify(Request $request): RedirectResponse|View
    {
        try {
            $signer = $this->session->signer($request);
            $data = $request->validate(['code' => ['required', 'digits:6']]);
            $this->verifyCode->handle($signer, $data['code'], $request->ip(), $request->userAgent());
            $this->session->verify($request);

            return to_route('sign.show');
        } catch (SigningAccessDenied) {
            return $this->neutral();
        }
    }

    public function consent(Request $request): RedirectResponse|View
    {
        try {
            $signer = $this->session->signer($request, true);
            $request->validate(['consent' => ['accepted']]);
            $this->recordConsent->handle($signer, $request->ip(), $request->userAgent());

            return to_route('sign.show');
        } catch (SigningAccessDenied) {
            return $this->neutral();
        }
    }

    public function document(Request $request): StreamedResponse|View
    {
        try {
            $signer = $this->session->signer($request, true);
        } catch (SigningAccessDenied) {
            return $this->neutral();
        }

        $document = $signer->envelope->document;

        if ($signer->consented_at === null || $document === null || ! Storage::exists($document->storage_path)) {
            return $this->neutral();
        }

        return Storage::response($document->storage_path, $document->original_name, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="document.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function saveFields(Request $request): RedirectResponse|View
    {
        try {
            $signer = $this->session->signer($request, true);
            $responses = $request->input('fields', []);

            if (! is_array($responses)) {
                throw ValidationException::withMessages(['fields' => 'The field responses were not valid.']);
            }

            $this->saveSignerFields->handle($signer, $responses, $request->ip(), $request->userAgent());

            return to_route('sign.show')->with('status', 'Your responses were saved. Review them, then sign.');
        } catch (SigningAccessDenied) {
            return $this->neutral();
        }
    }

    public function sign(Request $request): RedirectResponse|View
    {
        try {
            $signer = $this->session->signer($request, true);
            $request->validate(['intent' => ['accepted']]);

            try {
                $this->signEnvelope->handle($signer, $request->ip(), $request->userAgent());
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['intent' => $exception->getMessage()]);
            }

            $this->session->forget($request);

            return view('sign.complete');
        } catch (SigningAccessDenied) {
            return $this->neutral();
        }
    }

    public function decline(Request $request): RedirectResponse|View
    {
        try {
            $signer = $this->session->signer($request, true);
            $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
            $this->declineEnvelope->handle($signer, $data['reason'], $request->ip(), $request->userAgent());
            $this->session->forget($request);

            return view('sign.declined');
        } catch (SigningAccessDenied) {
            return $this->neutral();
        }
    }

    private function neutral(): View
    {
        return view('sign.neutral')->with('neutral', true);
    }
}
