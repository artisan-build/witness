@push('scripts')
    <script type="module" src="{{ asset('build/assets/signing.js') }}"></script>
@endpush

<x-signing-layout :title="$signer->envelope->title">
    <header class="signing-heading" data-testid="signer-document-header">
        <div>
            <p class="signing-kicker">Document for {{ $signer->name }}</p>
            <h1>{{ $signer->envelope->title }}</h1>
            <p>From {{ $signer->envelope->sender->name }}</p>
        </div>
        <span>{{ $signer->fields->where('completed_at', '!=', null)->count() }} / {{ $signer->fields->count() }} fields saved</span>
    </header>

    @if (session('status'))
        <div class="signing-notice" role="status">{{ session('status') }}</div>
    @endif

    <section class="signing-card" data-testid="signer-pdf-viewer" data-pdf-url="{{ route('sign.document') }}">
        <div class="signing-page-controls">
            <button type="button" data-page-prev>Previous</button>
            <span>Page <strong data-page-number>1</strong> of <span data-page-count>...</span></span>
            <button type="button" data-page-next>Next</button>
        </div>
        <div class="signing-pdf-stage"><canvas data-testid="signer-pdf-canvas"></canvas></div>
    </section>

    <form method="POST" action="{{ route('sign.fields') }}" class="signing-fields" data-testid="signer-fields">
        @csrf
        @foreach ($signer->fields->sortBy(['page', 'id']) as $field)
            <fieldset class="signing-card signing-field" data-testid="signer-field" data-field-type="{{ $field->type->value }}">
                <legend>{{ str($field->type->value)->replace('_', ' ')->title() }} · page {{ $field->page }} @if($field->required) <span>Required</span> @endif</legend>

                @if ($field->type === \App\Enums\FieldType::Text)
                    <label for="field-{{ $field->getKey() }}">Text</label>
                    <input id="field-{{ $field->getKey() }}" name="fields[{{ $field->getKey() }}][text]" maxlength="2000" value="{{ $field->value['value'] ?? '' }}" @required($field->required)>
                @elseif ($field->type === \App\Enums\FieldType::Checkbox)
                    <label class="signing-check"><input type="checkbox" name="fields[{{ $field->getKey() }}][checked]" value="1" @checked($field->value['checked'] ?? false)> I confirm</label>
                @elseif ($field->type === \App\Enums\FieldType::DateSigned)
                    <p data-testid="date-signed-value">Witness fills this with today's UTC date when you save.</p>
                @else
                    <div class="signing-signature-choice" data-signature-control>
                        <label><input type="radio" name="fields[{{ $field->getKey() }}][kind]" value="typed" @checked(($field->value['kind'] ?? 'typed') === 'typed')> Type</label>
                        <label><input type="radio" name="fields[{{ $field->getKey() }}][kind]" value="drawn" @checked(($field->value['kind'] ?? null) === 'drawn')> Draw</label>
                        <label>Typed {{ $field->type->value }}
                            <input class="signing-script" name="fields[{{ $field->getKey() }}][typed]" maxlength="100" value="{{ $field->value['text'] ?? '' }}">
                        </label>
                        <div class="signing-draw">
                            <canvas width="720" height="220" data-signature-canvas aria-label="Draw {{ $field->type->value }}"></canvas>
                            <input type="hidden" name="fields[{{ $field->getKey() }}][drawn]" data-signature-value>
                            <button type="button" data-signature-clear>Clear drawing</button>
                        </div>
                    </div>
                @endif
            </fieldset>
        @endforeach
        <button class="signing-button" type="submit" data-testid="save-signer-fields">Save responses</button>
    </form>

    <section class="signing-card signing-final" data-testid="signer-intent">
        <h2>Finish signing</h2>
        <form method="POST" action="{{ route('sign.complete') }}" class="signing-stack">
            @csrf
            <label class="signing-check"><input type="checkbox" name="intent" value="1" required> I have reviewed this document and intend to adopt my saved signature or initials and sign electronically.</label>
            <button class="signing-button" type="submit">Sign document</button>
        </form>
        <details data-testid="signer-decline">
            <summary>Decline to sign</summary>
            <form method="POST" action="{{ route('sign.decline') }}" class="signing-stack">
                @csrf
                <label for="reason">Reason</label>
                <textarea id="reason" name="reason" rows="4" maxlength="2000" required></textarea>
                <button class="signing-button signing-button-danger" type="submit">Decline and notify sender</button>
            </form>
        </details>
    </section>
</x-signing-layout>
