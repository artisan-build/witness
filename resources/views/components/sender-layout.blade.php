@props(['title'])

@push('head')
    <link rel="stylesheet" href="{{ asset('build/assets/sender.css') }}">
    @fluxAppearance
@endpush

<x-bfc-layout :title="$title.' - Witness'">
    <x-slot:sidebar>
        <x-sender-navigation />
    </x-slot:sidebar>

    <x-slot:sidebarFooter>
        <x-sender-navigation footer />
    </x-slot:sidebarFooter>

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
