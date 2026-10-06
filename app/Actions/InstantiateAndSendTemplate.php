<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\Template;
use App\Rules\SupportedPdfText;
use App\Services\PdfDocumentService;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class InstantiateAndSendTemplate
{
    public function __construct(
        private CreateDraftEnvelope $createDraft,
        private SendEnvelope $sendEnvelope,
        private PdfDocumentService $pdfs,
    ) {}

    /**
     * @param  list<array{role: string, name: string, email: string}>  $mappings
     */
    public function handle(
        User $sender,
        int $templateId,
        array $mappings,
        ?string $message,
        ?string $expiresAt,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Envelope {
        $validated = Validator::validate([
            'roles' => $mappings,
            'message' => $message,
            'expires_at' => $expiresAt,
        ], [
            'roles' => ['required', 'array', 'min:1', 'max:50'],
            'roles.*' => ['required', 'array:role,name,email'],
            'roles.*.role' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'roles.*.name' => ['required', 'string', 'max:255', new SupportedPdfText],
            'roles.*.email' => ['required', 'email:rfc', 'max:255', 'distinct:ignore_case', new SupportedPdfText],
            'message' => ['nullable', 'string', 'max:5000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        /** @var list<array{role: string, name: string, email: string}> $roleMappings */
        $roleMappings = $validated['roles'];
        $path = null;
        $envelope = null;

        try {
            $envelope = DB::transaction(function () use ($sender, $templateId, $roleMappings, $validated, $ipAddress, $userAgent, &$path): Envelope {
                $template = Template::query()
                    ->whereKey($templateId)
                    ->where('sender_id', $sender->getKey())
                    ->whereHas('team.users', fn ($query) => $query->whereKey($sender->getKey()))
                    ->with('roles.fields')
                    ->lockForUpdate()
                    ->firstOrFail();

                $byLabel = collect($roleMappings)->keyBy(fn (array $mapping): string => mb_strtolower(trim($mapping['role'])));
                $expected = $template->roles->map(fn ($role): string => mb_strtolower($role->label));

                if ($template->roles->isEmpty()
                    || $byLabel->count() !== count($roleMappings)
                    || $byLabel->keys()->sort()->values()->all() !== $expected->sort()->values()->all()) {
                    throw ValidationException::withMessages(['roles' => 'Map every template role exactly once and include no other roles.']);
                }

                $path = $this->pdfs->copy($template->storage_path, (int) $template->team_id);
                $draft = $this->createDraft->handle(
                    team: $template->team,
                    sender: $sender,
                    title: $template->title,
                    originalName: $template->original_name,
                    storagePath: $path,
                    sizeBytes: $template->size_bytes,
                    pageCount: $template->page_count,
                    originalSha256: $template->original_sha256,
                    message: $validated['message'] ?? $template->message,
                    expiresAt: isset($validated['expires_at']) ? CarbonImmutable::parse($validated['expires_at']) : null,
                    ipAddress: $ipAddress,
                    userAgent: $userAgent,
                );

                foreach ($template->roles as $position => $role) {
                    $mapping = $byLabel->get(mb_strtolower($role->label));
                    $signer = $draft->signers()->create([
                        'name' => trim($mapping['name']),
                        'email' => mb_strtolower(trim($mapping['email'])),
                        'role_label' => $role->label,
                        'position' => $position,
                    ]);

                    foreach ($role->fields as $field) {
                        $signer->fields()->create($field->only([
                            'page', 'type', 'required', 'position_x', 'position_y', 'width', 'height',
                        ]));
                    }
                }

                return $draft;
            });

            return $this->sendEnvelope->handle(
                $envelope->team,
                $sender,
                (int) $envelope->getKey(),
                $ipAddress,
                $userAgent,
            );
        } catch (Throwable $exception) {
            $freshEnvelope = $envelope?->fresh();
            $cleanupFile = $freshEnvelope === null;
            if ($freshEnvelope?->status === EnvelopeStatus::Draft) {
                DB::transaction(fn () => Envelope::query()->whereKey($freshEnvelope)->delete());
                $cleanupFile = true;
            }
            if ($cleanupFile && $path !== null) {
                $this->pdfs->deleteOrQueue($path);
            }

            throw $exception;
        }
    }
}
