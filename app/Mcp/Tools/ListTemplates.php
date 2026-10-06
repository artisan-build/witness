<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Authorization\WitnessMcpAbility;
use App\Models\Template;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class ListTemplates extends WitnessTool
{
    protected string $name = 'list_templates';

    protected string $description = 'List the caller-owned PDF templates across teams where the caller is currently a member.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(100)->description('Maximum rows to return; defaults to 50.'),
            'after_id' => $schema->integer()->min(1)->description('Return rows with an id greater than this cursor.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->access()->user(WitnessMcpAbility::Read);
        if ($user === null) {
            return Response::error('Witness read access is required.');
        }

        $this->validateOnly($request, ['limit', 'after_id']);
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'after_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $limit = (int) ($data['limit'] ?? 50);

        $templates = Template::query()
            ->select(['id', 'team_id', 'name', 'title', 'page_count', 'created_at', 'updated_at'])
            ->where('sender_id', $user->getKey())
            ->whereHas('team.users', fn ($query) => $query->whereKey($user->getKey()))
            ->when(isset($data['after_id']), fn ($query) => $query->where('id', '>', $data['after_id']))
            ->withCount(['roles', 'fields'])
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $templates->count() > $limit;
        $templates = $templates->take($limit);

        return Response::structured([
            'templates' => $templates->map(fn (Template $template): array => [
                'id' => (int) $template->getKey(),
                'team_id' => (int) $template->team_id,
                'name' => $template->name,
                'title' => $template->title,
                'page_count' => $template->page_count,
                'role_count' => $template->roles_count,
                'field_count' => $template->fields_count,
                'created_at' => $template->created_at?->toIso8601String(),
                'updated_at' => $template->updated_at?->toIso8601String(),
            ])->values()->all(),
            'next_after_id' => $hasMore ? (int) $templates->last()?->getKey() : null,
        ]);
    }
}
