<x-sender-layout title="Envelopes">
    <header class="witness-page-header"
            data-testid="sender-dashboard">
        <div>
            <p class="witness-kicker">
                Envelopes
            </p>
            <h1>
                {{ $filter?->label() ?? 'All envelopes' }}
            </h1>
            <p>
                Send a PDF, collect signatures, and keep proof of who signed it.
            </p>
        </div>
        <a class="witness-button"
           href="{{ route('sender.envelopes.create') }}"
           data-testid="new-envelope">
            Send a PDF
        </a>
    </header>

    <section class="witness-list"
             data-testid="envelope-list">
        @forelse ($envelopes as $envelope)
            <a class="witness-list-item witness-envelope-row"
               href="{{ route('sender.envelopes.edit', $envelope) }}"
               data-testid="envelope-row">
                <div>
                    <strong>{{ $envelope->title }}</strong>
                    <span>{{ $envelope->progressSummary() }} · {{ $envelope->signers->pluck('name')->join(', ') ?: 'Add signers to continue' }}</span>
                </div>
                <div class="witness-row-meta">
                    <span class="witness-status witness-status-{{ $envelope->status->value }}">{{ $envelope->status->label() }}</span>
                    <span>Updated {{ $envelope->updated_at?->diffForHumans() }}</span>
                </div>
            </a>
        @empty
            @if ($isFiltered)
                <div class="witness-empty"
                     data-testid="envelope-list-empty">
                    <h2>Nothing here</h2>
                    <p>No envelopes are in this group right now.</p>
                </div>
            @else
                <div class="witness-empty witness-onboarding"
                     data-testid="envelope-list-empty">
                    <h2>Send your first PDF for signature</h2>
                    <ol class="witness-how">
                        <li><strong>Upload a PDF</strong><span>Contracts, proposals, consent forms. It is stored privately.</span></li>
                        <li><strong>Add signers and place fields</strong><span>Say who signs and drop signature, date, and text fields where they go.</span></li>
                        <li><strong>Send it</strong><span>Each signer gets an email link and a verification code. You get the signed PDF and a full audit trail.</span></li>
                    </ol>
                    <a class="witness-button"
                       href="{{ route('sender.envelopes.create') }}">
                        Send a PDF
                    </a>
                </div>
            @endif
        @endforelse
    </section>
</x-sender-layout>
