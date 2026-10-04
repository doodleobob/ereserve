<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="font-family: Arial, sans-serif; color: #13233d; line-height: 1.6; padding: 24px;">
    <main style="max-width: 600px; margin: auto;">
        <p style="color: #1763a6; font-size: 24px; font-weight: bold;">eReserve</p>
        <h1 style="font-size: 22px;">{{ $title }}</h1>
        <p>Hello {{ $name }},</p>
        <p>{{ $activityMessage }}</p>
        <h2 style="font-size: 18px;">Reservation Details</h2>
        <table role="presentation" style="width: 100%; border-collapse: collapse;">
            @foreach ($details as $label => $value)
                <tr>
                    <td style="padding: 6px 12px 6px 0; vertical-align: top; font-weight: bold;">{{ $label }}:</td>
                    <td style="padding: 6px 0;">{{ $value }}</td>
                </tr>
            @endforeach
        </table>
        <p><a href="{{ $actionUrl }}" style="display: inline-block; background: #1763a6; color: #fff; padding: 10px 18px; border-radius: 4px; text-decoration: none;">View Reservation</a></p>
        <p>Thank you,<br>eReserve</p>
    </main>
</body>
</html>
