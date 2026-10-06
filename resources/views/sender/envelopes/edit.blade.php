@push('scripts')
    <script type="module" src="{{ asset('build/assets/sender.js') }}"></script>
@endpush

<x-sender-layout :title="$envelope->title">
    <header class="witness-page-header" data-testid="envelope-editor">
        <div>
            <p class="witness-kicker">Envelope #{{ $envelope->getKey() }}</p>
            <h1>{{ $envelope->title }}</h1>
            <p>{{ $envelope->document?->original_name }} · {{ $envelope->document?->page_count }} pages</p>
        </div>
        <span class="witness-status witness-status-{{ $envelope->status->value }}">{{ ucfirst($envelope->status->value) }}</span>
    </header>

    <div class="witness-editor-grid">
        <div class="witness-editor-main">
            <section class="witness-panel" data-testid="envelope-details">
                <p class="witness-kicker">Step 1</p><h2>Document and details</h2>
                <form method="POST" action="{{ route('sender.envelopes.update', $envelope) }}" class="witness-stack">
                    @csrf @method('PUT')
                    <label>Title<input name="title" value="{{ old('title', $envelope->title) }}" required @disabled(!$envelope->status->isDraft())></label>
                    <label>Message<textarea name="message" rows="4" @disabled(!$envelope->status->isDraft())>{{ old('message', $envelope->message) }}</textarea></label>
                    <label>Expires on<input type="date" name="expires_at" value="{{ old('expires_at', $envelope->expires_at?->format('Y-m-d')) }}" @disabled(!$envelope->status->isDraft())></label>
                    @if ($envelope->status->isDraft())<button class="witness-button-secondary" type="submit">Save details</button>@endif
                </form>
                @if ($envelope->status->isDraft())
                    <form method="POST" action="{{ route('sender.envelopes.document', $envelope) }}" enctype="multipart/form-data" class="witness-inline-form">
                        @csrf @method('PUT')
                        <label>Replace PDF<input type="file" name="document" accept="application/pdf,.pdf" required></label>
                        <button class="witness-button-secondary" type="submit">Replace</button>
                    </form>
                @endif
            </section>

            <section class="witness-panel" data-testid="signer-editor">
                <p class="witness-kicker">Step 2</p><h2>Signers</h2>
                @if ($envelope->status->isDraft())
                    <form method="POST" action="{{ route('sender.envelopes.signers', $envelope) }}" class="witness-stack" data-repeater="signers">
                        @csrf @method('PUT')
                        <div data-repeater-list>
                            @foreach ($envelope->signers as $index => $signer)
                                <div class="witness-repeater-row" data-repeater-row>
                                    <input type="hidden" data-name="id" value="{{ $signer->getKey() }}">
                                    <label>Name<input data-name="name" value="{{ $signer->name }}" required></label>
                                    <label>Email<input type="email" data-name="email" value="{{ $signer->email }}" required></label>
                                    <label>Role<input data-name="role_label" value="{{ $signer->role_label }}" placeholder="Client"></label>
                                    <div class="witness-row-actions"><button type="button" data-move-up aria-label="Move signer up">↑</button><button type="button" data-move-down aria-label="Move signer down">↓</button><button type="button" data-remove-row>Remove</button></div>
                                </div>
                            @endforeach
                        </div>
                        <template data-repeater-template><div class="witness-repeater-row" data-repeater-row><input type="hidden" data-name="id"><label>Name<input data-name="name" required></label><label>Email<input type="email" data-name="email" required></label><label>Role<input data-name="role_label" placeholder="Client"></label><div class="witness-row-actions"><button type="button" data-move-up aria-label="Move signer up">↑</button><button type="button" data-move-down aria-label="Move signer down">↓</button><button type="button" data-remove-row>Remove</button></div></div></template>
                        <div class="witness-actions"><button type="button" class="witness-button-secondary" data-add-row>Add signer</button><button class="witness-button-secondary" type="submit">Save signers</button></div>
                    </form>
                @else
                    <ol class="witness-summary-list">@foreach ($envelope->signers as $signer)<li><strong>{{ $signer->name }}</strong><span>{{ $signer->email }}</span></li>@endforeach</ol>
                @endif
            </section>

            <section class="witness-panel" data-testid="field-placement">
                <p class="witness-kicker">Step 3</p><h2>Place fields</h2>
                @if ($envelope->status->isDraft() && $envelope->signers->isNotEmpty())
                    <form method="POST" action="{{ route('sender.envelopes.fields', $envelope) }}" data-placement-root data-pdf-url="{{ route('sender.envelopes.document.show', $envelope) }}">
                        @csrf @method('PUT')
                        <input type="hidden" name="fields_json" data-fields-input>
                        <script type="application/json" data-placement-data>@json($placementData)</script>
                        <div class="witness-palette" aria-label="Field types">@foreach ($fieldTypes as $type)<button type="button" data-field-type="{{ $type->value }}">{{ str($type->value)->replace('_', ' ')->title() }}</button>@endforeach</div>
                        <div class="witness-page-controls"><button type="button" data-page-prev>Previous</button><span>Page <strong data-page-number>1</strong> of <span data-page-count>…</span></span><button type="button" data-page-next>Next</button></div>
                        <div class="witness-pdf-stage" data-pdf-stage><canvas data-testid="pdf-page-canvas"></canvas><div class="witness-field-layer" data-field-layer></div></div>
                        <button class="witness-button-secondary" type="submit" data-testid="save-fields">Save placements</button>
                    </form>
                @elseif ($envelope->signers->isEmpty())
                    <p>Add a signer before placing fields.</p>
                @else
                    <p>{{ $envelope->fields->count() }} field placements are frozen.</p>
                @endif
            </section>
        </div>

        <aside class="witness-panel witness-review" data-testid="envelope-review">
            <p class="witness-kicker">Step 4</p><h2>Review</h2>
            <dl><div><dt>Document</dt><dd>{{ $envelope->document?->original_name }}</dd></div><div><dt>Signers</dt><dd>{{ $envelope->signers->count() }}</dd></div><div><dt>Fields</dt><dd>{{ $envelope->fields->count() }}</dd></div><div><dt>Expiry</dt><dd>{{ $envelope->expires_at?->toFormattedDateString() ?? 'No expiry' }}</dd></div></dl>
            @if ($envelope->status->isDraft())
                <form method="POST" action="{{ route('sender.envelopes.send', $envelope) }}">@csrf<button class="witness-button witness-button-full" type="submit" data-testid="send-envelope">Send and freeze</button></form>
                <form method="POST" action="{{ route('sender.envelopes.template', $envelope) }}" class="witness-stack">@csrf<label>Template name<input name="name" required></label><button class="witness-button-secondary" type="submit">Save as template</button></form>
            @else
                <p class="witness-frozen">Sent {{ $envelope->sent_at?->diffForHumans() }}. Document and fields are locked.</p>
            @endif
        </aside>
    </div>
</x-sender-layout>
