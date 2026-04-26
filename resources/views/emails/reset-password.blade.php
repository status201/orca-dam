<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light only">
<meta name="supported-color-schemes" content="light only">
<title>{{ __('Reset Password Notification') }}</title>
<style>
    @media only screen and (max-width: 600px) {
        .orca-card { width: 100% !important; border-radius: 0 !important; }
        .orca-px { padding-left: 24px !important; padding-right: 24px !important; }
        .orca-button a { display: block !important; width: 100% !important; box-sizing: border-box; }
    }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2937;-webkit-font-smoothing:antialiased;">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f3f4f6;">
{{ __('You are receiving this email because we received a password reset request for your account.') }}
</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" class="orca-card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
                <tr>
                    <td align="center" style="background:linear-gradient(135deg,#006666 0%,#66b2b2 100%);padding:40px 24px;">
                        <img src="{{ $message->embed(public_path('images/orca-logo.png')) }}" alt="{{ config('app.name') }}" height="64" style="display:block;border:0;outline:none;text-decoration:none;height:64px;width:auto;margin:0 auto 12px;filter:brightness(0) invert(1);">
                        <div style="color:#ffffff;font-size:20px;font-weight:600;letter-spacing:0.5px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">{{ config('app.name') }}</div>
                    </td>
                </tr>
                <tr>
                    <td class="orca-px" style="padding:40px 48px 16px;">
                        <h1 style="margin:0 0 16px;font-size:24px;line-height:1.3;font-weight:700;color:#111827;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">{{ __('Hello!') }}</h1>
                        <p style="margin:0 0 24px;font-size:16px;line-height:1.6;color:#374151;">
                            {{ __('You are receiving this email because we received a password reset request for your account.') }}
                        </p>
                    </td>
                </tr>
                <tr>
                    <td class="orca-px" align="center" style="padding:0 48px 32px;">
                        <table role="presentation" class="orca-button" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
                            <tr>
                                <td align="center" style="border-radius:8px;background-color:#006666;box-shadow:0 2px 6px rgba(0,102,102,0.25);">
                                    <a href="{{ $resetUrl }}" target="_blank" style="display:inline-block;padding:14px 36px;font-size:16px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                                        {{ __('Reset Password') }}
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td class="orca-px" style="padding:0 48px 24px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f0f9f9;border-left:3px solid #66b2b2;border-radius:4px;">
                            <tr>
                                <td style="padding:14px 18px;font-size:14px;line-height:1.5;color:#0f5b5b;">
                                    {{ __('This password reset link will expire in :count minutes.', ['count' => $expireMinutes]) }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td class="orca-px" style="padding:0 48px 24px;">
                        <p style="margin:0;font-size:14px;line-height:1.6;color:#6b7280;">
                            {{ __('If you did not request a password reset, no further action is required.') }}
                        </p>
                    </td>
                </tr>
                <tr>
                    <td class="orca-px" style="padding:0 48px 32px;">
                        <p style="margin:0;font-size:15px;line-height:1.6;color:#374151;">
                            {{ __('Regards') }},<br>
                            <strong style="color:#111827;">{{ config('app.name') }}</strong>
                        </p>
                    </td>
                </tr>
                <tr>
                    <td class="orca-px" style="padding:0 48px 40px;">
                        <hr style="border:0;border-top:1px solid #e5e7eb;margin:0 0 20px;">
                        <p style="margin:0 0 8px;font-size:12px;line-height:1.5;color:#9ca3af;">
                            {{ __('If you\'re having trouble clicking the ":actionText" button, copy and paste the URL below into your web browser:', ['actionText' => __('Reset Password')]) }}
                        </p>
                        <p style="margin:0;font-size:12px;line-height:1.5;word-break:break-all;">
                            <a href="{{ $resetUrl }}" target="_blank" style="color:#006666;text-decoration:underline;">{{ $resetUrl }}</a>
                        </p>
                    </td>
                </tr>
            </table>
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">
                <tr>
                    <td align="center" style="padding:24px 16px 8px;font-size:12px;line-height:1.5;color:#9ca3af;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                        © {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:0 16px 24px;font-size:11px;line-height:1.5;color:#b1b5bd;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                        {{ __('This is an automated message. Please do not reply to this email.') }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
