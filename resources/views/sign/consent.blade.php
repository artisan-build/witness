<x-signing-layout title="Electronic records consent">
    <section class="signing-card signing-narrow" data-testid="signer-consent">
        <p class="signing-kicker">Before you review</p>
        <h1>Consent to electronic records</h1>
        <div class="signing-disclosure">
            <p>By continuing, you consent to receive, review, and sign this document electronically. Your electronic signature will have the same effect as signing on paper.</p>
            <p>You need a current web browser and the ability to view and retain PDF files. You may withdraw before signing. To request a paper copy or discuss another way to sign, contact {{ $signer->envelope->sender->name }} at {{ $signer->envelope->sender->email }}.</p>
            <p>Witness provides simple electronic signatures and is not suitable for documents excluded from electronic-signature laws.</p>
        </div>
        <form method="POST" action="{{ route('sign.consent') }}" class="signing-stack">
            @csrf
            <label class="signing-check"><input type="checkbox" name="consent" value="1" required> I consent to use electronic records and signatures for this document.</label>
            <button class="signing-button" type="submit">Consent and review document</button>
        </form>
    </section>
</x-signing-layout>
