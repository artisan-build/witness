<x-sender-layout title="Templates">
    <header class="witness-page-header" data-testid="template-list">
        <div><p class="witness-kicker">Reusable documents</p><h1>Templates</h1><p>Keep role-based fields separate from signer identities until send.</p></div>
        <a class="witness-button" href="{{ route('sender.templates.create') }}" data-testid="new-template">New template</a>
    </header>
    <section class="witness-list">
        @forelse ($templates as $template)
            <div class="witness-list-item">
                <a href="{{ route('sender.templates.edit', $template) }}"><strong>{{ $template->name }}</strong><span>{{ $template->roles_count }} roles · {{ $template->fields_count }} fields</span></a>
                <a class="witness-button-secondary" href="{{ route('sender.templates.send-form', $template) }}">Use template</a>
            </div>
        @empty
            <div class="witness-empty"><h2>No templates yet</h2><p>Create one from a PDF or save any draft as a template.</p></div>
        @endforelse
    </section>
</x-sender-layout>
