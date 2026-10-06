@props(['title'])

@push('head')
    <link rel="stylesheet" href="{{ asset('build/assets/sender.css') }}">
@endpush

<x-bfc-layout :title="$title.' - Witness'">
    <div class="witness-shell">
        <nav class="witness-nav" aria-label="Witness" data-testid="sender-navigation">
            <a href="{{ route('sender.dashboard') }}" class="witness-brand">Witness</a>
            <div class="witness-nav-links">
                <a href="{{ route('sender.dashboard') }}">Envelopes</a>
                <a href="{{ route('sender.templates.index') }}">Templates</a>
                <a href="{{ route('sender.envelopes.create') }}" class="witness-button witness-button-small">New envelope</a>
            </div>
            @isset($selectedTeam)
                <a href="{{ route('sender.teams.select') }}" class="witness-team" data-testid="selected-team">{{ $selectedTeam->name }}</a>
            @endisset
        </nav>

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
