<div>
    <header class="witness-page-header" data-testid="template-editor">
        <div>
            <p class="witness-kicker">Template #{{ $template->getKey() }}</p>
            <h1>{{ $template->name }}</h1>
            <p>{{ $template->original_name }} &middot; {{ $template->page_count }} {{ Str::plural('page', $template->page_count) }}</p>
        </div>
        <flux:button :href="route('sender.templates.send-form', $template)" variant="primary">Use template</flux:button>
    </header>

    <div class="witness-editor-grid">
        <div class="witness-editor-main">
            <section class="witness-panel" data-testid="template-details">
                <h2>Document and defaults</h2>
                <form method="POST" action="{{ route('sender.templates.update', $template) }}" class="witness-stack">
                    @csrf
                    @method('PUT')
                    <flux:input name="name" label="Template name" :value="$template->name" required />
                    <flux:input name="title" label="Envelope title" :value="$template->title" required />
                    <flux:textarea name="message" label="Default message" rows="4">{{ $template->message }}</flux:textarea>
                    <button class="witness-button" type="submit">Save details</button>
                </form>
                <form method="POST" action="{{ route('sender.templates.document.update', $template) }}" enctype="multipart/form-data" class="witness-inline-form">
                    @csrf
                    @method('PUT')
                    <label>Replace PDF<input class="witness-file-input" type="file" name="document" accept="application/pdf,.pdf" required></label>
                    <button class="witness-button" type="submit">Replace</button>
                </form>
            </section>

            <section class="witness-panel" data-testid="template-roles">
                <h2>Ordered roles</h2>
                <p>Roles describe the document. Signers are only created when you use it.</p>
                <form method="POST" action="{{ route('sender.templates.roles', $template) }}" class="witness-stack"
                      data-repeater="roles"
                      x-data="witnessRepeater({{ Js::from($template->roles->map(fn ($role) => ['id' => $role->getKey(), 'label' => $role->label])->values()) }}, {{ Js::from(['id' => null, 'label' => '']) }})">
                    @csrf
                    @method('PUT')
                    <div data-repeater-list>
                        <template x-for="(row, index) in rows" :key="row._key">
                            <div class="witness-repeater-row witness-role-row" data-repeater-row>
                                <input type="hidden" :name="`roles[${index}][id]`" x-model="row.id">
                                <flux:input label="Role label" x-bind:name="`roles[${index}][label]`" x-model="row.label" required />
                                <div class="witness-row-actions">
                                    <flux:button type="button" size="sm" x-on:click="move(index, -1)" aria-label="Move role up">Up</flux:button>
                                    <flux:button type="button" size="sm" x-on:click="move(index, 1)" aria-label="Move role down">Down</flux:button>
                                    <flux:button type="button" size="sm" variant="danger" x-on:click="remove(index)">Remove</flux:button>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div class="witness-actions">
                        <flux:button type="button" x-on:click="add()">Add role</flux:button>
                        <flux:button type="submit" variant="primary">Save roles</flux:button>
                    </div>
                </form>
            </section>

            <section class="witness-panel" data-testid="template-field-placement">
                <h2>Role fields</h2>
                @if ($template->roles->isNotEmpty())
                    <form method="POST" action="{{ route('sender.templates.fields', $template) }}"
                          data-placement-root
                          data-pdf-url="{{ route('sender.templates.document', $template) }}"
                          x-data="witnessPlacement({{ Js::from(route('sender.templates.document', $template)) }})">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="fields_json" data-fields-input :value="serializedFields">
                        <script type="application/json" data-placement-data>@json($placementData)</script>
                        <div class="witness-palette" aria-label="Field types">
                            @foreach ($fieldTypes as $type)
                                <flux:button type="button" size="sm" data-field-type="{{ $type->value }}" x-on:click="addField('{{ $type->value }}')">
                                    {{ str($type->value)->replace('_', ' ')->title() }}
                                </flux:button>
                            @endforeach
                        </div>
                        <div class="witness-page-controls">
                            <flux:button type="button" size="sm" data-page-prev x-on:click="previousPage()" x-bind:disabled="currentPage <= 1">Previous</flux:button>
                            <span>Page <strong data-page-number x-text="currentPage">1</strong> of <span data-page-count x-text="pageCount">...</span></span>
                            <flux:button type="button" size="sm" data-page-next x-on:click="nextPage()" x-bind:disabled="currentPage >= pageCount">Next</flux:button>
                        </div>
                        <div class="witness-pdf-stage" data-pdf-stage x-ref="stage">
                            <canvas data-testid="pdf-page-canvas" x-ref="canvas"></canvas>
                            <div class="witness-field-layer" data-field-layer x-ref="layer" x-bind:class="{ 'is-loading': loading }"
                                 x-on:pointermove.window="moveField($event)" x-on:pointerup.window="stopField()">
                                <template x-for="field in visibleFields" :key="field._key">
                                    <div class="witness-field" data-testid="placed-field" x-bind:style="fieldStyle(field)"
                                         x-on:pointerdown="startField($event, field)" x-init="observeField($el, field)">
                                        <label x-text="field.type.replace('_', ' ')"></label>
                                        <select x-model.number="field.assignee_id" x-on:change="persist()" x-on:pointerdown.stop x-bind:aria-label="`Assign ${field.type}`">
                                            <template x-for="assignee in assignees" :key="assignee.id">
                                                <option x-bind:value="Number(assignee.id)" x-text="assignee.name"></option>
                                            </template>
                                        </select>
                                        <button type="button" aria-label="Remove field" x-on:pointerdown.stop x-on:click="removeField(field._key)">&times;</button>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <button class="witness-button" type="submit">Save placements</button>
                    </form>
                @else
                    <p>Add roles before placing fields.</p>
                @endif
            </section>
        </div>

        <aside class="witness-panel witness-review" data-testid="template-review">
            <p class="witness-kicker">Template summary</p>
            <h2>{{ $template->roles->count() }} roles</h2>
            <p>{{ $template->fields->count() }} reusable fields across {{ $template->page_count }} {{ Str::plural('page', $template->page_count) }}.</p>
            <flux:button :href="route('sender.templates.send-form', $template)" variant="primary" class="witness-button-full">Map signers</flux:button>
        </aside>
    </div>
</div>
