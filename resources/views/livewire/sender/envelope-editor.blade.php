<div>
    <header class="witness-page-header" data-testid="envelope-editor">
        <div>
            <p class="witness-kicker">Envelope #{{ $envelope->getKey() }}</p>
            <h1>{{ $envelope->title }}</h1>
            <p>{{ $envelope->document?->original_name }} &middot; {{ $envelope->document?->page_count }} {{ Str::plural('page', $envelope->document?->page_count) }}</p>
        </div>
        <span class="witness-status witness-status-{{ $envelope->status->value }}">{{ ucfirst($envelope->status->value) }}</span>
    </header>

    <div class="witness-editor-grid">
        <div class="witness-editor-main">
            <section class="witness-panel" data-testid="envelope-details">
                <p class="witness-kicker">Step 1</p>
                <h2>Document and details</h2>
                <form method="POST" action="{{ route('sender.envelopes.update', $envelope) }}" class="witness-stack">
                    @csrf
                    @method('PUT')
                    <flux:input name="title" label="Title" :value="old('title', $envelope->title)" required :disabled="!$envelope->status->isDraft()" />
                    <flux:textarea name="message" label="Message" rows="4" :disabled="!$envelope->status->isDraft()">{{ old('message', $envelope->message) }}</flux:textarea>
                    <flux:input type="date" name="expires_at" label="Expires on" :value="old('expires_at', $envelope->expires_at?->format('Y-m-d'))" :disabled="!$envelope->status->isDraft()" />
                    <flux:input type="number" min="1" max="30" name="reminder_interval_days" label="Automatic reminder interval in days (leave blank for off)" :value="old('reminder_interval_days', $envelope->reminder_interval_days)" :disabled="!$envelope->status->isDraft()" />
                    @if ($envelope->status->isDraft())
                        <button class="witness-button" type="submit">Save details</button>
                    @endif
                </form>
                @if ($envelope->status->isDraft())
                    <form method="POST" action="{{ route('sender.envelopes.document', $envelope) }}" enctype="multipart/form-data" class="witness-inline-form">
                        @csrf
                        @method('PUT')
                        <label>Replace PDF<input class="witness-file-input" type="file" name="document" accept="application/pdf,.pdf" required></label>
                        <button class="witness-button" type="submit">Replace</button>
                    </form>
                @endif
            </section>

            <section class="witness-panel" data-testid="signer-editor">
                <p class="witness-kicker">Step 2</p>
                <h2>Signers</h2>
                @if ($envelope->status->isDraft())
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
                            <button class="witness-button" type="button" x-on:click="add()">Add signer</button>
                            <button class="witness-button" type="submit">Save signers</button>
                        </div>
                    </form>
                @else
                    <ol class="witness-summary-list">
                        @foreach ($envelope->signers as $signer)
                            <li>
                                <strong>{{ $signer->name }}</strong>
                                <span>{{ $signer->email }}</span>
                                <span>Status: {{ $signer->status->value }}</span>
                                @if ($signer->status === \App\Enums\SignerStatus::Declined)
                                    @php
                                        $declineDelivery = $signer->mailDeliveries
                                            ->where('purpose', \App\Enums\MailDeliveryPurpose::DeclineNotification)
                                            ->sortByDesc('generation')
                                            ->first();
                                    @endphp
                                    <span>Decline notification: {{ $declineDelivery?->status === \App\Enums\MailDeliveryStatus::Sent ? 'sent' : 'unknown' }}</span>
                                @elseif ($envelope->status === \App\Enums\EnvelopeStatus::Sent
                                    && ! in_array($signer->status, [\App\Enums\SignerStatus::Signed, \App\Enums\SignerStatus::Declined], true))
                                    <form method="POST" action="{{ route('sender.envelopes.signers.resend-link', [$envelope, $signer]) }}">
                                        @csrf
                                        <button class="witness-button witness-button-small" type="submit">Resend link</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            <section class="witness-panel" data-testid="field-placement">
                <p class="witness-kicker">Step 3</p>
                <h2>Place fields</h2>
                @if ($envelope->status->isDraft() && $envelope->signers->isNotEmpty())
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
                        <button class="witness-button" type="submit" data-testid="save-fields">Save placements</button>
                    </form>
                @elseif ($envelope->signers->isEmpty())
                    <p>Add a signer before placing fields.</p>
                @else
                    <p>{{ $envelope->fields->count() }} field placements are frozen.</p>
                @endif
            </section>
        </div>

        <aside class="witness-panel witness-review" data-testid="envelope-review">
            <p class="witness-kicker">Step 4</p>
            <h2>Review</h2>
            <dl>
                <div><dt>Document</dt><dd>{{ $envelope->document?->original_name }}</dd></div>
                <div><dt>Signers</dt><dd>{{ $envelope->signers->count() }}</dd></div>
                <div><dt>Fields</dt><dd>{{ $envelope->fields->count() }}</dd></div>
                <div><dt>Expiry</dt><dd>{{ $envelope->expires_at?->toFormattedDateString() ?? 'No expiry' }}</dd></div>
            </dl>
            @if ($envelope->status->isDraft())
                <form method="POST" action="{{ route('sender.envelopes.send', $envelope) }}">
                    @csrf
                    <button class="witness-button witness-button-full" type="submit" data-testid="send-envelope">Send and freeze</button>
                </form>
                <form method="POST" action="{{ route('sender.envelopes.template', $envelope) }}" class="witness-stack">
                    @csrf
                    <flux:input name="name" label="Template name" required />
                    <button class="witness-button" type="submit">Save as template</button>
                </form>
            @else
                <p class="witness-frozen">Sent {{ $envelope->sent_at?->diffForHumans() }}. Document and fields are locked.</p>
            @endif

            <div class="witness-actions" data-testid="envelope-downloads">
                <a class="witness-button witness-button-small" href="{{ route('sender.envelopes.document.show', $envelope) }}">Original PDF</a>
                @if ($envelope->status === \App\Enums\EnvelopeStatus::Completed)
                    <a class="witness-button witness-button-small" href="{{ route('sender.envelopes.final-document', $envelope) }}">Final PDF</a>
                @endif
            </div>

            @if ($envelope->status === \App\Enums\EnvelopeStatus::Sent)
                <form method="POST" action="{{ route('sender.envelopes.void', $envelope) }}" class="witness-stack" data-testid="void-envelope">
                    @csrf
                    <flux:textarea name="reason" label="Reason for voiding" required />
                    <flux:button type="submit" variant="danger">Void envelope</flux:button>
                </form>
            @elseif ($envelope->status === \App\Enums\EnvelopeStatus::CompletionFailed)
                <div data-testid="completion-failed"><strong>Completion failed.</strong> Your signatures and audit evidence are preserved.</div>
                <form method="POST" action="{{ route('sender.envelopes.retry-completion', $envelope) }}">@csrf
                    <button class="witness-button" type="submit">Retry completion</button>
                </form>
            @endif

            @foreach ($envelope->mailDeliveries->where('status', \App\Enums\MailDeliveryStatus::Unknown)->where('purpose', \App\Enums\MailDeliveryPurpose::FinalDocument) as $delivery)
                <form method="POST" action="{{ route('sender.envelopes.deliveries.resend', [$envelope, $delivery]) }}" class="witness-stack witness-recovery" data-testid="unknown-delivery-recovery">
                    @csrf
                    <p>Delivery to {{ $delivery->recipient_name }} is unknown. It may already have succeeded; resending can create a duplicate.</p>
                    <label><input type="checkbox" name="confirm_duplicate_risk" value="1" required> I understand the duplicate-delivery risk.</label>
                    <button class="witness-button" type="submit">Create a new delivery generation</button>
                </form>
            @endforeach

            @foreach ($envelope->mailDeliveries
                ->where('purpose', \App\Enums\MailDeliveryPurpose::FinalDocument)
                ->groupBy(fn ($delivery) => $delivery->recipient_kind.'|'.$delivery->recipient_email)
                ->map(fn ($deliveries) => $deliveries->sortByDesc('generation')->first())
                ->where('status', \App\Enums\MailDeliveryStatus::Sent) as $delivery)
                <form method="POST" action="{{ route('sender.envelopes.deliveries.reissue-link', [$envelope, $delivery]) }}" class="witness-stack witness-recovery" data-testid="final-link-reissue">
                    @csrf
                    <p>Issue {{ $delivery->recipient_name }} a fresh final-document link without creating another final PDF.</p>
                    <button class="witness-button" type="submit">Issue fresh final-document link</button>
                </form>
            @endforeach

            @if ($envelope->status->isDeletable())
                <flux:button :href="route('sender.envelopes.delete', $envelope)" variant="danger" size="sm" data-testid="delete-envelope">Delete envelope</flux:button>
            @endif
        </aside>
    </div>

    <section class="witness-panel" data-testid="audit-log">
        <h2>Audit log</h2>
        <ol class="witness-summary-list">
            @foreach ($envelope->auditEvents as $event)
                <li><strong>{{ str($event->type->value)->replace('_', ' ')->title() }}</strong> <span>{{ $event->occurred_at->toDayDateTimeString() }} · {{ $event->actor_type->value }}</span></li>
            @endforeach
        </ol>
    </section>
</div>
