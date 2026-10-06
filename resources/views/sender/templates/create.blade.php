<x-sender-layout title="New template">
    <section class="witness-panel witness-narrow" data-testid="new-template-form">
        <p class="witness-kicker">Reusable document</p><h1>Create a template</h1>
        <form method="POST" action="{{ route('sender.templates.store') }}" enctype="multipart/form-data" class="witness-stack">
            @csrf
            <label>Template name<input name="name" value="{{ old('name') }}" required></label>
            <label>Envelope title<input name="title" value="{{ old('title') }}" required></label>
            <label>Default message<textarea name="message" rows="4">{{ old('message') }}</textarea></label>
            <label>PDF document<input class="witness-file-input" type="file" name="document" accept="application/pdf,.pdf" required></label>
            <button class="witness-button" type="submit">Create template</button>
        </form>
    </section>
</x-sender-layout>
