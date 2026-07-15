<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\StaffIdService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffController extends Controller
{
    public function __construct(
        protected StaffIdService $staffIds
    ) {}

    public function nextId(Request $request)
    {
        $data = $request->validate([
            'department' => ['required', 'string', 'in:'.implode(',', $this->staffIds->departments())],
            'branch' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json([
            'staff_id' => $this->staffIds->generate(
                $data['department'],
                $data['branch'] ?? 'HQ'
            ),
        ]);
    }

    public function index(Request $request)
    {
        $q = Staff::query()->orderBy('full_name');

        if ($search = $request->string('search')->toString()) {
            $q->where(function ($w) use ($search) {
                $w->where('staff_id', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%");
            });
        }

        if ($dept = $request->string('department')->toString()) {
            $q->where('department', $dept);
        }

        if ($status = $request->string('employment_status')->toString()) {
            $q->where('employment_status', $status);
        }

        $sort = $request->string('sort')->toString();

        match ($sort) {
            'full_name_desc' => $q->orderByDesc('full_name'),
            'staff_id' => $q->orderBy('staff_id'),
            'department' => $q->orderBy('department'),
            'created_at' => $q->orderBy('created_at'),
            'created_at_desc' => $q->orderByDesc('created_at'),
            default => $q->orderBy('full_name'),
        };

        return $q->paginate((int) $request->get('per_page', 15));
    }

    public function show(Staff $staff)
    {
        return $staff;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('staff-photos', 'public');
        }
        $staff = Staff::query()->create($data);

        return response()->json($staff, 201);
    }

    public function update(Request $request, Staff $staff)
    {
        $data = $this->validated($request, $staff->id);
        if ($request->hasFile('photo')) {
            if ($staff->photo_path) {
                Storage::disk('public')->delete($staff->photo_path);
            }
            $data['photo_path'] = $request->file('photo')->store('staff-photos', 'public');
        }
        $staff->update($data);

        return response()->json($staff->fresh());
    }

    public function destroy(Staff $staff)
    {
        $staff->update(['employment_status' => 'Inactive']);

        return response()->json(['ok' => true]);
    }

    public function export(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="HoganGuards_Staff_'.now()->format('Y-m-d').'.csv"',
        ];

        return response()->stream(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Staff ID', 'Full Name', 'Department', 'Job Title', 'Branch', 'Status']);
            Staff::query()->orderBy('staff_id')->chunk(200, function ($rows) use ($out) {
                foreach ($rows as $s) {
                    fputcsv($out, [$s->staff_id, $s->full_name, $s->department, $s->job_title, $s->branch, $s->employment_status]);
                }
            });
            fclose($out);
        }, 200, $headers);
    }

    public function import(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:4096'],
            'department' => ['nullable', 'string', 'max:128'],
            'branch' => ['nullable', 'string', 'max:64'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        $headers = fgetcsv($handle);

        $lowerHeaders = array_map('strtolower', array_map('trim', $headers));

        $results = ['imported' => 0, 'skipped' => 0, 'errors' => []];

        $departments = $this->staffIds->departments();
        $defaultDept = $request->string('department')->toString() ?: ($departments[0] ?? 'Operations');
        $defaultBranch = $request->string('branch')->toString() ?: 'HQ';

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($lowerHeaders, $row);

            if (! $data || empty($data['staff_id']) || empty($data['full_name'])) {
                $results['skipped']++;
                continue;
            }

            $staffId = trim($data['staff_id']);
            $fullName = trim($data['full_name']);
            $department = trim($data['department'] ?? $defaultDept);
            $jobTitle = trim($data['job_title'] ?? '');
            $branch = trim($data['branch'] ?? $defaultBranch);
            $status = trim($data['employment_status'] ?? 'Active');

            if (! in_array($department, $departments)) {
                $department = $defaultDept;
            }

            if (! $this->staffIds->isValidFormat($staffId)) {
                $results['errors'][] = "Invalid staff ID format: {$staffId}";
                $results['skipped']++;
                continue;
            }

            try {
                Staff::query()->updateOrCreate(
                    ['staff_id' => $staffId],
                    [
                        'full_name' => $fullName,
                        'department' => $department,
                        'job_title' => $jobTitle ?: null,
                        'branch' => $branch,
                        'employment_status' => in_array($status, ['Active', 'Inactive']) ? $status : 'Active',
                    ]
                );
                $results['imported']++;
            } catch (\Throwable $e) {
                $results['errors'][] = "Failed to import {$staffId}: ".$e->getMessage();
                $results['skipped']++;
            }
        }

        fclose($handle);

        return response()->json($results);
    }

    protected function validated(Request $request, ?int $ignoreId = null): array
    {
        $unique = 'unique:staff,staff_id';
        if ($ignoreId) {
            $unique .= ','.$ignoreId;
        }

        $pattern = config('hg.staff_id_pattern');

        return $request->validate([
            'staff_id' => ['required', 'string', 'max:32', 'regex:'.$pattern, $unique],
            'full_name' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'in:'.implode(',', $this->staffIds->departments())],
            'job_title' => ['nullable', 'string', 'max:128'],
            'branch' => ['nullable', 'string', 'max:64'],
            'employment_status' => ['nullable', 'in:Active,Inactive'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);
    }
}
