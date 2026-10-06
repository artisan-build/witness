<x-sender-layout title="Envelopes">
    <header class="witness-page-header" data-testid="sender-dashboard">
        <div>
            <p class="witness-kicker">Sender desk</p>
            <h1>Your envelopes</h1>
            <p>Prepare PDFs, assign fields, and preserve a clear audit trail.</p>
        </div>
        <a class="witness-button" href="{{ route('sender.envelopes.create') }}" data-testid="new-envelope">Send a PDF</a>
    </header>

    <form method="GET" action="{{ route('sender.dashboard') }}" class="witness-inline-form" data-testid="status-filter">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">All</option>
            @foreach (\App\Enums\EnvelopeStatus::cases() as $option)
                <option value="{{ $option->value }}" @selected($status === $option->value)>{{ str($option->value)->replace('_', ' ')->title() }}</option>
            @endforeach
        </select>
        <flux:button type="submit">Filter</flux:button>
    </form>

    <section class="witness-list" data-testid="envelope-list">
        @forelse ($envelopes as $envelope)
            <a class="witness-list-item" href="{{ route('sender.envelopes.edit', $envelope) }}">
                <div>
                    <strong>{{ $envelope->title }}</strong>
                    <span>{{ $envelope->signers->count() }} {{ Str::plural('signer', $envelope->signers->count()) }}</span>
                    <span>{{ $envelope->signers->map(fn ($signer) => $signer->name.': '.$signer->status->value)->join(' · ') }}</span>
                </div>
                <span class="witness-status witness-status-{{ $envelope->status->value }}">{{ ucfirst($envelope->status->value) }}</span>
            </a>
        @empty
            <div class="witness-empty">
                <h2>No envelopes yet</h2>
                <p>Start with one PDF. Nothing is sent until you review it.</p>
            </div>
        @endforelse
    </section>
</x-sender-layout>
