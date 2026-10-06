<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateDraftEnvelope;
use App\Enums\FieldType;
use App\Models\Template;
use App\Models\TemplateRole;
use App\Services\PdfDocumentService;
use App\Support\SelectedTeam;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class TemplateController
{
    public function __construct(
        private SelectedTeam $selectedTeam,
        private PdfDocumentService $pdfs,
        private CreateDraftEnvelope $createDraft,
    ) {}

    public function index(Request $request): View
    {
        $templates = $this->baseQuery($this->user($request))->withCount(['roles', 'fields'])->latest()->get();

        return view('sender.templates.index', compact('templates'));
    }

    public function create(): View
    {
        return view('sender.templates.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            'document' => ['required', 'file', 'max:20480'],
        ]);
        $pdf = $this->pdfs->inspect($data['document']);
        $path = $this->pdfs->store($pdf, (int) $this->selectedTeam->get()->getKey());

        try {
            $template = new Template([
                'name' => $data['name'],
                'title' => $data['title'],
                'message' => $data['message'] ?? null,
                'original_name' => $pdf['name'],
                'storage_path' => $path,
                'size_bytes' => $pdf['size'],
                'page_count' => $pdf['pages'],
                'original_sha256' => $pdf['sha256'],
            ]);
            $template->team_id = $this->selectedTeam->get()->getKey();
            $template->sender_id = $user->getKey();
            $template->save();
        } catch (Throwable $exception) {
            $this->pdfs->delete($path);
            throw $exception;
        }

        return to_route('sender.templates.edit', $template)->with('status', 'Template created. Add roles and fields.');
    }

    public function edit(Request $request, int $template): View
    {
        $record = $this->findTemplate($this->user($request), $template, ['roles', 'fields']);

        $placementData = [
            'assignees' => $record->roles->map(fn (TemplateRole $role): array => [
                'id' => $role->getKey(),
                'name' => $role->label,
            ])->values(),
            'fields' => $record->fields->map(fn ($field): array => [
                'assignee_id' => $field->template_role_id,
                'page' => $field->page,
                'type' => $field->type->value,
                'required' => $field->required,
                'position_x' => (float) $field->position_x,
                'position_y' => (float) $field->position_y,
                'width' => (float) $field->width,
                'height' => (float) $field->height,
            ])->values(),
        ];

        return view('sender.templates.edit', [
            'template' => $record,
            'fieldTypes' => FieldType::cases(),
            'placementData' => $placementData,
        ]);
    }

    public function update(Request $request, int $template): RedirectResponse
    {
        $record = $this->findTemplate($this->user($request), $template);
        $record->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]));

        return back()->with('status', 'Template details saved.');
    }

    public function replaceDocument(Request $request, int $template): RedirectResponse
    {
        $record = $this->findTemplate($this->user($request), $template);
        $data = $request->validate(['document' => ['required', 'file', 'max:20480']]);
        $pdf = $this->pdfs->inspect($data['document']);
        $path = $this->pdfs->store($pdf, (int) $this->selectedTeam->get()->getKey());
        $oldPath = $record->storage_path;

        try {
            DB::transaction(function () use ($record, $pdf, $path): void {
                $record->fields()->delete();
                $record->update([
                    'original_name' => $pdf['name'],
                    'storage_path' => $path,
                    'size_bytes' => $pdf['size'],
                    'page_count' => $pdf['pages'],
                    'original_sha256' => $pdf['sha256'],
                ]);
            });
        } catch (Throwable $exception) {
            $this->pdfs->delete($path);
            throw $exception;
        }

        $this->pdfs->delete($oldPath);

        return back()->with('status', 'Template PDF replaced. Existing fields were cleared.');
    }

    public function updateRoles(Request $request, int $template): RedirectResponse
    {
        $record = $this->findTemplate($this->user($request), $template);
        $data = $request->validate([
            'roles' => ['present', 'array', 'max:50'],
            'roles.*.id' => ['nullable', 'integer'],
            'roles.*.label' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
        ]);

        DB::transaction(function () use ($record, $data): void {
            $existing = $record->roles()->get()->keyBy('id');
            $submittedIds = array_values(array_filter(array_map(
                fn (array $role): ?int => isset($role['id']) ? (int) $role['id'] : null,
                $data['roles'],
            )));

            if (array_diff($submittedIds, $existing->keys()->all()) !== []) {
                abort(404);
            }

            $record->roles()->whereNotIn('id', $submittedIds)->delete();
            foreach ($existing->whereIn('id', $submittedIds) as $role) {
                $role->update(['label' => '__witness_'.Str::uuid()]);
            }
            foreach (array_values($data['roles']) as $position => $input) {
                $attributes = ['label' => $input['label'], 'position' => $position];
                if (! empty($input['id'])) {
                    $existing->get((int) $input['id'])?->update($attributes);
                } else {
                    $record->roles()->create($attributes);
                }
            }
        });

        return back()->with('status', 'Template roles saved in the displayed order.');
    }

    public function updateFields(Request $request, int $template): RedirectResponse
    {
        $record = $this->findTemplate($this->user($request), $template, ['roles']);
        $request->validate(['fields_json' => ['required', 'json']]);
        $fields = json_decode($request->string('fields_json')->toString(), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($fields)) {
            throw ValidationException::withMessages(['fields_json' => 'Field placements must be an array.']);
        }

        $validated = validator(['fields' => array_values($fields)], [
            'fields' => ['present', 'array', 'max:500'],
            'fields.*.assignee_id' => ['required', 'integer'],
            'fields.*.page' => ['required', 'integer', 'min:1', 'max:'.$record->page_count],
            'fields.*.type' => ['required', Rule::enum(FieldType::class)],
            'fields.*.required' => ['required', 'boolean'],
            'fields.*.position_x' => ['required', 'numeric', 'between:0,1'],
            'fields.*.position_y' => ['required', 'numeric', 'between:0,1'],
            'fields.*.width' => ['required', 'numeric', 'gt:0', 'lte:1'],
            'fields.*.height' => ['required', 'numeric', 'gt:0', 'lte:1'],
        ])->validate()['fields'];
        $roleIds = $record->roles->modelKeys();

        foreach ($validated as $field) {
            if (! in_array((int) $field['assignee_id'], $roleIds, true)
                || (float) $field['position_x'] + (float) $field['width'] > 1
                || (float) $field['position_y'] + (float) $field['height'] > 1) {
                throw ValidationException::withMessages(['fields_json' => 'Every field must use a role and stay within a page.']);
            }
        }

        DB::transaction(function () use ($record, $validated): void {
            $record->fields()->delete();
            foreach ($validated as $field) {
                $role = $record->roles()->findOrFail($field['assignee_id']);
                $role->fields()->create([
                    'page' => (int) $field['page'],
                    'type' => $field['type'],
                    'required' => (bool) $field['required'],
                    'position_x' => (float) $field['position_x'],
                    'position_y' => (float) $field['position_y'],
                    'width' => (float) $field['width'],
                    'height' => (float) $field['height'],
                ]);
            }
        });

        return back()->with('status', 'Template field placements saved.');
    }

    public function sendForm(Request $request, int $template): View
    {
        $record = $this->findTemplate($this->user($request), $template, ['roles.fields']);

        return view('sender.templates.send', ['template' => $record]);
    }

    public function instantiate(Request $request, int $template): RedirectResponse
    {
        $user = $this->user($request);
        $record = $this->findTemplate($user, $template, ['roles.fields']);
        $data = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*.name' => ['required', 'string', 'max:255'],
            'roles.*.email' => ['required', 'email:rfc', 'max:255', 'distinct:ignore_case'],
            'message' => ['nullable', 'string', 'max:5000'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        if ($record->roles->isEmpty() || $record->roles->contains(fn ($role) => ! isset($data['roles'][$role->getKey()]))) {
            throw ValidationException::withMessages(['roles' => 'Map every template role to a signer.']);
        }

        $path = $this->pdfs->copy($record->storage_path, (int) $this->selectedTeam->get()->getKey());

        try {
            $envelope = DB::transaction(function () use ($record, $user, $request, $data, $path) {
                $envelope = $this->createDraft->handle(
                    team: $this->selectedTeam->get(),
                    sender: $user,
                    title: $record->title,
                    originalName: $record->original_name,
                    storagePath: $path,
                    sizeBytes: $record->size_bytes,
                    pageCount: $record->page_count,
                    originalSha256: $record->original_sha256,
                    message: $data['message'] ?? $record->message,
                    expiresAt: isset($data['expires_at']) ? Date::parse($data['expires_at']) : null,
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                );

                foreach ($record->roles as $position => $role) {
                    $mapping = $data['roles'][$role->getKey()];
                    $signer = $envelope->signers()->create([
                        'name' => $mapping['name'],
                        'email' => strtolower($mapping['email']),
                        'role_label' => $role->label,
                        'position' => $position,
                    ]);
                    foreach ($role->fields as $field) {
                        $signer->fields()->create($field->only(['page', 'type', 'required', 'position_x', 'position_y', 'width', 'height']));
                    }
                }

                return $envelope;
            });
        } catch (Throwable $exception) {
            $this->pdfs->delete($path);
            throw $exception;
        }

        return to_route('sender.envelopes.edit', $envelope)->with('status', 'Draft created from template. Review it before sending.');
    }

    /** @return Builder<Template> */
    private function baseQuery(User $user): Builder
    {
        return Template::query()
            ->forTeam($this->selectedTeam->get())
            ->where('sender_id', $user->getKey());
    }

    /** @param array<int|string, mixed> $with */
    private function findTemplate(User $user, int $id, array $with = []): Template
    {
        return $this->baseQuery($user)->with($with)->findOrFail($id);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
