<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<body style="font-family: sans-serif">
    <p>{{ __('auth.admin_code_intro', ['name' => $accountName]) }}</p>
    <p style="font-size: 28px; letter-spacing: 6px; font-weight: bold" dir="ltr">{{ $code }}</p>
    <p>{{ __('auth.admin_code_valid', ['minutes' => $minutes]) }}</p>
    <p>{{ __('auth.admin_code_ignore') }}</p>
</body>
</html>
