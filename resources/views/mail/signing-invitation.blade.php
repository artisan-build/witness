<x-mail::message>
# {{ $signer->name }}, your signature is requested

{{ $witnessEnvelope->sender->name }} has asked you to review and sign **{{ $witnessEnvelope->title }}**.

<x-mail::button :url="route('sign.open', $linkToken)">
Review and sign
</x-mail::button>

You will receive a six-digit verification code after opening this private link. Do not forward it.

Thanks,<br>
Witness
</x-mail::message>
