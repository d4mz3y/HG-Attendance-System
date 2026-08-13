<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Hogan Guards Attendance Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #111; }
        h1 { font-size: 16px; margin-bottom: 4px; }
        .meta { color: #555; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 4px; text-align: left; }
        th { background: #f3f4f6; font-weight: 600; }
        tr:nth-child(even) { background: #fafafa; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">
        {{ $filters['date_from'] }} to {{ $filters['date_to'] }}
        @foreach(['company' => 'Company', 'branch' => 'Branch', 'department' => 'Department'] as $key => $label)
            @if(!empty($filters[$key])) · {{ $label }}: {{ $filters[$key] }} @endif
        @endforeach
    </div>
    <table>
        <thead><tr>
            <th>Name</th><th>Staff ID</th><th>Company</th><th>Branch</th><th>Department</th>
            <th>Date</th><th>Holiday</th><th>Session</th><th>In</th><th>Out</th><th>Hours</th><th>Break</th>
            <th>Late</th><th>OT</th><th>Notes</th><th>Status</th>
        </tr></thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row['full_name'] }}</td><td>{{ $row['staff_code'] }}</td>
                    <td>{{ $row['company'] ?? '' }}</td><td>{{ $row['branch'] ?? '' }}</td><td>{{ $row['department'] }}</td>
                    <td>{{ $row['date'] }}</td><td>{{ $row['holiday_name'] ?? '' }}</td><td>{{ $row['session_number'] ?? '' }}</td>
                    <td>{{ $row['clock_in'] }}</td><td>{{ $row['clock_out'] }}</td><td>{{ $row['total_hours'] }}</td>
                    <td>{{ $row['break_minutes'] ?? '' }}</td><td>{{ $row['late_minutes'] }}</td><td>{{ $row['overtime_minutes'] }}</td>
                    <td>{{ $row['notes'] ?? '' }}</td><td>{{ $row['status'] }}</td>
                </tr>
            @empty
                <tr><td colspan="16">No matching attendance records.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
