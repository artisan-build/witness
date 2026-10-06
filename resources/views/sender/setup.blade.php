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
        <a class="witness-link" href="{{ \App\Support\SetupInstructions::CUSTOM_DOMAIN_URL }}" rel="noopener">Laravel Cloud custom-domain instructions</a>
    </section>
    <section class="witness-panel" data-testid="setup-mail">
        <h2>Email</h2>
        <p>{{ $mailer === 'scalpels' ? 'Sent by Scalpels' : "Your own mail configuration ({$mailer})" }}</p>
        <form method="POST" action="{{ route('sender.setup.test-mail') }}">@csrf
            <button class="witness-button" type="submit">Send me a test email</button>
        </form>
    </section>
    <section class="witness-panel witness-setup-instructions" data-testid="setup-instructions">
        <p class="witness-kicker">Operator guide</p>
        <h2>Before the first envelope</h2>
        @foreach ($instructions as $section)
            <section data-testid="setup-instruction-{{ $section['id'] }}">
                <h3>{{ $section['title'] }}</h3>
                @foreach ($section['paragraphs'] as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach
                @if ($section['bullets'] !== [])
                    <ul>
                        @foreach ($section['bullets'] as $bullet)
                            <li>{{ $bullet }}</li>
                        @endforeach
                    </ul>
                @endif
                @if ($section['link'] !== null)
                    <a class="witness-link" href="{{ $section['link']['url'] }}" rel="noopener">{{ $section['link']['label'] }}</a>
                @endif
            </section>
        @endforeach
    </section>
</x-sender-layout>
