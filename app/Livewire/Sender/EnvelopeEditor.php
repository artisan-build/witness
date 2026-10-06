<?php

declare(strict_types=1);

namespace App\Livewire\Sender;

use App\Enums\FieldType;
use App\Models\Envelope;
use App\Models\Signer;
use App\Support\SelectedTeam;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class EnvelopeEditor extends Component
{
    #[Locked]
    public int $envelopeId;

    public function mount(int $envelopeId): void
    {
        $this->envelopeId = $envelopeId;
    }

    public function render(): View
    {
        $envelope = Envelope::query()
            ->forTeam(resolve(SelectedTeam::class)->get())
            ->where('sender_id', auth()->id())
            ->with([
                'document',
                'signers' => fn ($query) => $query
                    ->with(['mailDeliveries' => fn ($query) => $query->orderBy('generation')])
                    ->orderBy('position'),
                'fields',
                'auditEvents',
            ])
            ->findOrFail($this->envelopeId);

        return view('livewire.sender.envelope-editor', [
            'envelope' => $envelope,
            'fieldTypes' => FieldType::cases(),
            'placementData' => [
                'assignees' => $envelope->signers->map(fn (Signer $signer): array => [
                    'id' => $signer->getKey(),
                    'name' => $signer->name,
                ])->values(),
                'fields' => $envelope->fields->map(fn ($field): array => [
                    'assignee_id' => $field->signer_id,
                    'page' => $field->page,
                    'type' => $field->type->value,
                    'required' => $field->required,
                    'position_x' => (float) $field->position_x,
                    'position_y' => (float) $field->position_y,
                    'width' => (float) $field->width,
                    'height' => (float) $field->height,
                ])->values(),
            ],
        ]);
    }
}
