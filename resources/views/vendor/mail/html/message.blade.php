@props(['brand' => null, 'settingsUrl' => null])
<x-mail::layout>
{{-- Header: the practice's logo or name in a client's email, otherwise AstroLabe (MailLook). --}}
<x-slot:header>
<x-mail::header :url="$brand['url'] ?? config('app.url')">
@if (! empty($brand['logo']))
<img src="{{ $brand['logo'] }}" class="logo" alt="{{ $brand['name'] }}" style="height: auto; max-height: 56px; width: auto; max-width: 240px;">
@else
{{ $brand['name'] ?? __('mail_layout.header', ['app' => config('app.name')]) }}
@endif
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer: where to choose notifications, then the operator's footer text. --}}
<x-slot:footer>
<x-mail::footer>
@if ($settingsUrl)
[{{ __('mail_layout.settings_link') }}]({{ $settingsUrl }})

@endif
@if (! empty($brand['name']))
{{ __('mail_layout.footer_client', ['practice' => $brand['name'], 'app' => config('app.name'), 'year' => date('Y')]) }}

@endif
{{ __('mail_layout.footer', ['year' => date('Y'), 'app' => config('app.name')]) }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
