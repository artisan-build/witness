<x-sender-layout title="Choose a team">
    <section class="witness-panel witness-narrow" data-testid="team-selection">
        <p class="witness-kicker">Workspace</p>
        <h1>Choose a team</h1>
        <p>Witness keeps every envelope and template inside one selected team.</p>
        <form method="POST" action="{{ route('sender.teams.store') }}" class="witness-stack">
            @csrf
            @foreach ($teams as $team)
                <label class="witness-choice">
                    <input type="radio" name="team_id" value="{{ $team->getKey() }}" required>
                    <span>{{ $team->name }}</span>
                </label>
            @endforeach
            <button class="witness-button" type="submit" data-testid="select-team">Continue</button>
        </form>
    </section>
</x-sender-layout>
