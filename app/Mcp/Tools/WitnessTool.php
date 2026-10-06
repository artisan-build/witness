<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Authorization\McpAccess;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhase;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

abstract class WitnessTool extends Tool
{
    use AdvertisesToolEffect;
    use RespectsEffectCeiling;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $this->setMeta(
            ToolClassification::META_KEY,
            (ToolClassification::of($this)->value ?? Classification::Content)->value,
        );
        $this->setMeta(
            ToolEffect::META_KEY,
            (ToolEffect::of($this)->value ?? Effect::Destructive)->value,
        );

        $tool = parent::toArray();
        $tool['inputSchema']['additionalProperties'] = false;

        if (TwoPhase::of($this) !== null) {
            $tool['inputSchema']['properties'][TwoPhase::CONFIRM_ARGUMENT] = [
                'type' => 'string',
                'description' => 'Single-use confirmation returned by this tool preview.',
            ];
            $tool['_meta'][TwoPhase::META_KEY] = [
                'confirmationArgument' => TwoPhase::CONFIRM_ARGUMENT,
                'protocolVersion' => 1,
            ];
        }

        return $tool;
    }

    protected function access(): McpAccess
    {
        return app(McpAccess::class);
    }

    /** @param list<string> $allowed */
    protected function validateOnly(Request $request, array $allowed): void
    {
        $unknown = array_diff(array_keys($request->all()), $allowed);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'arguments' => 'Unknown argument: '.implode(', ', $unknown).'.',
            ]);
        }
    }
}
