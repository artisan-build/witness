@push('scripts')
    <script type="module" src="{{ asset('build/assets/sender.js') }}"></script>
    @fluxScripts
@endpush

<x-sender-layout :title="$envelope->title">
    <livewire:sender.envelope-editor :envelope-id="$envelope->getKey()" />
</x-sender-layout>
