<x-signing-layout title="Verify your email">
    <section class="signing-card signing-narrow" data-testid="signer-otp">
        <p class="signing-kicker">Email verification</p>
        <h1>Check your email</h1>
        @if ($deliveryFailed)
            <p>We could not confirm that the code was delivered. Request a new code before continuing.</p>
            <form method="POST" action="{{ route('sign.code.rotate') }}" class="signing-stack">
                @csrf
                <button class="signing-button" type="submit">Send a new code</button>
            </form>
        @else
            <p>We sent a six-digit code to the address for {{ $signer->name }}. It expires in 10 minutes.</p>
        @endif
        <form method="POST" action="{{ route('sign.verify') }}" class="signing-stack">
            @csrf
            <label for="code">Verification code</label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus>
            <button class="signing-button" type="submit">Verify code</button>
        </form>
        <p class="signing-help">Five failed attempts lock this code. Reopen the private link after the code expires to receive a new one.</p>
    </section>
</x-signing-layout>
