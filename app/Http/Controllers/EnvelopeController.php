<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateDraftEnvelope;
use App\Actions\SendEnvelope;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\Template;
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
    ) {}

    public function index(Request $request): View
    {
        $user = $this->user($request);
        $envelopes = $this->baseQuery($user)
            ->with(['signers' => fn ($query) => $query->orderBy('position')])
            ->latest()
            ->get();

        return view('sender.dashboard', compact('envelopes'));
    }

    public function create(): View
    {
        return view('sender.envelopes.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
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
            $this->pdfs->delete($path);
            throw $exception;
        }

        return to_route('sender.envelopes.edit', $envelope)->with('status', 'Draft created. Add signers and fields.');
    }

    public function edit(Request $request, int $envelope): View
    {
        $record = $this->findEnvelope($this->user($request), $envelope, [
            'document',
            'signers' => fn ($query) => $query->orderBy('position'),
            'fields',
            'auditEvents',
        ]);

        $placementData = [
            'assignees' => $record->signers->map(fn (Signer $signer): array => [
                'id' => $signer->getKey(),
                'name' => $signer->name,
            ])->values(),
            'fields' => $record->fields->map(fn ($field): array => [
                'assignee_id' => $field->signer_id,
                'page' => $field->page,
                'type' => $field->type->value,
                'required' => $field->required,
                'position_x' => (float) $field->position_x,
                'position_y' => (float) $field->position_y,
                'width' => (float) $field->width,
                'height' => (float) $field->height,
            ])->values(),
        ];

        return view('sender.envelopes.edit', [
            'envelope' => $record,
            'fieldTypes' => FieldType::cases(),
            'placementData' => $placementData,
        ]);
    }

    public function update(Request $request, int $envelope): RedirectResponse
    {
        $record = $this->findDraft($this->user($request), $envelope);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);
        $record->update($data);

        return back()->with('status', 'Envelope details saved.');
    }

    public function replaceDocument(Request $request, int $envelope): RedirectResponse
    {
        $record = $this->findDraft($this->user($request), $envelope, ['document']);
        $data = $request->validate(['document' => ['required', 'file', 'max:20480']]);
        $pdf = $this->pdfs->inspect($data['document']);
        $newPath = $this->pdfs->store($pdf, (int) $this->selectedTeam->get()->getKey());
        $oldPath = $record->document?->storage_path;

        try {
            DB::transaction(function () use ($record, $pdf, $newPath): void {
                $locked = $this->lockDraft((int) $record->getKey());
                $locked->fields()->delete();
                $locked->document()->update([
                    'original_name' => $pdf['name'],
                    'storage_path' => $newPath,
                    'size_bytes' => $pdf['size'],
                    'page_count' => $pdf['pages'],
                    'original_sha256' => $pdf['sha256'],
                ]);
            });
        } catch (Throwable $exception) {
            $this->pdfs->delete($newPath);
            throw $exception;
        }

        if ($oldPath !== null) {
            $this->pdfs->delete($oldPath);
        }

        return back()->with('status', 'PDF replaced. Existing fields were cleared.');
    }

    public function updateSigners(Request $request, int $envelope): RedirectResponse
    {
        $this->findDraft($this->user($request), $envelope);
        $data = $request->validate([
            'signers' => ['present', 'array', 'max:50'],
            'signers.*.id' => ['nullable', 'integer'],
            'signers.*.name' => ['required', 'string', 'max:255'],
            'signers.*.email' => ['required', 'email:rfc', 'max:255', 'distinct:ignore_case'],
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
        $record = $this->findDraft($this->user($request), $envelope, ['document', 'signers']);
        $fields = $this->decodeFields($request);
        $validated = validator(['fields' => $fields], $this->fieldRules())->validate()['fields'];
        $signerIds = $record->signers->modelKeys();
        $pages = $record->document->page_count;

        foreach ($validated as $field) {
            if (! in_array((int) $field['assignee_id'], $signerIds, true) || (int) $field['page'] > $pages) {
                throw ValidationException::withMessages(['fields_json' => 'Every field must use a signer and page from this envelope.']);
            }
            $this->assertBounds($field);
        }

        DB::transaction(function () use ($envelope, $validated): void {
            $record = $this->lockDraft($envelope);
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

    public function saveAsTemplate(Request $request, int $envelope): RedirectResponse
    {
        $user = $this->user($request);
        $record = $this->findDraft($user, $envelope, ['document', 'signers.fields']);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $path = $this->pdfs->copy($record->document->storage_path, (int) $this->selectedTeam->get()->getKey());

        try {
            $template = DB::transaction(function () use ($record, $user, $data, $path): Template {
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
            $this->pdfs->delete($path);
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
