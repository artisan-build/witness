<x-mail::message>
# Signature request declined

{{ $signer->name }} declined **{{ $witnessEnvelope->title }}**.

**Reason:** {{ $reason }}

Thanks,<br>
Witness
</x-mail::message>
