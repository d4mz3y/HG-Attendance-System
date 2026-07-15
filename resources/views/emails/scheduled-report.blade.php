<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Attendance Report</title>
</head>
<body style="font-family: Arial, sans-serif; color: #111;">
    <h1>Hogan Guards Attendance Report</h1>
    <p>
        From {{ $filters['date_from'] }} to {{ $filters['date_to'] }}
        @if($filters['department'])
            · Department: {{ $filters['department'] }}
        @endif
    </p>
    <p>Please find the attached Excel report.</p>
    <p style="color: #666; font-size: 12px;">This is an automated message from Hogan Guards Attendance HQ.</p>
</body>
</html>
