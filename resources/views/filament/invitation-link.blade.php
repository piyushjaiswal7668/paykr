<x-filament::section heading="Invitation ready">
    <p>Copy this password setup link and send it privately to the invited person. No invitation email has been sent.</p>
    <p class="mt-3 break-all">
        <a href="{{ $invitationUrl }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $invitationUrl }}</a>
    </p>
    <p class="mt-3">The recipient can use this link to choose their password. The link expires; share it promptly.</p>
</x-filament::section>
