<nav class="bfc-sidebar-links witness-sidebar"
     aria-label="Witness"
     data-testid="sender-navigation">
    <a href="{{ route('sender.dashboard') }}"
       class="bfc-shell-link"
       @if ($onEnvelopes && collect($filters)->doesntContain('current', true)) aria-current="page" @endif
       data-testid="envelopes-navigation">
        Envelopes
    </a>
    <div class="witness-sidebar-filters">
        @foreach ($filters as $item)
            <a href="{{ route('sender.dashboard', ['status' => $item['filter']->value]) }}"
               class="witness-sidebar-filter"
               @if ($item['current']) aria-current="page" @endif
               data-testid="envelope-filter-{{ $item['filter']->value }}">
                <span>{{ $item['filter']->label() }}</span>
                <span class="witness-count">{{ $item['count'] }}</span>
            </a>
        @endforeach
    </div>
    <a href="{{ route('sender.templates.index') }}"
       class="bfc-shell-link"
       @if ($onTemplates) aria-current="page" @endif
       data-testid="templates-navigation">
        Templates
    </a>
</nav>
