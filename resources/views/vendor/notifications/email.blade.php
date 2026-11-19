{{--
    Every notification email (Laravel's template, AstroLabe's frame): a practice's header and
    signature for a client (MailLook), the operator's wording from Admin → Notifications
    through the translator, and lines worded empty left out instead of shown as blank space.
--}}
@php
    $signedBy = $brand['name'] ?? config('app.name');
    $shown = fn ($line) => trim(strip_tags((string) $line)) !== '';
    $salutationText = filled($salutation ?? null) ? $salutation : __('mail_layout.salutation', ['app' => $signedBy]);
@endphp
<x-mail::message :brand="$brand ?? null" :settings-url="$settingsUrl ?? null">
{{-- Greeting --}}
# {{ filled($greeting ?? null) ? $greeting : __('mail_layout.greeting') }}

{{-- Intro Lines --}}
@foreach ($introLines as $line)
@if ($shown($line))
{{ $line }}

@endif
@endforeach

{{-- Action Button --}}
@isset($actionText)
<x-mail::button :url="$actionUrl" color="primary">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
@if ($shown($line))
{{ $line }}

@endif
@endforeach

{{-- Salutation --}}
@if (trim((string) $salutationText) !== '')
{!! nl2br(e($salutationText)) !!}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
{{ __('mail_layout.subcopy', ['actionText' => $actionText]) }} <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
