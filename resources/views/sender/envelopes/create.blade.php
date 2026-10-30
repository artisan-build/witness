<x-sender-layout title="New envelope">
    <section class="witness-panel witness-narrow" data-testid="new-envelope-form">
        <p class="witness-kicker">New envelope</p>
        <h1>Start with a PDF</h1>
        <p>The original is stored privately. Maximum 20 MiB and 100 pages.</p>
        <form method="POST" action="{{ route('sender.envelopes.store') }}" enctype="multipart/form-data" class="witness-stack">
            @csrf
            <label>Envelope title<input name="title" value="{{ old('title') }}" required maxlength="255"></label>
            <label>PDF document<input class="witness-file-input" type="file" name="document" accept="application/pdf,.pdf" required data-testid="pdf-upload"></label>
            <button class="witness-button" type="submit" data-testid="create-envelope">Create draft</button>
        </form>
    </section>
</x-sender-layout>
