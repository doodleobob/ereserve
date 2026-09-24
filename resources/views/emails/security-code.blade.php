<!DOCTYPE html>
<html lang="en">
<body style="font-family: Arial, sans-serif; color: #13233d; line-height: 1.6; padding: 24px;">
    <h1>eReserve security code</h1>
    <p>{{ $purpose === 'login' ? 'Use this code to finish signing in.' : 'Use this code to enable email-based two-factor authentication.' }}</p>
    <p style="font-size: 32px; font-weight: bold; letter-spacing: 6px;">{{ $code }}</p>
    <p>This code expires in 5 minutes and can only be used once. Do not share it.</p>
    <p>If you did not request this code, you can ignore this email.</p>
</body>
</html>
