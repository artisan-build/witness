<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use App\Exceptions\SigningAccessDenied;
use App\Models\Signer;
use Illuminate\Http\Request;

final class SigningSession
{
    private const string KEY = 'witness.signer';

    public function bind(Request $request, Signer $signer, string $tokenHash): void
    {
        $request->session()->regenerate();
        $request->session()->put(self::KEY, [
            'signer_id' => (int) $signer->getKey(),
            'token_hash' => $tokenHash,
            'verified' => false,
        ]);
    }

    public function verify(Request $request): void
    {
        $binding = $request->session()->get(self::KEY);

        if (! is_array($binding)) {
            throw new SigningAccessDenied;
        }

        $binding['verified'] = true;
        $request->session()->put(self::KEY, $binding);
    }

    public function signer(Request $request, bool $mustBeVerified = false): Signer
    {
        $binding = $request->session()->get(self::KEY);

        if (! is_array($binding)
            || ! isset($binding['signer_id'], $binding['token_hash'])
            || ($mustBeVerified && ($binding['verified'] ?? false) !== true)) {
            throw new SigningAccessDenied;
        }

        $signer = Signer::query()
            ->with(['envelope.document', 'envelope.sender', 'fields'])
            ->find($binding['signer_id']);

        if ($signer === null
            || $signer->link_token_hash === null
            || ! hash_equals($signer->link_token_hash, (string) $binding['token_hash'])
            || $signer->envelope->status !== EnvelopeStatus::Sent
            || ($signer->envelope->expires_at !== null && $signer->envelope->expires_at->isPast())
            || in_array($signer->status, [SignerStatus::Signed, SignerStatus::Declined], true)) {
            $this->forget($request);
            throw new SigningAccessDenied;
        }

        return $signer;
    }

    public function forget(Request $request): void
    {
        $request->session()->forget(self::KEY);
    }
}
