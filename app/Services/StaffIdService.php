<?php

namespace App\Services;

use App\Models\Staff;
use InvalidArgumentException;

class StaffIdService
{
    public function departmentCodes(): array
    {
        $codes = \App\Models\Setting::getValue('department_codes');
        
        return $codes ? json_decode($codes, true) : config('hg.department_codes', []);
    }

    public function departments(): array
    {
        $departments = \App\Models\Setting::getValue('departments');
        
        return $departments ? json_decode($departments, true) : config('hg.departments', []);
    }

    public function branchCode(string $branch): string
    {
        $codes = \App\Models\Setting::getValue('branch_codes');
        $branchCodes = $codes ? json_decode($codes, true) : config('hg.branch_codes', []);
        $branchCode = $branchCodes[$branch] ?? $branchCodes[mb_strtoupper($branch)] ?? config('hg.default_branch_code', 'LA');

        return strtoupper($branchCode);
    }

    public function departmentCode(string $department): string
    {
        $code = $this->departmentCodes()[$department] ?? null;

        if (! $code) {
            throw new InvalidArgumentException("Unknown department: {$department}");
        }

        return $code;
    }

    public function generate(string $department, string $branch = 'HQ'): string
    {
        $company = config('hg.company_code', 'HGL');
        $branchCode = $this->branchCode($branch);
        $deptCode = $this->departmentCode($department);
        $prefix = "{$company}/{$branchCode}/{$deptCode}/";

        $max = 0;
        Staff::query()
            ->where('staff_id', 'like', $prefix.'%')
            ->pluck('staff_id')
            ->each(function (string $staffId) use (&$max) {
                if (preg_match('/\/(\d{3})$/', $staffId, $matches)) {
                    $max = max($max, (int) $matches[1]);
                }
            });

        return $prefix.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    public function isValidFormat(string $staffId): bool
    {
        return (bool) preg_match(config('hg.staff_id_pattern'), $staffId);
    }
}
