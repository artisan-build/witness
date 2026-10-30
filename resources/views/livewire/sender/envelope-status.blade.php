<div data-testid="envelope-editor"
     data-page="status">
    <header class="witness-page-header witness-page-header-compact">
        <div>
            <p class="witness-kicker">
                <a href="{{ route('sender.dashboard', ['status' => $filter->value]) }}">{{ $filter->label() }}</a>
            </p>
            <h1>{{ $envelope->title }}</h1>
            <p>Sent {{ $envelope->sent_at?->diffForHumans() }} &middot; {{ $envelope->document?->original_name }}</p>
        </div>
        <span class="witness-status witness-status-{{ $envelope->status->value }}">{{ $envelope->status->label() }}</span>
    </header>

    @if ($envelope->status === \App\Enums\EnvelopeStatus::CompletionFailed)
        <div class="witness-alert"
             data-testid="completion-failed">
            <div>
                <strong>Completion failed.</strong>
                Every signature and all the audit evidence are preserved. Retrying builds the final PDF again.
            </div>
            <form method="POST" action="{{ route('sender.envelopes.retry-completion', $envelope) }}">
                @csrf
                <button class="witness-button" type="submit">Retry completion</button>
            </form>
        </div>
    @endif

    <div class="witness-editor-grid">
        <div class="witness-editor-main">
            <section class="witness-panel"
                     data-testid="signer-progress">
                <div class="witness-panel-heading">
                    <h2>Signers</h2>
                    <span>{{ $signedCount }} of {{ $envelope->signers->count() }} signed</span>
                </div>
                <ol class="witness-signers">
                    @foreach ($signers as $row)
                        <li data-testid="signer-row">
                            <div>
                                <strong>{{ $row['signer']->name }}</strong>
                                <span>{{ $row['signer']->email }}{{ $row['signer']->role_label ? ' · '.$row['signer']->role_label : '' }}</span>
                                @if ($row['declineNotified'] !== null)
                                    <span>Decline notification: {{ $row['declineNotified'] ? 'sent' : 'unknown' }}</span>
                                @endif
                            </div>
                            <div class="witness-row-meta">
                                <span class="witness-status witness-signer-{{ $row['signer']->status->value }}">{{ ucfirst($row['signer']->status->value) }}</span>
                                @if ($row['canResend'])
                                    <form method="POST" action="{{ route('sender.envelopes.signers.resend-link', [$envelope, $row['signer']]) }}">
                                        @csrf
                                        <button class="witness-button-secondary witness-button-small" type="submit">Resend link</button>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>

            <section class="witness-panel"
                     data-testid="audit-log">
                <h2>Audit trail</h2>
                <ol class="witness-timeline">
                    @foreach ($auditEvents as $event)
                        <li>
                            <strong>{{ str($event->type->value)->replace('_', ' ')->title() }}</strong>
                            <span>{{ $event->occurred_at->toDayDateTimeString() }} · {{ $event->actor_type->value }}</span>
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>

        <aside class="witness-editor-side">
            <section class="witness-panel"
                     data-testid="envelope-downloads">
                <h2>Documents</h2>
                <div class="witness-stack">
                    @if ($envelope->status === \App\Enums\EnvelopeStatus::Completed)
                        <a class="witness-button" href="{{ route('sender.envelopes.final-document', $envelope) }}">Download signed PDF</a>
                    @endif
                    <a class="witness-button-secondary" href="{{ route('sender.envelopes.document.show', $envelope) }}">Original PDF</a>
                </div>
            </section>

            @if ($unknownDeliveries->isNotEmpty() || $reissuableDeliveries->isNotEmpty())
                <section class="witness-panel"
                         data-testid="delivery-recovery">
                    <h2>Delivery</h2>
                    @foreach ($unknownDeliveries as $delivery)
                        <form method="POST" action="{{ route('sender.envelopes.deliveries.resend', [$envelope, $delivery]) }}" class="witness-stack witness-recovery" data-testid="unknown-delivery-recovery">
                            @csrf
                            <p>Delivery to {{ $delivery->recipient_name }} is unknown. It may already have arrived, so resending can create a duplicate.</p>
                            <label><input type="checkbox" name="confirm_duplicate_risk" value="1" required> I understand the duplicate-delivery risk.</label>
                            <button class="witness-button-secondary" type="submit">Send the final PDF again</button>
                        </form>
                    @endforeach
                    @foreach ($reissuableDeliveries as $delivery)
                        <form method="POST" action="{{ route('sender.envelopes.deliveries.reissue-link', [$envelope, $delivery]) }}" class="witness-stack witness-recovery" data-testid="final-link-reissue">
                            @csrf
                            <p>Send {{ $delivery->recipient_name }} a fresh link to the signed PDF.</p>
                            <button class="witness-button-secondary" type="submit">Send a fresh link</button>
                        </form>
                    @endforeach
                </section>
            @endif

            @if ($envelope->status === \App\Enums\EnvelopeStatus::Sent || $envelope->status->isDeletable())
                <section class="witness-panel witness-danger"
                         data-testid="envelope-actions">
                    <h2>Stop or remove</h2>
                    @if ($envelope->status === \App\Enums\EnvelopeStatus::Sent)
                        <details class="witness-disclosure">
                            <summary>Void this envelope</summary>
                            <form method="POST" action="{{ route('sender.envelopes.void', $envelope) }}" class="witness-stack" data-testid="void-envelope">
                                @csrf
                                <p>Voiding stops anyone else from signing. The reason is kept in the audit trail.</p>
                                <flux:textarea name="reason" label="Reason for voiding" required />
                                <flux:button type="submit" variant="danger">Void envelope</flux:button>
                            </form>
                        </details>
                    @endif
                    @if ($envelope->status->isDeletable())
                        <p>Deleting permanently removes the envelope, its signatures, its PDFs, and its audit trail. You confirm on the next page.</p>
                        <flux:button :href="route('sender.envelopes.delete', $envelope)" variant="danger" size="sm" data-testid="delete-envelope">Delete envelope</flux:button>
                    @endif
                </section>
            @endif
        </aside>
    </div>
</div>
