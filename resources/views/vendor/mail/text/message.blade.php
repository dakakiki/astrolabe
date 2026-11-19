@props(['brand' => null, 'settingsUrl' => null])
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="$brand['url'] ?? config('app.url')">
            {{ $brand['name'] ?? __('mail_layout.header', ['app' => config('app.name')]) }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            @if ($settingsUrl)
                {{ __('mail_layout.settings_link') }}: {{ $settingsUrl }}
            @endif
            @if (! empty($brand['name']))
                {{ __('mail_layout.footer_client', ['practice' => $brand['name'], 'app' => config('app.name'), 'year' => date('Y')]) }}
            @endif
            {{ __('mail_layout.footer', ['year' => date('Y'), 'app' => config('app.name')]) }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
