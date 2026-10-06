<x-sender-layout title="Setup">
    <header class="witness-page-header" data-testid="setup-checklist">
        <div><p class="witness-kicker">Installation admin</p><h1>Setup checklist</h1></div>
    </header>
    <section class="witness-panel" data-testid="setup-domain">
        <h2>Custom domain</h2>
        @if ($cloudDomain)
            <p>Configure a custom domain before sending documents because signing and verification links preserve this host.</p>
        @else
            <p>Custom domain configured.</p>
        @endif
        <a href="https://cloud.laravel.com/docs/custom-domains" rel="noopener">Laravel Cloud custom-domain instructions</a>
    </section>
    <section class="witness-panel" data-testid="setup-mail">
        <h2>Email</h2>
        <p>{{ $mailer === 'scalpels' ? 'Sent by Scalpels' : "Your own mail configuration ({$mailer})" }}</p>
        <form method="POST" action="{{ route('sender.setup.test-mail') }}">@csrf
            <flux:button type="submit" variant="primary">Send me a test email</flux:button>
        </form>
    </section>
</x-sender-layout>
