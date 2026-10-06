<x-signing-layout title="Verification result">
    <section class="signing-card" data-testid="verify-result">
        <p class="signing-eyebrow">Document verification</p>

        @if ($envelope)
            <h1>Verified completed document</h1>
            <dl>
                <dt>Title</dt>
                <dd>{{ $envelope->title }}</dd>
                <dt>Completed</dt>
                <dd>{{ $envelope->completed_at->utc()->format('Y-m-d H:i:s \U\T\C') }}</dd>
                <dt>Signers</dt>
                <dd>{{ $envelope->signers->sortBy('position')->pluck('name')->implode(', ') }}</dd>
            </dl>
        @else
            <h1>No completed document match</h1>
            <p>This upload does not exactly match a completed final PDF held by this Witness installation.</p>
        @endif

        <p><a href="{{ route('verify.index') }}">Verify another PDF</a></p>
    </section>
</x-signing-layout>
