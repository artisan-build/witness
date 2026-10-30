<x-sender-layout title="Setup">
    <header class="witness-page-header witness-page-header-compact" data-testid="setup-checklist">
        <div>
            <p class="witness-kicker">Installation admin</p>
            <h1>Setup</h1>
            <p>Two things to finish before you send your first envelope.</p>
        </div>
    </header>
    <ol class="witness-checklist">
        <li @class(['witness-panel', 'is-done' => ! $cloudDomain]) data-testid="setup-domain">
            <span class="witness-step-marker">{{ $cloudDomain ? '1' : '✓' }}</span>
            <div>
                <h2>Custom domain</h2>
                @if ($cloudDomain)
                    <p>Signing and verification links keep the address they were created with, so set your permanent domain before sending anything.</p>
                    <a class="witness-link" href="{{ \App\Support\SetupInstructions::CUSTOM_DOMAIN_URL }}" rel="noopener">How to add a custom domain on Laravel Cloud</a>
                @else
                    <p>Custom domain configured.</p>
                @endif
            </div>
        </li>
        <li class="witness-panel" data-testid="setup-mail">
            <span class="witness-step-marker">2</span>
            <div>
                <h2>Email</h2>
                <p>{{ $mailer === 'scalpels' ? 'Sent by Scalpels' : "Your own mail configuration ({$mailer})" }}. Send yourself a test to confirm invitations will arrive.</p>
                <form method="POST" action="{{ route('sender.setup.test-mail') }}">@csrf
                    <button class="witness-button-secondary" type="submit">Send me a test email</button>
                </form>
            </div>
        </li>
    </ol>
    <details class="witness-panel witness-setup-instructions" data-testid="setup-instructions">
        <summary><h2>Operator guide</h2></summary>
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
    </details>
</x-sender-layout>
