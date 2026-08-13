<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Staff;
use Illuminate\Support\Facades\Schema;

class StaffIdService
{
    public function departmentCodes(): array
    {
        return $this->jsonSetting('department_codes', config('hg.department_codes', []));
    }

    public function departments(): array
    {
        $configured = $this->jsonSetting('departments', config('hg.departments', []));

        // Imported staff can pre-date the organisation lookup configuration.
        // Treat their current department as a valid value until an IT manager
        // explicitly reassigns them. Otherwise a harmless update (such as a
        // new profile photo) would fail validation.
        if (! Schema::hasTable('staff')) {
            return $configured;
        }

        $referenced = Staff::query()
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->distinct()
            ->orderBy('department')
            ->pluck('department')
            ->all();

        return $this->uniqueNames([...$configured, ...$referenced]);
    }

    /** @return list<string> */
    public function branches(): array
    {
        return $this->jsonSetting('branches', array_keys(config('hg.branch_codes', [])));
    }

    /** @return list<string> */
    public function companies(): array
    {
        return $this->jsonSetting('companies', array_keys(config('hg.company_codes', [])));
    }

    public function branchCode(string $branch): string
    {
        $branchCodes = $this->branchCodes();
        $branchCode = $branchCodes[$branch] ?? $branchCodes[mb_strtoupper($branch)] ?? config('hg.default_branch_code', 'LA');

        return strtoupper($branchCode);
    }

    public function branchCodes(): array
    {
        return $this->jsonSetting('branch_codes', config('hg.branch_codes', []));
    }

    public function departmentCode(string $department): string
    {
        $department = trim($department);
        $codes = $this->departmentCodes();
        $code = $codes[$department] ?? null;

        if (! $code) {
            foreach ($codes as $name => $candidate) {
                if ($this->nameKey((string) $name) === $this->nameKey($department)) {
                    $code = $candidate;

                    break;
                }
            }
        }

        if ($code) {
            return strtoupper((string) $code);
        }

        // Legacy departments may have staff IDs even when a code was never
        // saved in settings. Reuse that known code so the next generated ID
        // remains in the same series. If this is a genuinely new legacy value,
        // create a collision-free code until it is added to the organisation
        // configuration.
        $legacyCode = $this->legacyDepartmentCode($department);

        return $legacyCode ?? $this->availableDepartmentCode($department);
    }

    public function generate(string $department, string $branch = 'Lagos (HQ)', string $company = 'Hogan Guards'): string
    {
        $companyCode = $this->companyCode($company);
        $branchCode = $this->branchCode($branch);
        $deptCode = $this->departmentCode($department);
        $prefix = "{$companyCode}/{$branchCode}/{$deptCode}/";

        $max = 0;
        Staff::query()
            ->where('staff_id', 'like', $prefix.'%')
            ->pluck('staff_id')
            ->each(function (string $staffId) use (&$max) {
                if (preg_match('/\/(\d{3,6})$/', $staffId, $matches)) {
                    $max = max($max, (int) $matches[1]);
                }
            });

        return $prefix.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    public function companyCode(string $company): string
    {
        $companyCodes = $this->companyCodes();
        $code = $companyCodes[$company] ?? $companyCodes[mb_strtoupper($company)] ?? config('hg.company_codes.Hogan Guards', 'HGL');

        return strtoupper($code);
    }

    public function companyCodes(): array
    {
        return $this->jsonSetting('company_codes', config('hg.company_codes', []));
    }

    public function isValidFormat(string $staffId): bool
    {
        return (bool) preg_match(config('hg.staff_id_pattern'), $staffId);
    }

    /** @return array<mixed> */
    private function jsonSetting(string $key, array $fallback): array
    {
        $stored = Setting::getValue($key);
        if (! is_string($stored) || $stored === '') {
            return $fallback;
        }

        $decoded = json_decode($stored, true);

        return is_array($decoded) ? $decoded : $fallback;
    }

    /** @param array<int, mixed> $names
     * @return list<string>
     */
    private function uniqueNames(array $names): array
    {
        $unique = [];
        $seen = [];

        foreach ($names as $name) {
            $name = trim((string) $name);
            $key = $this->nameKey($name);
            if ($name === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $name;
        }

        return $unique;
    }

    private function nameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private function legacyDepartmentCode(string $department): ?string
    {
        if (! Schema::hasTable('staff')) {
            return null;
        }

        $staff = Staff::query()
            ->select(['department', 'staff_id'])
            ->orderBy('id')
            ->get()
            ->first(function (Staff $staff) use ($department): bool {
                return $this->nameKey((string) $staff->department) === $this->nameKey($department)
                    && preg_match('/^[A-Z]{2,4}\/[A-Z]{2,4}\/([A-Z]{2,4})\/\d{3,6}$/', (string) $staff->staff_id) === 1;
            });

        return $staff ? $this->staffIdDepartmentCode((string) $staff->staff_id) : null;
    }

    private function staffIdDepartmentCode(string $staffId): ?string
    {
        if (preg_match('/^[A-Z]{2,4}\/[A-Z]{2,4}\/([A-Z]{2,4})\/\d{3,6}$/', $staffId, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function availableDepartmentCode(string $department): string
    {
        $used = array_map('strtoupper', array_values($this->departmentCodes()));
        if (Schema::hasTable('staff')) {
            Staff::query()->pluck('staff_id')->each(function (string $staffId) use (&$used): void {
                $code = $this->staffIdDepartmentCode($staffId);
                if ($code) {
                    $used[] = $code;
                }
            });
        }
        $used = array_values(array_unique($used));

        $words = preg_split('/[^A-Za-z]+/', strtoupper($department), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($department)) ?: 'DEPT';
        $initials = implode('', array_map(fn (string $word): string => $word[0], $words));
        $candidates = array_unique(array_filter([
            substr($initials, 0, 4),
            substr($letters, 0, 4),
            substr($letters, 0, 3),
            substr($letters, 0, 2),
        ], fn (string $candidate): bool => strlen($candidate) >= 2));

        foreach ($candidates as $candidate) {
            if (! in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        $stem = substr(str_pad($letters, 2, 'X'), 0, 2);
        for ($index = 0; $index < 676; $index++) {
            $suffix = $index < 26
                ? chr(65 + $index)
                : chr(65 + intdiv($index - 26, 26)).chr(65 + (($index - 26) % 26));
            $candidate = substr($stem, 0, 4 - strlen($suffix)).$suffix;
            if (! in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        // Four characters plus a two-character suffix provides 676 distinct
        // options for a stem, so reaching this is practically impossible.
        return 'DEPT';
    }
}
