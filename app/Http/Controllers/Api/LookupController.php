<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class LookupController extends Controller
{
    public function __construct(
        protected \App\Services\StaffIdService $staffIds
    ) {}

    public function departments()
    {
        return response()->json($this->staffIds->departments());
    }

    public function staffOptions()
    {
        return \App\Models\Staff::query()
            ->where('employment_status', 'Active')
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'staff_id', 'department']);
    }

    public function branches()
    {
        $branches = Setting::getValue('branches', json_encode(['Lagos (HQ)', 'Abuja', 'Ibadan']));
        
        return response()->json(json_decode($branches, true) ?: ['Lagos (HQ)', 'Abuja', 'Ibadan']);
    }

    public function updateDepartments(Request $request)
    {
        $data = $request->validate([
            'departments' => ['required', 'array', 'min:1', 'max:20'],
            'departments.*' => ['string', 'max:128', 'distinct'],
        ]);

        \App\Models\Setting::setValue('departments', json_encode($data['departments']));

        $codes = [];
        $prefixes = ['OPS', 'SEC', 'ADM', 'FIN', 'MGT', 'BOD', 'HR', 'IT', 'LOG', 'SAL', 'ACC', 'MKT', 'CLN', 'DRV', 'WKR'];
        foreach ($data['departments'] as $index => $name) {
            $codes[$name] = strtoupper($prefixes[$index] ?? 'D'.($index + 1));
        }
        \App\Models\Setting::setValue('department_codes', json_encode($codes));

        return response()->json(['ok' => true, 'departments' => $data['departments']]);
    }

    public function updateBranches(Request $request)
    {
        $data = $request->validate([
            'branches' => ['required', 'array', 'min:1', 'max:20'],
            'branches.*' => ['string', 'max:128', 'distinct'],
        ]);

        \App\Models\Setting::setValue('branches', json_encode($data['branches']));

        return response()->json(['ok' => true, 'branches' => $data['branches']]);
    }
}
