<?php

namespace App\Http\Controllers\Api;

use App\Auth\Permission;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Staff;
use App\Services\StaffIdService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LookupController extends Controller
{
    private const MAX_LOOKUP_VALUES = 100;

    public function __construct(
        protected StaffIdService $staffIds
    ) {}

    private function ensureOrganizationManager(Request $request): void
    {
        abort_unless(
            $request->user()?->hasPermission(Permission::ORGANIZATION_MANAGE),
            403,
            'You do not have permission to manage organization details.'
        );
    }

    public function departments()
    {
        // Older imports can contain departments that pre-date the configured
        // lookup list. Returning the effective list keeps those records
        // editable and, importantly, prevents the Organization screen from
        // accidentally submitting a list that omits existing staff values.
        return response()->json($this->effectiveValues(
            $this->staffIds->departments(),
            'department'
        ));
    }

    public function staffOptions()
    {
        return Staff::query()
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
        $this->ensureOrganizationManager($request);
        $data = $request->validate([
            'companies' => ['required', 'array', 'min:1', 'max:20'],
            'companies.*' => ['required', 'string', 'max:128', 'distinct:ignore_case'],
        ]);
        $data['companies'] = $this->normalizedNames($data['companies'], 'companies');

        $this->rejectRemovalOfReferencedValues('company', $data['companies'], 'companies');
        $existingCodes = $this->staffIds->companyCodes();
        $codes = [];

        foreach ($data['companies'] as $name) {
            if (isset($existingCodes[$name])) {
                $codes[$name] = $existingCodes[$name];

                continue;
            }

            $codes[$name] = $this->uniqueCode($name, array_merge(array_values($existingCodes), array_values($codes)), 2, 4);
        }

        DB::transaction(function () use ($data, $codes): void {
            Setting::setValue('companies', json_encode($data['companies'], JSON_THROW_ON_ERROR));
            Setting::setValue('company_codes', json_encode($codes, JSON_THROW_ON_ERROR));
        });

        return response()->json(['ok' => true, 'companies' => $data['companies']]);
    }

    public function updateDepartments(Request $request)
    {
        $this->ensureOrganizationManager($request);
        $data = $request->validate([
            'departments' => ['required', 'array', 'min:1', 'max:'.self::MAX_LOOKUP_VALUES],
            'departments.*' => ['required', 'string', 'max:128', 'distinct:ignore_case'],
        ]);
        $data['departments'] = $this->normalizedNames($data['departments'], 'departments');

        $this->rejectRemovalOfReferencedValues('department', $data['departments'], 'departments');

        $existingCodes = $this->staffIds->departmentCodes();
        $codes = [];

        foreach ($data['departments'] as $name) {
            if (isset($existingCodes[$name])) {
                $codes[$name] = $existingCodes[$name];

                continue;
            }

            $codes[$name] = $this->uniqueCode(
                $name,
                array_merge(array_values($existingCodes), array_values($codes)),
                2,
                4
            );
        }

        DB::transaction(function () use ($data, $codes): void {
            Setting::setValue('departments', json_encode($data['departments'], JSON_THROW_ON_ERROR));
            Setting::setValue('department_codes', json_encode($codes, JSON_THROW_ON_ERROR));
        });

        return response()->json(['ok' => true, 'departments' => $data['departments']]);
    }

    public function updateBranches(Request $request)
    {
        $this->ensureOrganizationManager($request);
        $data = $request->validate([
            'branches' => ['required', 'array', 'min:1', 'max:20'],
            'branches.*' => ['required', 'string', 'max:128', 'distinct:ignore_case'],
        ]);
        $data['branches'] = $this->normalizedNames($data['branches'], 'branches');

        $this->rejectRemovalOfReferencedValues('branch', $data['branches'], 'branches');

        $existingCodes = $this->staffIds->branchCodes();
        $codes = [];
        foreach ($data['branches'] as $branch) {
            $codes[$branch] = $existingCodes[$branch]
                ?? $this->uniqueCode($branch, array_merge(array_values($existingCodes), array_values($codes)), 2, 4);
        }

        DB::transaction(function () use ($data, $codes): void {
            Setting::setValue('branches', json_encode($data['branches'], JSON_THROW_ON_ERROR));
            Setting::setValue('branch_codes', json_encode($codes, JSON_THROW_ON_ERROR));
        });

        return response()->json(['ok' => true, 'branches' => $data['branches']]);
    }

    private function rejectRemovalOfReferencedValues(string $column, array $nextValues, string $field): void
    {
        $used = $this->referencedValues($column);
        $nextKeys = array_fill_keys(array_map([$this, 'nameKey'], $nextValues), true);
        $removed = array_values(array_filter(
            $used,
            fn (string $value): bool => ! isset($nextKeys[$this->nameKey($value)])
        ));
        if ($removed !== []) {
            throw ValidationException::withMessages([
                $field => ['Cannot remove values still assigned to staff. Reassign these staff records first: '.implode(', ', $removed)],
            ]);
        }
    }

    /** @return list<string> */
    private function referencedValues(string $column): array
    {
        return Staff::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $configuredValues
     * @return list<string>
     */
    private function effectiveValues(array $configuredValues, string $column): array
    {
        $values = [];
        $seen = [];

        foreach (array_merge($configuredValues, $this->referencedValues($column)) as $value) {
            $name = trim((string) $value);
            $key = $this->nameKey($name);
            if ($name === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $values[] = $name;
        }

        return $values;
    }

    private function nameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private function uniqueCode(string $name, array $used, int $minimumLength, int $maximumLength): string
    {
        $words = preg_split('/[^A-Za-z]+/', strtoupper($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $base = count($words) > 1
            ? implode('', array_map(fn (string $word) => $word[0], $words))
            : preg_replace('/[^A-Z]/', '', strtoupper($name));
        $base = substr($base ?: 'XX', 0, $maximumLength);
        $base = str_pad($base, $minimumLength, 'X');

        if (! in_array($base, $used, true)) {
            return $base;
        }

        for ($index = 0; $index < 702; $index++) {
            $suffix = $index < 26
                ? chr(65 + $index)
                : chr(65 + intdiv($index - 26, 26)).chr(65 + (($index - 26) % 26));
            $candidate = substr($base, 0, $maximumLength - strlen($suffix)).$suffix;
            if (strlen($candidate) >= $minimumLength && ! in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        throw ValidationException::withMessages(['code' => ["Unable to generate a unique code for {$name}."]]);
    }

    /** @param array<int, string> $names
     * @return array<int, string>
     */
    private function normalizedNames(array $names, string $field): array
    {
        $names = array_values(array_map(fn (string $name): string => trim($name), $names));
        if (in_array('', $names, true) || count(array_unique(array_map('mb_strtolower', $names))) !== count($names)) {
            throw ValidationException::withMessages([
                $field => ['Names must be non-empty and unique, ignoring capitalization and surrounding spaces.'],
            ]);
        }

        return $names;
    }
}
