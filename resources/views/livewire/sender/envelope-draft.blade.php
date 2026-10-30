<div data-testid="envelope-editor"
     data-page="draft">
    <header class="witness-page-header witness-page-header-compact">
        <div>
            <p class="witness-kicker">Draft</p>
            <h1>{{ $envelope->title }}</h1>
            <p>{{ $envelope->document?->original_name }} &middot; {{ $envelope->document?->page_count }} {{ Str::plural('page', $envelope->document?->page_count) }}</p>
        </div>
        <a class="witness-button-secondary"
           href="{{ route('sender.envelopes.document.show', $envelope) }}">
            View PDF
        </a>
    </header>

    <nav class="witness-steps"
         aria-label="Steps"
         data-testid="envelope-steps">
        @foreach ($steps as $index => $item)
            <a href="{{ route('sender.envelopes.edit', ['envelope' => $envelope, 'step' => $item['step']->value]) }}"
               @class(['witness-step', 'is-current' => $item['current'], 'is-done' => $item['done']])
               @if ($item['current']) aria-current="step" @endif>
                <span class="witness-step-marker">{{ $item['done'] && ! $item['current'] ? '✓' : $index + 1 }}</span>
                <span>{{ $item['step']->label() }}</span>
            </a>
        @endforeach
    </nav>

    @switch ($step)
        @case (\App\Enums\EnvelopeSteps::Details)
            <section class="witness-panel"
                     data-testid="envelope-details">
                <h2>Document and message</h2>
                <p>Signers see the title and message in their invitation email.</p>
                <form method="POST" action="{{ route('sender.envelopes.update', $envelope) }}" class="witness-stack">
                    @csrf
                    @method('PUT')
                    <flux:input name="title" label="Title" :value="old('title', $envelope->title)" required />
                    <flux:textarea name="message" label="Message to signers (optional)" rows="4">{{ old('message', $envelope->message) }}</flux:textarea>
                    <div class="witness-two-up">
                        <flux:input type="date" name="expires_at" label="Expires on (optional)" :value="old('expires_at', $envelope->expires_at?->format('Y-m-d'))" />
                        <flux:input type="number" min="1" max="30" name="reminder_interval_days" label="Remind every N days (optional)" :value="old('reminder_interval_days', $envelope->reminder_interval_days)" />
                    </div>
                    <div class="witness-actions">
                        <button class="witness-button-secondary" type="submit">Save details</button>
                        <button class="witness-button" type="submit" name="next" value="signers" data-testid="details-continue">Save and add signers</button>
                    </div>
                </form>
                <details class="witness-disclosure">
                    <summary>Replace the PDF</summary>
                    <p>Replacing the PDF removes every field you have placed.</p>
                    <form method="POST" action="{{ route('sender.envelopes.document', $envelope) }}" enctype="multipart/form-data" class="witness-inline-form">
                        @csrf
                        @method('PUT')
                        <label>New PDF<input class="witness-file-input" type="file" name="document" accept="application/pdf,.pdf" required></label>
                        <button class="witness-button-secondary" type="submit">Replace</button>
                    </form>
                </details>
            </section>
            @break

        @case (\App\Enums\EnvelopeSteps::Signers)
            <section class="witness-panel"
                     data-testid="signer-editor">
                <h2>Who needs to sign?</h2>
                <p>Everyone signs in parallel. Each person gets their own link and verification code by email.</p>
                <form method="POST" action="{{ route('sender.envelopes.signers', $envelope) }}" class="witness-stack"
                      data-repeater="signers"
                      x-data="witnessRepeater({{ Js::from($envelope->signers->map(fn ($signer) => ['id' => $signer->getKey(), 'name' => $signer->name, 'email' => $signer->email, 'role_label' => $signer->role_label])->values()) }}, {{ Js::from(['id' => null, 'name' => '', 'email' => '', 'role_label' => '']) }})">
                    @csrf
                    @method('PUT')
                    <div data-repeater-list>
                        <template x-for="(row, index) in rows" :key="row._key">
                            <div class="witness-repeater-row" data-repeater-row>
                                <input type="hidden" :name="`signers[${index}][id]`" x-model="row.id">
                                <flux:input label="Name" x-bind:name="`signers[${index}][name]`" x-model="row.name" required />
                                <flux:input type="email" label="Email" x-bind:name="`signers[${index}][email]`" x-model="row.email" required />
                                <flux:input label="Role" x-bind:name="`signers[${index}][role_label]`" x-model="row.role_label" placeholder="Client" />
                                <div class="witness-row-actions">
                                    <flux:button type="button" size="sm" x-on:click="move(index, -1)" aria-label="Move signer up">Up</flux:button>
                                    <flux:button type="button" size="sm" x-on:click="move(index, 1)" aria-label="Move signer down">Down</flux:button>
                                    <flux:button type="button" size="sm" variant="danger" x-on:click="remove(index)">Remove</flux:button>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div class="witness-actions">
                        <button class="witness-button-secondary" type="button" x-on:click="add()">Add signer</button>
                        <span class="witness-actions-end">
                            <button class="witness-button-secondary" type="submit">Save signers</button>
                            <button class="witness-button" type="submit" name="next" value="fields" data-testid="signers-continue">Save and place fields</button>
                        </span>
                    </div>
                </form>
            </section>
            @break

        @case (\App\Enums\EnvelopeSteps::Fields)
            <section class="witness-panel witness-panel-wide"
                     data-testid="field-placement">
                <h2>Place fields</h2>
                @if ($envelope->signers->isEmpty())
                    <p>Add at least one signer first, so each field has someone to fill it in.</p>
                    <a class="witness-button" href="{{ route('sender.envelopes.edit', ['envelope' => $envelope, 'step' => 'signers']) }}">Add signers</a>
                @else
                    <p>Pick a field type to add it to this page, drag it into place, and choose who fills it in.</p>
                <form method="POST" action="{{ route('sender.envelopes.fields', $envelope) }}"
                      data-placement-root
                      data-pdf-url="{{ route('sender.envelopes.document.show', $envelope) }}"
                      x-data="witnessPlacement({{ Js::from(route('sender.envelopes.document.show', $envelope)) }})">
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
                    <div class="witness-actions">
                        <a class="witness-button-secondary" href="{{ route('sender.envelopes.edit', ['envelope' => $envelope, 'step' => 'signers']) }}">Back to signers</a>
                        <span class="witness-actions-end">
                            <button class="witness-button-secondary" type="submit" data-testid="save-fields">Save placements</button>
                            <button class="witness-button" type="submit" name="next" value="review" data-testid="fields-continue">Save and review</button>
                        </span>
                    </div>
                </form>
                @endif
            </section>
            @break

        @default
            <section class="witness-panel witness-review"
                     data-testid="envelope-review">
                <h2>Review and send</h2>
                <p>Once sent, the document, signers, and fields are locked.</p>
                <dl>
                    <div><dt>Document</dt><dd>{{ $envelope->document?->original_name }}</dd></div>
                    <div><dt>Expires</dt><dd>{{ $envelope->expires_at?->toFormattedDateString() ?? 'Never' }}</dd></div>
                    <div><dt>Reminders</dt><dd>{{ $envelope->reminder_interval_days ? 'Every '.$envelope->reminder_interval_days.' days' : 'Off' }}</dd></div>
                </dl>
                <ul class="witness-signer-checks"
                    data-testid="review-signers">
                    @forelse ($envelope->signers as $signer)
                        <li @class(['is-missing' => $fieldsBySigner[$signer->getKey()] === 0])>
                            <strong>{{ $signer->name }}</strong>
                            <span>{{ $signer->email }}</span>
                            <span>{{ $fieldsBySigner[$signer->getKey()] === 0 ? 'No fields yet' : $fieldsBySigner[$signer->getKey()].' '.Str::plural('field', $fieldsBySigner[$signer->getKey()]) }}</span>
                        </li>
                    @empty
                        <li class="is-missing"><strong>No signers yet</strong></li>
                    @endforelse
                </ul>
                <form method="POST" action="{{ route('sender.envelopes.send', $envelope) }}">
                    @csrf
                    <button class="witness-button witness-button-full" type="submit" data-testid="send-envelope">Send for signature</button>
                </form>
                <details class="witness-disclosure">
                    <summary>Save as a template instead</summary>
                    <form method="POST" action="{{ route('sender.envelopes.template', $envelope) }}" class="witness-stack">
                        @csrf
                        <flux:input name="name" label="Template name" required />
                        <button class="witness-button-secondary" type="submit">Save as template</button>
                    </form>
                </details>
            </section>
    @endswitch
</div>
