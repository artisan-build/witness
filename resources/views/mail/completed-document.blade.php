<x-mail::message>
# {{ $witnessEnvelope->title }} is complete

Hello {{ $delivery->recipient_name }},

Everyone has signed the document. The completed PDF includes the signatures and its Certificate of Completion.

@if ($attachFinal)
The completed PDF is attached.
@else
The completed PDF is larger than 10 MiB. Use the private link below to download it.

<x-mail::button :url="$downloadUrl">
Download completed PDF
</x-mail::button>
@endif

Thanks,<br>
Witness
</x-mail::message>
