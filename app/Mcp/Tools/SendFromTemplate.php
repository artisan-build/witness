<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\InstantiateAndSendTemplate;
use App\Authorization\WitnessMcpAbility;
use App\Enums\MailDeliveryPurpose;
use App\Models\MailDelivery;
use App\Models\Template;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhase;
use DomainException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Throwable;

#[IsDestructive]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Destructive)]
#[TwoPhase]
final class SendFromTemplate extends WitnessTool
{
    protected string $name = 'send_from_template';

    protected string $description = 'Create and send an envelope from one caller-owned template after an exact role mapping preview.';

    public function __construct(private readonly InstantiateAndSendTemplate $sendFromTemplate) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema->integer()->min(1)->required(),
            'roles' => $schema->array()->items($schema->object([
                'role' => $schema->string()->max(255)->required(),
                'name' => $schema->string()->max(255)->required(),
                'email' => $schema->string()->format('email')->max(255)->required(),
            ])->withoutAdditionalProperties())->min(1)->max(50)->required(),
            'message' => $schema->string()->max(5000),
            'expires_at' => $schema->string()->format('date-time'),
        ];
    }

    public function preview(Request $request): Response|ResponseFactory
    {
        $user = $this->access()->user(WitnessMcpAbility::Destructive);
        if ($user === null) {
            return Response::error('Witness destructive access is required.');
        }

        $data = $this->validated($request);
        $template = Template::query()
            ->whereKey($data['template_id'])
            ->where('sender_id', $user->getKey())
            ->whereHas('team.users', fn ($query) => $query->whereKey($user->getKey()))
            ->with('roles:id,template_id,label,position')
            ->first();

        if (! $template instanceof Template || ! $this->mappingMatches($template, $data['roles'])) {
            return Response::error('Template not found or role mapping is invalid.');
        }

        return Response::structured([
            'operation' => 'send_from_template',
            'template_id' => (int) $template->getKey(),
            'title' => $template->title,
            'signer_count' => $template->roles->count(),
            'expires_at' => $data['expires_at'] ?? null,
        ]);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->access()->user(WitnessMcpAbility::Destructive);
        if ($user === null) {
            return Response::error('Witness destructive access is required.');
        }

        $data = $this->validated($request);

        try {
            $envelope = $this->sendFromTemplate->handle(
                $user,
                $data['template_id'],
                $data['roles'],
                $data['message'] ?? null,
                $data['expires_at'] ?? null,
                request()->ip(),
                request()->userAgent(),
            );
        } catch (ModelNotFoundException|DomainException|ValidationException) {
            return Response::error('Template not found or cannot be sent with those arguments.');
        } catch (Throwable) {
            return Response::error('The envelope could not be created safely.');
        }

        $deliveries = MailDelivery::query()
            ->where('envelope_id', $envelope->getKey())
            ->where('purpose', MailDeliveryPurpose::SigningInvitation->value)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return Response::structured([
            'envelope_id' => (int) $envelope->getKey(),
            'status' => $envelope->status->value,
            'invitation_deliveries' => [
                'sent' => (int) ($deliveries['sent'] ?? 0),
                'unknown' => (int) ($deliveries['unknown'] ?? 0),
                'pending' => (int) ($deliveries['pending'] ?? 0),
            ],
        ]);
    }

    /** @return array{template_id: int, roles: list<array{role: string, name: string, email: string}>, message?: string|null, expires_at?: string|null} */
    private function validated(Request $request): array
    {
        $this->validateOnly($request, ['template_id', 'roles', 'message', 'expires_at']);

        /** @var array{template_id: int, roles: list<array{role: string, name: string, email: string}>, message?: string|null, expires_at?: string|null} */
        return $request->validate([
            'template_id' => ['required', 'integer', 'min:1'],
            'roles' => ['required', 'array', 'min:1', 'max:50'],
            'roles.*' => ['required', 'array:role,name,email'],
            'roles.*.role' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'roles.*.name' => ['required', 'string', 'max:255'],
            'roles.*.email' => ['required', 'email:rfc', 'max:255', 'distinct:ignore_case'],
            'message' => ['nullable', 'string', 'max:5000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
    }

    /** @param list<array{role: string, name: string, email: string}> $mappings */
    private function mappingMatches(Template $template, array $mappings): bool
    {
        $actual = collect($mappings)->map(fn (array $mapping): string => mb_strtolower(trim($mapping['role'])));
        $expected = $template->roles->map(fn ($role): string => mb_strtolower($role->label));

        return $actual->unique()->count() === $actual->count()
            && $actual->sort()->values()->all() === $expected->sort()->values()->all();
    }
}
