@push('scripts')
    <script type="module" src="{{ asset('build/assets/sender.js') }}"></script>
@endpush

<x-sender-layout :title="$template->name">
    <header class="witness-page-header" data-testid="template-editor">
        <div><p class="witness-kicker">Template #{{ $template->getKey() }}</p><h1>{{ $template->name }}</h1><p>{{ $template->original_name }} · {{ $template->page_count }} pages</p></div>
        <a class="witness-button" href="{{ route('sender.templates.send-form', $template) }}">Use template</a>
    </header>
    <div class="witness-editor-grid">
        <div class="witness-editor-main">
            <section class="witness-panel" data-testid="template-details">
                <h2>Document and defaults</h2>
                <form method="POST" action="{{ route('sender.templates.update', $template) }}" class="witness-stack">@csrf @method('PUT')<label>Template name<input name="name" value="{{ $template->name }}" required></label><label>Envelope title<input name="title" value="{{ $template->title }}" required></label><label>Default message<textarea name="message" rows="4">{{ $template->message }}</textarea></label><button class="witness-button-secondary" type="submit">Save details</button></form>
                <form method="POST" action="{{ route('sender.templates.document.update', $template) }}" enctype="multipart/form-data" class="witness-inline-form">@csrf @method('PUT')<label>Replace PDF<input type="file" name="document" accept="application/pdf,.pdf" required></label><button class="witness-button-secondary" type="submit">Replace</button></form>
            </section>
            <section class="witness-panel" data-testid="template-roles">
                <h2>Ordered roles</h2><p>Roles describe the document. Signers are only created when you use it.</p>
                <form method="POST" action="{{ route('sender.templates.roles', $template) }}" class="witness-stack" data-repeater="roles">@csrf @method('PUT')
                    <div data-repeater-list>@foreach ($template->roles as $role)<div class="witness-repeater-row witness-role-row" data-repeater-row><input type="hidden" data-name="id" value="{{ $role->getKey() }}"><label>Role label<input data-name="label" value="{{ $role->label }}" required></label><div class="witness-row-actions"><button type="button" data-move-up>↑</button><button type="button" data-move-down>↓</button><button type="button" data-remove-row>Remove</button></div></div>@endforeach</div>
                    <template data-repeater-template><div class="witness-repeater-row witness-role-row" data-repeater-row><input type="hidden" data-name="id"><label>Role label<input data-name="label" placeholder="Client" required></label><div class="witness-row-actions"><button type="button" data-move-up>↑</button><button type="button" data-move-down>↓</button><button type="button" data-remove-row>Remove</button></div></div></template>
                    <div class="witness-actions"><button type="button" class="witness-button-secondary" data-add-row>Add role</button><button class="witness-button-secondary" type="submit">Save roles</button></div>
                </form>
            </section>
            <section class="witness-panel" data-testid="template-field-placement">
                <h2>Role fields</h2>
                @if ($template->roles->isNotEmpty())
                    <form method="POST" action="{{ route('sender.templates.fields', $template) }}" data-placement-root data-pdf-url="{{ route('sender.templates.document', $template) }}">@csrf @method('PUT')
                        <input type="hidden" name="fields_json" data-fields-input>
                        <script type="application/json" data-placement-data>@json($placementData)</script>
                        <div class="witness-palette">@foreach ($fieldTypes as $type)<button type="button" data-field-type="{{ $type->value }}">{{ str($type->value)->replace('_', ' ')->title() }}</button>@endforeach</div>
                        <div class="witness-page-controls"><button type="button" data-page-prev>Previous</button><span>Page <strong data-page-number>1</strong> of <span data-page-count>…</span></span><button type="button" data-page-next>Next</button></div>
                        <div class="witness-pdf-stage" data-pdf-stage><canvas data-testid="pdf-page-canvas"></canvas><div class="witness-field-layer" data-field-layer></div></div>
                        <button class="witness-button-secondary" type="submit">Save placements</button>
                    </form>
                @else<p>Add roles before placing fields.</p>@endif
            </section>
        </div>
        <aside class="witness-panel witness-review"><p class="witness-kicker">Template summary</p><h2>{{ $template->roles->count() }} roles</h2><p>{{ $template->fields->count() }} reusable fields across {{ $template->page_count }} pages.</p><a class="witness-button witness-button-full" href="{{ route('sender.templates.send-form', $template) }}">Map signers</a></aside>
    </div>
</x-sender-layout>
