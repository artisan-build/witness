<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Enums\EnvelopeFilters;
use App\Models\Envelope;
use App\Models\Team;
use App\Support\SelectedTeam;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Witness's sidebar: envelopes grouped by what they need next, with counts, and templates. The
 * footer half holds Setup for admins and the team switcher for people in more than one team.
 */
final class SenderNavigation extends Component
{
    /** @var list<array{filter: EnvelopeFilters, count: int, current: bool}> */
    public array $filters = [];

    public bool $onEnvelopes;

    public bool $onTemplates;

    public bool $onSetup;

    public bool $isAdmin;

    public ?Team $team;

    public bool $canSwitchTeam;

    /** Gather counts for the selected team and work out which link is current. */
    public function __construct(SelectedTeam $selectedTeam, public bool $footer = false)
    {
        $user = Auth::user();
        $this->team = $selectedTeam->current() ?? $this->sessionTeam($user);
        $this->isAdmin = $user instanceof User && RolePolicy::canManageMembers($user->role);
        $this->canSwitchTeam = $user instanceof User
            && Team::query()->whereHas('users', fn ($query) => $query->whereKey($user->getKey()))->count() > 1;
        $this->onEnvelopes = Request::routeIs('sender.dashboard', 'sender.envelopes.*');
        $this->onTemplates = Request::routeIs('sender.templates.*');
        $this->onSetup = Request::routeIs('sender.setup');

        $counts = $this->team !== null && $user instanceof User
            ? Envelope::query()->forTeam($this->team)->where('sender_id', $user->getKey())
                ->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')
            : collect();
        $current = Request::routeIs('sender.dashboard') ? EnvelopeFilters::tryFrom((string) Request::query('status')) : null;

        foreach (EnvelopeFilters::cases() as $filter) {
            $this->filters[] = [
                'filter' => $filter,
                'count' => (int) collect($filter->statuses())->sum(fn ($status): int => (int) ($counts[$status->value] ?? 0)),
                'current' => $filter === $current,
            ];
        }
    }

    /**
     * The team for pages outside the team-selecting middleware, such as Setup: the one chosen this
     * session, or the person's only team.
     */
    private function sessionTeam(mixed $user): ?Team
    {
        if (! $user instanceof User) {
            return null;
        }

        $teams = Team::query()->whereHas('users', fn ($query) => $query->whereKey($user->getKey()))->get();

        return $teams->firstWhere('id', Session::get('witness.team_id')) ?? ($teams->count() === 1 ? $teams->first() : null);
    }

    /** The main navigation, or the footer links. */
    public function render(): View
    {
        return view($this->footer ? 'components.sender-navigation-footer' : 'components.sender-navigation');
    }
}
