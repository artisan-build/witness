<?php

declare(strict_types=1);

namespace App\Livewire\Sender;

use App\Enums\FieldType;
use App\Models\Template;
use App\Models\TemplateRole;
use App\Support\SelectedTeam;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class TemplateEditor extends Component
{
    #[Locked]
    public int $templateId;

    public function mount(int $templateId): void
    {
        $this->templateId = $templateId;
    }

    public function render(): View
    {
        $template = Template::query()
            ->forTeam(resolve(SelectedTeam::class)->get())
            ->where('sender_id', auth()->id())
            ->with(['roles', 'fields'])
            ->findOrFail($this->templateId);

        return view('livewire.sender.template-editor', [
            'template' => $template,
            'fieldTypes' => FieldType::cases(),
            'placementData' => [
                'assignees' => $template->roles->map(fn (TemplateRole $role): array => [
                    'id' => $role->getKey(),
                    'name' => $role->label,
                ])->values(),
                'fields' => $template->fields->map(fn ($field): array => [
                    'assignee_id' => $field->template_role_id,
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
