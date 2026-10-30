@if ($isAdmin)
    <a href="{{ route('sender.setup') }}"
       class="bfc-shell-link"
       @if ($onSetup) aria-current="page" @endif
       data-testid="setup-navigation">
        Setup
    </a>
@endif
@if ($team && $canSwitchTeam)
    <a href="{{ route('sender.teams.select') }}"
       class="bfc-shell-link"
       data-testid="selected-team">
        Team: {{ $team->name }}
    </a>
@endif
