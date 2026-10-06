<x-signing-layout title="Verify a completed document">
    <section class="signing-card" data-testid="verify-upload">
        <p class="signing-eyebrow">Document verification</p>
        <h1>Verify a completed PDF</h1>
        <p>Upload a completed PDF from Witness. The file is hashed for this request and is not stored.</p>

        <form method="post" action="{{ route('verify.store') }}" enctype="multipart/form-data" class="signing-form">
            @csrf
            <label for="verification_document">Completed PDF</label>
            <input id="verification_document" name="verification_document" type="file" accept="application/pdf,.pdf" required>
            <button type="submit" class="signing-button">Verify PDF</button>
        </form>
    </section>
</x-signing-layout>
