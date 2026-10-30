<?php

declare(strict_types=1);

namespace App\Livewire\Sender;

use App\Enums\EnvelopeFilters;
use App\Enums\EnvelopeStatus;
use App\Enums\EnvelopeSteps;
use App\Enums\FieldType;
use App\Enums\MailDeliveryPurpose;
use App\Enums\MailDeliveryStatus;
use App\Enums\SignerStatus;
use App\Models\Envelope;
use App\Models\Signer;
use App\Support\SelectedTeam;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

final class EnvelopeEditor extends Component
{
    #[Locked]
    public int $envelopeId;

    #[Url]
    public string $step = 'details';

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
                'mailDeliveries',
            ])
            ->findOrFail($this->envelopeId);

        if (! $envelope->status->isDraft()) {
            return view('livewire.sender.envelope-status', $this->statusPage($envelope));
        }

        $step = EnvelopeSteps::tryFrom($this->step) ?? EnvelopeSteps::Details;

        return view('livewire.sender.envelope-draft', [
            'envelope' => $envelope,
            'step' => $step,
            'steps' => $this->stepBar($envelope, $step),
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
            'fieldsBySigner' => $envelope->signers->mapWithKeys(fn (Signer $signer): array => [
                $signer->getKey() => $envelope->fields->where('signer_id', $signer->getKey())->count(),
            ]),
        ]);
    }

    /**
     * Each step with whether it is done and whether it is the one showing.
     *
     * @return list<array{step: EnvelopeSteps, done: bool, current: bool}>
     */
    private function stepBar(Envelope $envelope, EnvelopeSteps $current): array
    {
        return array_map(fn (EnvelopeSteps $step): array => [
            'step' => $step,
            'done' => match ($step) {
                EnvelopeSteps::Details => true,
                EnvelopeSteps::Signers => $envelope->signers->isNotEmpty(),
                EnvelopeSteps::Fields => $envelope->fields->isNotEmpty(),
                EnvelopeSteps::Review => false,
            },
            'current' => $step === $current,
        ], EnvelopeSteps::cases());
    }

    /**
     * What the page for a sent envelope shows: who has signed, what can still be done, and the trail.
     *
     * @return array<string, mixed>
     */
    private function statusPage(Envelope $envelope): array
    {
        $signed = $envelope->signers->where('status', SignerStatus::Signed)->count();
        $finalDeliveries = $envelope->mailDeliveries->where('purpose', MailDeliveryPurpose::FinalDocument);

        return [
            'envelope' => $envelope,
            'filter' => EnvelopeFilters::forStatus($envelope->status),
            'signedCount' => $signed,
            'signers' => $envelope->signers->map(fn (Signer $signer): array => [
                'signer' => $signer,
                'canResend' => $envelope->status === EnvelopeStatus::Sent
                    && ! in_array($signer->status, [SignerStatus::Signed, SignerStatus::Declined], true),
                'declineNotified' => $signer->status === SignerStatus::Declined
                    ? $signer->mailDeliveries
                        ->where('purpose', MailDeliveryPurpose::DeclineNotification)
                        ->sortByDesc('generation')
                        ->first()?->status === MailDeliveryStatus::Sent
                    : null,
            ]),
            'unknownDeliveries' => $finalDeliveries->where('status', MailDeliveryStatus::Unknown),
            'reissuableDeliveries' => $finalDeliveries
                ->groupBy(fn ($delivery) => $delivery->recipient_kind.'|'.$delivery->recipient_email)
                ->map(fn ($deliveries) => $deliveries->sortByDesc('generation')->first())
                ->where('status', MailDeliveryStatus::Sent),
            'auditEvents' => $envelope->auditEvents->sortByDesc('occurred_at'),
        ];
    }
}
