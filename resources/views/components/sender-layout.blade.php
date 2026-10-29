@props(['title'])

@push('head')
    <link rel="stylesheet" href="{{ asset('build/assets/sender.css') }}">
    @fluxAppearance
@endpush

<x-bfc-layout :title="$title.' - Witness'">
    <x-slot:sidebar>
        <nav class="bfc-sidebar-links" aria-label="Witness" data-testid="sender-navigation">
            <a href="{{ route('sender.dashboard') }}" class="bfc-shell-link">Witness</a>
            <a href="{{ route('sender.dashboard') }}" class="bfc-shell-link">Envelopes</a>
            <a href="{{ route('sender.templates.index') }}" class="bfc-shell-link">Templates</a>
            <a href="{{ route('sender.setup') }}" class="bfc-shell-link">Setup</a>
            <a href="{{ route('sender.envelopes.create') }}" class="bfc-shell-link">New envelope</a>
            @isset($selectedTeam)
                <a href="{{ route('sender.teams.select') }}" class="bfc-shell-link" data-testid="selected-team">{{ $selectedTeam->name }}</a>
            @endisset
        </nav>
    </x-slot:sidebar>

    <div class="witness-shell">
        @if (session('status'))
            <div class="witness-notice" role="status" data-testid="sender-status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="witness-errors" role="alert" data-testid="sender-errors">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        {{ $slot }}
    </div>
</x-bfc-layout>
