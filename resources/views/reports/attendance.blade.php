<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Hogan Guards Attendance Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .meta { color: #555; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        th { background: #f3f4f6; font-weight: 600; }
        tr:nth-child(even) { background: #fafafa; }
    </style>
</head>
<body>
    <h1>Attendance Report</h1>
    <div class="meta">
        From {{ $filters['date_from'] }} to {{ $filters['date_to'] }}
        @if($filters['department'])
            · Department: {{ $filters['department'] }}
        @endif
    </div>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Staff ID</th>
                <th>Department</th>
                <th>Date</th>
                <th>In</th>
                <th>Out</th>
                <th>Hours</th>
                <th>Late</th>
                <th>OT</th>
                <th>Notes</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                @if($row['status'] === 'Public Holiday Work')
                    @continue
                @endif
                <tr>
                    <td>{{ $row['full_name'] }}</td>
                    <td>{{ $row['staff_code'] }}</td>
                    <td>{{ $row['department'] }}</td>
                    <td>{{ $row['date'] }}</td>
                    <td>{{ $row['clock_in'] }}</td>
                    <td>{{ $row['clock_out'] }}</td>
                    <td>{{ $row['total_hours'] }}</td>
                    <td>{{ $row['late_minutes'] }}</td>
                    <td>{{ $row['overtime_minutes'] }}</td>
                    <td>{{ $row['notes'] ?? '' }}</td>
                    <td>{{ $row['status'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @php
        $publicHolidayRows = $rows->filter(fn($r) => $r['status'] === 'Public Holiday Work');
    @endphp
    @if($publicHolidayRows->isNotEmpty())
        <h2 style="margin-top: 24px; font-size: 16px;">Public Holiday Work Records</h2>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Staff ID</th>
                    <th>Department</th>
                    <th>Date</th>
                    <th>In</th>
                    <th>Out</th>
                    <th>Hours</th>
                    <th>Late</th>
                    <th>OT</th>
                    <th>Notes</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($publicHolidayRows as $row)
                    <tr>
                        <td>{{ $row['full_name'] }}</td>
                        <td>{{ $row['staff_code'] }}</td>
                        <td>{{ $row['department'] }}</td>
                        <td>{{ $row['date'] }}</td>
                        <td>{{ $row['clock_in'] }}</td>
                        <td>{{ $row['clock_out'] }}</td>
                        <td>{{ $row['total_hours'] }}</td>
                        <td>{{ $row['late_minutes'] }}</td>
                        <td>{{ $row['overtime_minutes'] }}</td>
                        <td>{{ $row['notes'] ?? '' }}</td>
                        <td>{{ $row['status'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
