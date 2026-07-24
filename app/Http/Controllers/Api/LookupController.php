<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LookupController extends Controller
{
    public function __construct(
        protected \App\Services\StaffIdService $staffIds
    ) {}

    private function ensureSuperAdmin(Request $request): void
    {
        if ($request->user()->role !== 'super_admin') {
            throw ValidationException::withMessages([
                'access' => ['Only super admins can perform this action.'],
            ]);
        }
    }

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

    public function companies()
    {
        $companies = Setting::getValue('companies', json_encode(['Hogan Guards', 'Hogan Technology', 'Hogan Logistics', 'Hogan Cleaning', 'Hogan Maintenance', 'Hogan Security']));
        
        return response()->json(json_decode($companies, true) ?: ['Hogan Guards', 'Hogan Technology', 'Hogan Logistics', 'Hogan Cleaning', 'Hogan Maintenance', 'Hogan Security']);
    }

    public function updateCompanies(Request $request)
    {
        $this->ensureSuperAdmin($request);
        $data = $request->validate([
            'companies' => ['required', 'array', 'min:1', 'max:20'],
            'companies.*' => ['string', 'max:128', 'distinct'],
        ]);

        $existingCodes = config('hg.company_codes', []);
        $prefixes = ['HGL', 'HTL', 'HLL', 'HCL', 'HMN', 'HSC', 'HTC', 'HFS', 'HHR', 'HIT'];
        $codes = [];
        $nextIndex = 0;

        foreach ($data['companies'] as $name) {
            if (isset($existingCodes[$name])) {
                $codes[$name] = $existingCodes[$name];
                continue;
            }

            $assigned = false;
            foreach ($prefixes as $prefix) {
                if (! in_array($prefix, $existingCodes, true) && ! in_array($prefix, $codes, true)) {
                    $codes[$name] = $prefix;
                    $assigned = true;
                    break;
                }
            }

            if (! $assigned) {
                $codes[$name] = 'HC'.($nextIndex + 1);
            }

            $nextIndex++;
        }

        \App\Models\Setting::setValue('companies', json_encode($data['companies']));
        \App\Models\Setting::setValue('company_codes', json_encode($codes));

        return response()->json(['ok' => true, 'companies' => $data['companies']]);
    }

    public function updateDepartments(Request $request)
    {
        $this->ensureSuperAdmin($request);
        $data = $request->validate([
            'departments' => ['required', 'array', 'min:1', 'max:20'],
            'departments.*' => ['string', 'max:128', 'distinct'],
        ]);

        \App\Models\Setting::setValue('departments', json_encode($data['departments']));

        $existingCodes = $this->staffIds->departmentCodes();
        $prefixes = ['OPS', 'SEC', 'ADM', 'FIN', 'MGT', 'BOD', 'HR', 'IT', 'LOG', 'SAL', 'ACC', 'MKT', 'CLN', 'DRV', 'WKR'];
        $codes = [];
        $nextIndex = 0;

        foreach ($data['departments'] as $name) {
            if (isset($existingCodes[$name])) {
                continue;
            }

            $assigned = false;
            foreach ($prefixes as $prefix) {
                if (! in_array($prefix, $existingCodes, true) && ! in_array($prefix, array_values($codes ?? []), true)) {
                    $codes[$name] = $prefix;
                    $assigned = true;
                    break;
                }
            }

            if (! $assigned) {
                $codes[$name] = 'D'.($nextIndex + 1);
            }

            $nextIndex++;
        }

        $codes = array_merge($existingCodes, $codes ?? []);
        \App\Models\Setting::setValue('department_codes', json_encode($codes));

        return response()->json(['ok' => true, 'departments' => $data['departments']]);
    }

    public function updateBranches(Request $request)
    {
        $this->ensureSuperAdmin($request);
        $data = $request->validate([
            'branches' => ['required', 'array', 'min:1', 'max:20'],
            'branches.*' => ['string', 'max:128', 'distinct'],
        ]);

        \App\Models\Setting::setValue('branches', json_encode($data['branches']));

        return response()->json(['ok' => true, 'branches' => $data['branches']]);
    }
}
