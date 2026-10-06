<x-bfc-layout :title="$manifest->name">
<section data-testid="landing"
         data-app-slug="{{ $manifest->slug }}"
         class="grid gap-8 py-4
                lg:py-10">
    <div class="flex flex-wrap items-center gap-x-8 gap-y-5">
        <img data-testid="landing-manifest-icon"
             src="{{ $manifest->imageUrl() }}"
             alt="{{ $manifest->name }}"
             width="128"
             height="128"
             class="size-24 shrink-0 object-contain
                    sm:size-32">
        <div class="grid gap-3">
            <p class="text-lg text-clay-soft">Send a PDF. Collect signatures. Keep the proof.</p>
            <h1 data-testid="landing-manifest-name"
                class="font-display text-4xl font-normal tracking-tight text-balance text-clay-ink
                       sm:text-6xl">
                {{ $manifest->name }}
            </h1>
        </div>
    </div>

    <div class="grid gap-3 max-w-2xl">
        <p data-testid="landing-manifest-description"
           class="text-lg leading-8 text-pretty text-clay-soft
                  sm:text-xl">
            {{ $manifest->description }}
        </p>
        <p class="text-lg leading-8 text-pretty text-clay-soft">
            Prepare one PDF, invite parallel signers, collect typed or drawn simple electronic signatures,
            and retain a completion certificate with the audit evidence. Witness does not provide legal advice,
            notarisation, or advanced or qualified signatures.
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <a data-testid="landing-ui-entry"
           href="{{ route('bfc.dashboard') }}"
           class="bfc-button">
            Open Witness
        </a>
        <a data-testid="landing-verify-entry"
           href="{{ route('verify.index') }}"
           class="bfc-button-soft">
            Verify a completed PDF
        </a>
        <a data-testid="landing-manifest-product-link"
           href="{{ $manifest->productUrl }}"
           class="bfc-button-soft">
            About {{ $manifest->name }} on Scalpels
        </a>
    </div>
</section>
</x-bfc-layout>
