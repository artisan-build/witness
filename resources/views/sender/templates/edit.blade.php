@push('scripts')
    <script type="module" src="{{ asset('build/assets/sender.js') }}"></script>
    @fluxScripts
@endpush

<x-sender-layout :title="$template->name">
    <livewire:sender.template-editor :template-id="$template->getKey()" />
</x-sender-layout>
