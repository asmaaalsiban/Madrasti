<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<body style="font-family: sans-serif">
    <p>{{ $forStudent ? __('auth.reset_mail_for_student', ['name' => $accountName]) : __('auth.reset_mail_for_self', ['name' => $accountName]) }}</p>
    <p style="font-size: 28px; letter-spacing: 6px; font-weight: bold" dir="ltr">{{ $code }}</p>
    <p>{{ __('auth.reset_mail_valid', ['minutes' => $minutes]) }}</p>
    <p>{{ __('auth.reset_mail_ignore') }}</p>
</body>
</html>
