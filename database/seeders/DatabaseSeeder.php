<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Setting::setValue('shift_start', '08:00');
        Setting::setValue('shift_end', '17:00');
        Setting::setValue('scan_debounce_seconds', '120');
        Setting::setValue('branch_label', 'Headquarters');
        Setting::setValue('branches', json_encode(['Lagos (HQ)', 'Abuja', 'Ibadan']));
        Setting::setValue('departments', json_encode(['Board of Directors', 'Management', 'Operations', 'Admin', 'Finance', 'Security']));
        Setting::setValue('department_codes', json_encode([
            'Board of Directors' => 'BOD',
            'Management' => 'MGT',
            'Operations' => 'OPS',
            'Admin' => 'ADM',
            'Finance' => 'FIN',
            'Security' => 'SEC',
        ]));

        User::query()->updateOrCreate(
            ['username' => 'admin'],
            ['password' => Hash::make('admin123'), 'role' => 'admin']
        );

        User::query()->updateOrCreate(
            ['username' => 'superadmin'],
            ['password' => Hash::make('super123'), 'role' => 'super_admin']
        );

        $staff = [
            ['staff_id' => 'HGL/LA/OPS/001', 'full_name' => 'Adaeze Okonkwo', 'department' => 'Operations', 'job_title' => 'Shift Supervisor', 'branch' => 'HQ'],
            ['staff_id' => 'HGL/LA/SEC/001', 'full_name' => 'Chinedu Eze', 'department' => 'Security', 'job_title' => 'Security Officer', 'branch' => 'HQ'],
            ['staff_id' => 'HGL/LA/ADM/001', 'full_name' => 'Fatima Bello', 'department' => 'Admin', 'job_title' => 'HR Assistant', 'branch' => 'HQ'],
            ['staff_id' => 'HGL/LA/FIN/001', 'full_name' => 'Ibrahim Musa', 'department' => 'Finance', 'job_title' => 'Payroll Clerk', 'branch' => 'HQ'],
            ['staff_id' => 'HGL/LA/SEC/002', 'full_name' => 'Ngozi Adeyemi', 'department' => 'Security', 'job_title' => 'Control Room Operator', 'branch' => 'HQ'],
            ['staff_id' => 'HGL/LA/OPS/002', 'full_name' => 'Tunde Okafor', 'department' => 'Operations', 'job_title' => 'Logistics Coordinator', 'branch' => 'HQ'],
            ['staff_id' => 'HGL/LA/BOD/001', 'full_name' => 'Helen Hogan', 'department' => 'Board of Directors', 'job_title' => 'Chairperson', 'branch' => 'HQ'],
            ['staff_id' => 'HGL/LA/MGT/001', 'full_name' => 'Samuel Adebayo', 'department' => 'Management', 'job_title' => 'General Manager', 'branch' => 'HQ'],
        ];

        foreach ($staff as $row) {
            Staff::query()->updateOrCreate(
                ['staff_id' => $row['staff_id']],
                array_merge($row, ['employment_status' => 'Active'])
            );
        }
    }
}
