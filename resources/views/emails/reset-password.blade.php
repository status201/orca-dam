<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('app.url')">
<img src="{{ $message->embed(public_path('images/orca-logo.png')) }}" alt="{{ config('app.name') }}" height="48" style="display:block;margin:0 auto;border:0;">
</x-mail::header>
</x-slot:header>

# {{ __('Hello!') }}

{{ __('You are receiving this email because we received a password reset request for your account.') }}

<x-mail::button :url="$resetUrl">
{{ __('Reset Password') }}
</x-mail::button>

{{ __('This password reset link will expire in :count minutes.', ['count' => $expireMinutes]) }}

{{ __('If you did not request a password reset, no further action is required.') }}

{{ __('Regards') }},<br>{{ config('app.name') }}

<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
