<x-sender-layout :title="'Use '.$template->name">
    <section class="witness-panel witness-narrow" data-testid="template-mapping">
        <p class="witness-kicker">Create a draft</p><h1>Map template roles</h1><p>{{ $template->name }} stays unchanged. A new private draft will be created.</p>
        <form method="POST" action="{{ route('sender.templates.send', $template) }}" class="witness-stack">@csrf
            @foreach ($template->roles as $role)
                <fieldset class="witness-role-map"><legend>{{ $role->label }}</legend><label>Name<input name="roles[{{ $role->getKey() }}][name]" required></label><label>Email<input type="email" name="roles[{{ $role->getKey() }}][email]" required></label></fieldset>
            @endforeach
            <label>Message<textarea name="message" rows="4">{{ $template->message }}</textarea></label><label>Expires on<input type="date" name="expires_at"></label>
            <button class="witness-button" type="submit" data-testid="instantiate-template">Create draft for review</button>
        </form>
    </section>
</x-sender-layout>
