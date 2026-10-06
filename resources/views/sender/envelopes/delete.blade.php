<x-sender-layout :title="'Delete '.$envelope->title">
    <section class="witness-panel" data-testid="delete-envelope-confirmation">
        <p class="witness-kicker">Permanent deletion</p>
        <h1>Delete {{ $envelope->title }}?</h1>
        <p>This permanently removes the envelope, its audit trail, signatures, and private PDF files. This cannot be undone.</p>
        <form method="POST" action="{{ route('sender.envelopes.destroy', $envelope) }}" class="witness-stack">
            @csrf @method('DELETE')
            <flux:input name="confirmation" label="Type DELETE to confirm" required />
            <button class="witness-button" type="submit">Permanently delete</button>
        </form>
    </section>
</x-sender-layout>
