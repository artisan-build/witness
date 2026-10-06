@props(['title'])

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $title }} - Witness</title>
    <link rel="stylesheet" href="{{ asset('build/assets/signing.css') }}">
    @stack('head')
</head>
<body>
    <header class="signing-brand"><a href="/">Witness</a><span>Private signing session</span></header>
    <main class="signing-shell">
        @if ($errors->any())
            <div class="signing-alert" role="alert" data-testid="signer-errors">
                @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </div>
        @endif
        {{ $slot }}
    </main>
    @stack('scripts')
</body>
</html>
