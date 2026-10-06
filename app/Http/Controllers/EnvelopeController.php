<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateDraftEnvelope;
use App\Actions\PurgeEnvelope;
use App\Actions\RetryEnvelopeCompletion;
use App\Actions\RotateSigningInvitation;
use App\Actions\SendEnvelope;
use App\Actions\VoidEnvelope;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Models\Envelope;
use App\Models\Template;
use App\Rules\SupportedPdfText;
use App\Services\EnvelopeCompletionService;
use App\Services\PdfDocumentService;
use App\Support\SelectedTeam;
use ArtisanBuild\BuiltForCloud\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class EnvelopeController
{
    public function __construct(
        private SelectedTeam $selectedTeam,
        private PdfDocumentService $pdfs,
        private CreateDraftEnvelope $createDraft,
        private SendEnvelope $sendEnvelope,
        private RotateSigningInvitation $rotateSigningInvitation,
        private VoidEnvelope $voidEnvelope,
        private RetryEnvelopeCompletion $retryCompletion,
        private PurgeEnvelope $purgeEnvelope,
        private EnvelopeCompletionService $completion,
    ) {}

    public function index(Request $request): View
    {
        $user = $this->user($request);
        $status = $request->string('status')->toString();
        $envelopes = $this->baseQuery($user)
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->with(['signers' => fn ($query) => $query->orderBy('position')])
            ->latest()
            ->get();

        return view('sender.dashboard', compact('envelopes', 'status'));
    }

    public function create(): View
    {
        return view('sender.envelopes.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255', new SupportedPdfText],
            'document' => ['required', 'file', 'max:20480'],
        ]);
        $pdf = $this->pdfs->inspect($data['document']);
        $path = $this->pdfs->store($pdf, (int) $this->selectedTeam->get()->getKey());

        try {
            $envelope = $this->createDraft->handle(
                team: $this->selectedTeam->get(),
                sender: $user,
                title: $data['title'],
                originalName: $pdf['name'],
                storagePath: $path,
                sizeBytes: $pdf['size'],
                pageCount: $pdf['pages'],
                originalSha256: $pdf['sha256'],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (Throwable $exception) {
            $this->pdfs->deleteOrQueue($path);
            throw $exception;
        }

        return to_route('sender.envelopes.edit', $envelope)->with('status', 'Draft created. Add signers and fields.');
    }

    public function edit(Request $request, int $envelope): View
    {
        $record = $this->findEnvelope($this->user($request), $envelope);

        return view('sender.envelopes.edit', ['envelope' => $record]);
    }

    public function update(Request $request, int $envelope): RedirectResponse
    {
        $this->findDraft($this->user($request), $envelope);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255', new SupportedPdfText],
            'message' => ['nullable', 'string', 'max:5000'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'reminder_interval_days' => ['nullable', 'integer', 'between:1,30'],
        ]);
        DB::transaction(function () use ($envelope, $data): void {
            $this->lockDraft($envelope)->update($data);
        });

        return back()->with('status', 'Envelope details saved.');
    }

    public function replaceDocument(Request $request, int $envelope): RedirectResponse
    {
        $record = $this->findDraft($this->user($request), $envelope, ['document']);
        abort_if($record->document === null, 409, 'The draft has no document to replace.');
        $data = $request->validate(['document' => ['required', 'file', 'max:20480']]);
        $pdf = $this->pdfs->inspect($data['document']);
        $newPath = $this->pdfs->store($pdf, (int) $this->selectedTeam->get()->getKey());

        try {
            $oldPath = DB::transaction(function () use ($envelope, $pdf, $newPath): string {
                $locked = $this->lockDraft($envelope);
                $document = $locked->document()->first();
                abort_if($document === null, 409, 'The draft has no document to replace.');
                $displacedPath = $document->storage_path;
                $locked->fields()->delete();
                $document->update([
                    'original_name' => $pdf['name'],
                    'storage_path' => $newPath,
                    'size_bytes' => $pdf['size'],
                    'page_count' => $pdf['pages'],
                    'original_sha256' => $pdf['sha256'],
                ]);

                return $displacedPath;
            });
        } catch (Throwable $exception) {
            $this->pdfs->deleteOrQueue($newPath);
            throw $exception;
        }

        $cleaned = $this->pdfs->deleteOrQueue($oldPath);

        return back()->with('status', $cleaned
            ? 'PDF replaced. Existing fields were cleared.'
            : 'PDF replaced. Existing fields were cleared and old-file cleanup is queued.');
    }

    public function updateSigners(Request $request, int $envelope): RedirectResponse
    {
        $this->findDraft($this->user($request), $envelope);
        $data = $request->validate([
            'signers' => ['present', 'array', 'max:50'],
            'signers.*.id' => ['nullable', 'integer'],
            'signers.*.name' => ['required', 'string', 'max:255', new SupportedPdfText],
            'signers.*.email' => ['required', 'email:rfc', 'max:255', 'distinct:ignore_case', new SupportedPdfText],
            'signers.*.role_label' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($envelope, $data): void {
            $record = $this->lockDraft($envelope);
            $existing = $record->signers()->get()->keyBy('id');
            $submittedIds = array_values(array_filter(array_map(
                fn (array $signer): ?int => isset($signer['id']) ? (int) $signer['id'] : null,
                $data['signers'],
            )));

            if (array_diff($submittedIds, $existing->keys()->all()) !== []) {
                abort(404);
            }

            $record->signers()->whereNotIn('id', $submittedIds)->delete();

            foreach ($existing->whereIn('id', $submittedIds) as $signer) {
                $signer->update(['email' => Str::uuid().'@invalid.test']);
            }

            foreach (array_values($data['signers']) as $position => $input) {
                $attributes = [
                    'name' => $input['name'],
                    'email' => strtolower($input['email']),
                    'role_label' => ($input['role_label'] ?? null) ?: null,
                    'position' => $position,
                ];

                if (! empty($input['id'])) {
                    $existing->get((int) $input['id'])?->update($attributes);
                } else {
                    $record->signers()->create($attributes);
                }
            }
        });

        return back()->with('status', 'Signers saved in the displayed order.');
    }

    public function updateFields(Request $request, int $envelope): RedirectResponse
    {
        $this->findDraft($this->user($request), $envelope);
        $fields = $this->decodeFields($request);
        $validated = validator(['fields' => $fields], $this->fieldRules())->validate()['fields'];

        DB::transaction(function () use ($envelope, $validated): void {
            $record = $this->lockDraft($envelope);
            $record->load(['document', 'signers']);
            abort_if($record->document === null, 409, 'The draft has no document for field placement.');
            $signerIds = $record->signers->modelKeys();

            foreach ($validated as $field) {
                if (! in_array((int) $field['assignee_id'], $signerIds, true)
                    || (int) $field['page'] > $record->document->page_count) {
                    throw ValidationException::withMessages(['fields_json' => 'Every field must use a signer and page from this envelope.']);
                }
                $this->assertBounds($field);
            }

            $record->fields()->delete();

            foreach ($validated as $field) {
                $signer = $record->signers()->findOrFail($field['assignee_id']);
                $signer->fields()->create($this->fieldAttributes($field));
            }
        });

        return back()->with('status', 'Field placements saved.');
    }

    public function send(Request $request, int $envelope): RedirectResponse
    {
        $user = $this->user($request);
        $record = $this->findEnvelope($user, $envelope, ['document', 'signers.fields', 'fields']);

        if ($record->status !== EnvelopeStatus::Draft) {
            return back()->with('status', 'This envelope has already been sent.');
        }

        $errors = $this->sendErrors($record);

        if ($errors !== []) {
            throw ValidationException::withMessages(['send' => $errors]);
        }

        try {
            $this->sendEnvelope->handle(
                $this->selectedTeam->get(),
                $user,
                $envelope,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (DomainException $exception) {
            if ($this->findEnvelope($user, $envelope)->status === EnvelopeStatus::Sent) {
                return back()->with('status', 'This envelope has already been sent.');
            }

            throw $exception;
        }

        return back()->with('status', 'Envelope sent and frozen.');
    }

    public function resendLink(Request $request, int $envelope, int $signer): RedirectResponse
    {
        $user = $this->user($request);

        try {
            $delivered = $this->rotateSigningInvitation->handle(
                $this->selectedTeam->get(),
                $user,
                $envelope,
                $signer,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['resend' => $exception->getMessage()]);
        }

        return back()->with('status', $delivered
            ? 'A new signing link was handed to the configured mailer.'
            : 'The mail handoff is unknown. The new link was not claimed as delivered.');
    }

    public function void(Request $request, int $envelope): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->voidEnvelope->handle($this->selectedTeam->get(), $this->user($request), $envelope, $data['reason']);

        return back()->with('status', 'Envelope voided. Sender notification was attempted.');
    }

    public function retryCompletion(Request $request, int $envelope): RedirectResponse
    {
        try {
            $this->retryCompletion->handle($this->selectedTeam->get(), $this->user($request), $envelope);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['completion' => $exception->getMessage()]);
        }

        return back()->with('status', 'Completion retry queued.');
    }

    public function resendUnknown(Request $request, int $envelope, int $delivery): RedirectResponse
    {
        $request->validate(['confirm_duplicate_risk' => ['accepted']]);
        $this->completion->resendUnknown(
            $this->selectedTeam->get(),
            $this->user($request),
            $envelope,
            $delivery,
        );

        return back()->with('status', 'A new final-document delivery generation was attempted.');
    }

    public function confirmDelete(Request $request, int $envelope): View
    {
        $record = $this->findEnvelope($this->user($request), $envelope);
        abort_unless($record->status->isDeletable(), 409);

        return view('sender.envelopes.delete', ['envelope' => $record]);
    }

    public function destroy(Request $request, int $envelope): RedirectResponse
    {
        $request->validate(['confirmation' => ['required', 'in:DELETE']]);
        $this->purgeEnvelope->handle($this->selectedTeam->get(), $this->user($request), $envelope);

        return to_route('sender.dashboard')->with('status', 'Envelope and its private files were deleted.');
    }

    public function saveAsTemplate(Request $request, int $envelope): RedirectResponse
    {
        $user = $this->user($request);
        $this->findDraft($user, $envelope);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $path = null;

        try {
            $template = DB::transaction(function () use ($envelope, $user, $data, &$path): Template {
                $record = $this->lockDraft($envelope);
                $record->load(['document', 'signers.fields']);
                abort_if($record->document === null, 409, 'The draft has no document to capture.');
                $path = $this->pdfs->copy($record->document->storage_path, (int) $this->selectedTeam->get()->getKey());
                $template = new Template([
                    'name' => $data['name'],
                    'title' => $record->title,
                    'message' => $record->message,
                    'original_name' => $record->document->original_name,
                    'storage_path' => $path,
                    'size_bytes' => $record->document->size_bytes,
                    'page_count' => $record->document->page_count,
                    'original_sha256' => $record->document->original_sha256,
                ]);
                $template->team_id = $this->selectedTeam->get()->getKey();
                $template->sender_id = $user->getKey();
                $template->save();

                foreach ($record->signers as $position => $signer) {
                    $baseLabel = $signer->role_label ?: $signer->name;
                    $label = $baseLabel;
                    $suffix = 2;
                    while ($template->roles()->where('label', $label)->exists()) {
                        $label = $baseLabel.' '.$suffix++;
                    }
                    $role = $template->roles()->create([
                        'label' => $label,
                        'position' => $position,
                    ]);
                    foreach ($signer->fields as $field) {
                        $role->fields()->create($field->only(['page', 'type', 'required', 'position_x', 'position_y', 'width', 'height']));
                    }
                }

                return $template;
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                $this->pdfs->deleteOrQueue($path);
            }
            throw $exception;
        }

        return to_route('sender.templates.edit', $template)->with('status', 'Template created from the draft.');
    }

    /** @return Builder<Envelope> */
    private function baseQuery(User $user): Builder
    {
        return Envelope::query()
            ->forTeam($this->selectedTeam->get())
            ->where('sender_id', $user->getKey());
    }

    /** @param array<int|string, mixed> $with */
    private function findEnvelope(User $user, int $id, array $with = []): Envelope
    {
        return $this->baseQuery($user)->with($with)->findOrFail($id);
    }

    /** @param array<int|string, mixed> $with */
    private function findDraft(User $user, int $id, array $with = []): Envelope
    {
        $record = $this->findEnvelope($user, $id, $with);
        abort_unless($record->status === EnvelopeStatus::Draft, 409);

        return $record;
    }

    private function lockDraft(int $id): Envelope
    {
        $record = Envelope::query()
            ->forTeam($this->selectedTeam->get())
            ->where('sender_id', auth()->id())
            ->lockForUpdate()
            ->findOrFail($id);
        abort_unless($record->status === EnvelopeStatus::Draft, 409);

        return $record;
    }

    /** @return list<array<string, mixed>> */
    private function decodeFields(Request $request): array
    {
        $request->validate(['fields_json' => ['required', 'json']]);
        $fields = json_decode($request->string('fields_json')->toString(), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($fields)) {
            throw ValidationException::withMessages(['fields_json' => 'Field placements must be an array.']);
        }

        return array_values($fields);
    }

    /** @return array<string, array<int, mixed>> */
    private function fieldRules(): array
    {
        return [
            'fields' => ['present', 'array', 'max:500'],
            'fields.*.assignee_id' => ['required', 'integer'],
            'fields.*.page' => ['required', 'integer', 'min:1'],
            'fields.*.type' => ['required', Rule::enum(FieldType::class)],
            'fields.*.required' => ['required', 'boolean'],
            'fields.*.position_x' => ['required', 'numeric', 'between:0,1'],
            'fields.*.position_y' => ['required', 'numeric', 'between:0,1'],
            'fields.*.width' => ['required', 'numeric', 'gt:0', 'lte:1'],
            'fields.*.height' => ['required', 'numeric', 'gt:0', 'lte:1'],
        ];
    }

    /** @param array<string, mixed> $field */
    private function assertBounds(array $field): void
    {
        if ((float) $field['position_x'] + (float) $field['width'] > 1
            || (float) $field['position_y'] + (float) $field['height'] > 1) {
            throw ValidationException::withMessages(['fields_json' => 'Fields must stay within the PDF page.']);
        }
    }

    /** @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function fieldAttributes(array $field): array
    {
        return [
            'page' => (int) $field['page'],
            'type' => $field['type'],
            'required' => (bool) $field['required'],
            'position_x' => (float) $field['position_x'],
            'position_y' => (float) $field['position_y'],
            'width' => (float) $field['width'],
            'height' => (float) $field['height'],
        ];
    }

    /** @return list<string> */
    private function sendErrors(Envelope $envelope): array
    {
        $errors = [];
        if ($envelope->document === null) {
            $errors[] = 'A valid PDF is required.';
        }
        if ($envelope->signers->isEmpty()) {
            $errors[] = 'Add at least one signer.';
        }
        if ($envelope->fields->contains(fn ($field) => $field->signer_id === null)) {
            $errors[] = 'Assign every field to a signer.';
        }
        foreach ($envelope->signers as $signer) {
            $hasRequiredSignature = $signer->fields
                ->where('required', true)
                ->contains(fn ($field) => in_array($field->type, [FieldType::Signature, FieldType::Initials], true));
            if (! $hasRequiredSignature) {
                $errors[] = $signer->name.' needs a required signature or initials field.';
            }
        }

        return $errors;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
