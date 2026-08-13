<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Staff;
use App\Models\StaffAssignmentHistory;
use App\Models\StaffEmploymentHistory;
use App\Services\StaffIdService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffController extends Controller
{
    public function __construct(
        protected StaffIdService $staffIds
    ) {}

    public function nextId(Request $request)
    {
        $data = $request->validate([
            'department' => ['required', 'string', Rule::in($this->staffIds->departments())],
            'branch' => ['nullable', 'string', 'max:64', Rule::in($this->staffIds->branches())],
            'company' => ['nullable', 'string', 'max:128', Rule::in($this->staffIds->companies())],
        ]);

        $branches = $this->staffIds->branches();
        $companies = $this->staffIds->companies();

        return response()->json([
            'staff_id' => $this->staffIds->generate(
                $data['department'],
                $data['branch'] ?? ($branches[0] ?? 'Lagos (HQ)'),
                $data['company'] ?? ($companies[0] ?? 'Hogan Guards')
            ),
        ]);
    }

    public function index(Request $request)
    {
        $q = Staff::query();

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

        return $q->paginate(min(100, max(1, $request->integer('per_page', 15))));
    }

    public function show(Staff $staff)
    {
        return $staff;
    }

    public function store(Request $request)
    {
        if (! $request->user()->canManageStaff()) {
            throw ValidationException::withMessages([
                'access' => ['Your role cannot create staff records.'],
            ]);
        }

        $data = $this->validated($request);
        $data['employment_start_date'] ??= now()->toDateString();
        $changeReason = trim((string) ($data['employment_change_reason'] ?? ''));
        $assignmentReason = trim((string) ($data['assignment_change_reason'] ?? ''));
        $assignmentEffectiveDate = $data['employment_start_date'];
        unset(
            $data['employment_change_reason'],
            $data['assignment_change_reason'],
            $data['assignment_effective_date']
        );
        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('staff-photos', 'local');
        }
        try {
            $staff = DB::transaction(function () use (
                $data,
                $request,
                $changeReason,
                $assignmentReason,
                $assignmentEffectiveDate
            ) {
                $staff = Staff::query()->create($data);
                $this->createInitialEmploymentHistory(
                    $staff,
                    $request->user()->id,
                    $changeReason ?: 'Staff record created'
                );
                $this->createInitialAssignmentHistory(
                    $staff,
                    $request->user()->id,
                    $assignmentReason ?: 'Staff record created',
                    $assignmentEffectiveDate
                );

                return $staff;
            });
        } catch (\Throwable $exception) {
            if (isset($data['photo_path'])) {
                Storage::disk('local')->delete($data['photo_path']);
            }

            throw $exception;
        }

        return response()->json($staff, 201);
    }

    public function update(Request $request, Staff $staff)
    {
        if (! $request->user()->canManageStaff()) {
            throw ValidationException::withMessages([
                'access' => ['Your role cannot update staff records.'],
            ]);
        }

        $data = $this->validated($request, $staff->id);
        $changeReason = trim((string) ($data['employment_change_reason'] ?? ''));
        $assignmentReason = trim((string) ($data['assignment_change_reason'] ?? '')) ?: $changeReason;
        $assignmentEffectiveDate = $data['assignment_effective_date'] ?? now()->toDateString();
        unset(
            $data['employment_change_reason'],
            $data['assignment_change_reason'],
            $data['assignment_effective_date']
        );
        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('staff-photos', 'local');
        }
        $oldPhotoPath = null;
        try {
            [$staff, $oldPhotoPath] = DB::transaction(function () use (
                $staff,
                $data,
                $request,
                $changeReason,
                $assignmentReason,
                $assignmentEffectiveDate
            ): array {
                $lockedStaff = Staff::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail();
                $replacedPhotoPath = $lockedStaff->photo_path;
                $oldStatus = $lockedStaff->employment_status;
                $oldStart = $lockedStaff->employment_start_date?->toDateString();
                $oldEnd = $lockedStaff->employment_end_date?->toDateString();
                $oldAssignment = $this->assignmentValues($lockedStaff);
                $newStatus = (string) $data['employment_status'];
                $newStart = Carbon::parse($data['employment_start_date'])->toDateString();
                $newEnd = filled($data['employment_end_date'] ?? null)
                    ? Carbon::parse($data['employment_end_date'])->toDateString()
                    : null;

                if ($oldStatus === 'Inactive'
                    && $newStatus === 'Active'
                    && $oldEnd
                    && $newStart <= $oldEnd) {
                    throw ValidationException::withMessages([
                        'employment_start_date' => ['A reactivation start date must be after the prior employment end date. Refresh this staff record and try again.'],
                    ]);
                }
                if (($oldStatus !== $newStatus || $oldStart !== $newStart || $oldEnd !== $newEnd)
                    && $changeReason === '') {
                    throw ValidationException::withMessages([
                        'employment_change_reason' => ['Explain the employment status or date change for the history record.'],
                    ]);
                }
                if ($oldStatus === $newStatus && ($oldStart !== $newStart || $oldEnd !== $newEnd)) {
                    throw ValidationException::withMessages([
                        'employment_start_date' => ['Existing employment intervals are immutable. Change employment status to close or begin an interval; correct older history through a separately audited data-repair process.'],
                    ]);
                }
                $this->assertEmploymentTransitionIsSafe(
                    $lockedStaff,
                    $oldStatus,
                    $oldStart,
                    $oldEnd,
                    $newStatus,
                    $newStart,
                    $newEnd,
                );

                $newAssignment = [
                    'company' => (string) $data['company'],
                    'branch' => (string) $data['branch'],
                    'department' => (string) $data['department'],
                    'job_title' => $this->nullableString($data['job_title'] ?? null),
                ];
                if ($oldAssignment !== $newAssignment && $assignmentReason === '') {
                    throw ValidationException::withMessages([
                        'assignment_change_reason' => ['Explain the organizational assignment change for the history record.'],
                    ]);
                }
                $lockedStaff->update($data);

                if ($oldStatus !== $lockedStaff->employment_status
                    || $oldStart !== $lockedStaff->employment_start_date?->toDateString()
                    || $oldEnd !== $lockedStaff->employment_end_date?->toDateString()) {
                    $this->syncEmploymentHistory(
                        $lockedStaff,
                        $oldStatus,
                        $oldStart,
                        $oldEnd,
                        $request->user()->id,
                        $changeReason ?: 'Employment details updated'
                    );
                }

                if ($oldAssignment !== $this->assignmentValues($lockedStaff)) {
                    $this->syncAssignmentHistory(
                        $lockedStaff,
                        $oldAssignment,
                        $assignmentEffectiveDate,
                        $request->user()->id,
                        $assignmentReason ?: 'Organizational assignment updated'
                    );
                }

                return [$lockedStaff, $replacedPhotoPath];
            });
        } catch (\Throwable $exception) {
            if (isset($data['photo_path'])) {
                Storage::disk('local')->delete($data['photo_path']);
            }
            throw $exception;
        }

        if (isset($data['photo_path']) && $oldPhotoPath) {
            $this->deletePhotoFromAllDisks($oldPhotoPath);
        }

        return response()->json($staff->fresh());
    }

    public function destroy(Request $request, Staff $staff)
    {
        if (! $request->user()->canManageStaff()) {
            throw ValidationException::withMessages([
                'access' => ['Your role cannot deactivate staff records.'],
            ]);
        }

        $data = $request->validate([
            'employment_end_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($staff, $data, $request): void {
            $staff = Staff::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail();
            $endDate = $data['employment_end_date']
                ?? $staff->employment_end_date?->toDateString()
                ?? now()->toDateString();
            if ($staff->employment_start_date && Carbon::parse($endDate)->lt($staff->employment_start_date)) {
                throw ValidationException::withMessages([
                    'employment_end_date' => ['Employment end date must be on or after the start date.'],
                ]);
            }
            if (Carbon::parse($endDate)->isFuture()) {
                throw ValidationException::withMessages([
                    'employment_end_date' => ['A deactivated employee cannot have a future end date. Keep the employee active until that date.'],
                ]);
            }

            if ($staff->employment_status === 'Inactive'
                && $staff->employment_end_date?->toDateString() === Carbon::parse($endDate)->toDateString()) {
                return;
            }
            if ($staff->employment_status === 'Inactive') {
                throw ValidationException::withMessages([
                    'employment_end_date' => ['Existing employment intervals are immutable. Correct an older interval through a separately audited data-repair process.'],
                ]);
            }

            $oldStatus = $staff->employment_status;
            $oldStart = $staff->employment_start_date?->toDateString();
            $oldEnd = $staff->employment_end_date?->toDateString();
            $this->assertEmploymentTransitionIsSafe(
                $staff,
                $oldStatus,
                $oldStart,
                $oldEnd,
                'Inactive',
                $oldStart ?? Carbon::parse($endDate)->toDateString(),
                Carbon::parse($endDate)->toDateString(),
            );
            $staff->update([
                'employment_status' => 'Inactive',
                'employment_end_date' => $endDate,
            ]);
            $this->syncEmploymentHistory(
                $staff,
                $oldStatus,
                $oldStart,
                $oldEnd,
                $request->user()->id,
                trim((string) ($data['reason'] ?? '')) ?: 'Staff deactivated'
            );
        });

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
            fputcsv($out, ['Staff ID', 'Full Name', 'Company', 'Department', 'Job Title', 'Branch', 'Status', 'Employment Start', 'Employment End']);
            Staff::query()->orderBy('staff_id')->chunk(200, function ($rows) use ($out) {
                foreach ($rows as $s) {
                    fputcsv($out, array_map([$this, 'safeCsvCell'], [
                        $s->staff_id, $s->full_name, $s->company, $s->department, $s->job_title, $s->branch,
                        $s->employment_status, $s->employment_start_date?->toDateString(), $s->employment_end_date?->toDateString(),
                    ]));
                }
            });
            fclose($out);
        }, 200, $headers);
    }

    public function import(Request $request)
    {
        if (! $request->user()->canManageStaff()) {
            throw ValidationException::withMessages(['access' => ['You cannot import staff records.']]);
        }

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:4096'],
            'department' => ['nullable', 'string', 'max:128', Rule::in($this->staffIds->departments())],
            'branch' => ['nullable', 'string', 'max:64', Rule::in($this->staffIds->branches())],
            'company' => ['nullable', 'string', 'max:128', Rule::in($this->staffIds->companies())],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $file = $request->file('file');
        $handle = @fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['The CSV file could not be read.']]);
        }

        $headers = fgetcsv($handle);
        if (! is_array($headers) || $headers === []) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => ['The CSV file is empty or has no header row.']]);
        }

        $headerAliases = [
            'status' => 'employment_status',
            'employment_start' => 'employment_start_date',
            'employment_end' => 'employment_end_date',
        ];
        $lowerHeaders = array_map(function (mixed $header) use ($headerAliases): string {
            $header = preg_replace('/^\xEF\xBB\xBF/', '', trim((string) $header)) ?? '';
            $normalized = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($header)), '_');

            return $headerAliases[$normalized] ?? $normalized;
        }, $headers);

        if (in_array('', $lowerHeaders, true) || count(array_unique($lowerHeaders)) !== count($lowerHeaders)) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => ['CSV headers must be named and unique.']]);
        }

        $missing = array_values(array_diff(['staff_id', 'full_name'], $lowerHeaders));
        if ($missing !== []) {
            fclose($handle);
            throw ValidationException::withMessages([
                'file' => ['Missing required CSV column(s): '.implode(', ', $missing).'.'],
            ]);
        }

        $results = ['imported' => 0, 'skipped' => 0, 'errors' => []];

        $departments = $this->staffIds->departments();
        $branches = $this->staffIds->branches();
        $companies = $this->staffIds->companies();
        $defaultDept = $request->string('department')->toString() ?: ($departments[0] ?? 'Operations');
        $defaultBranch = $request->string('branch')->toString() ?: ($branches[0] ?? 'Lagos (HQ)');
        $defaultCompany = $request->string('company')->toString() ?: ($companies[0] ?? 'Hogan Guards');
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if ($rowNumber > 10001) {
                $this->addImportError($results, 'The import is limited to 10,000 data rows.');
                break;
            }

            if (count($row) !== count($lowerHeaders)) {
                $this->addImportError($results, "Row {$rowNumber} has ".count($row).' column(s); '.count($lowerHeaders).' were expected.');
                $results['skipped']++;

                continue;
            }

            $data = array_combine($lowerHeaders, $row);

            if (! $data || empty($data['staff_id']) || empty($data['full_name'])) {
                $this->addImportError($results, "Row {$rowNumber} is missing staff_id or full_name.");
                $results['skipped']++;

                continue;
            }

            $staffId = trim($data['staff_id']);
            $existing = Staff::query()->where('staff_id', $staffId)->first();
            $providedStart = trim((string) ($data['employment_start_date'] ?? ''));
            $providedAssignmentDate = trim((string) ($data['assignment_effective_date'] ?? ''));
            $providedAssignmentReason = trim((string) ($data['assignment_change_reason'] ?? ''));
            $rowData = [
                'staff_id' => $staffId,
                'full_name' => trim((string) $data['full_name']),
                'company' => trim((string) (($data['company'] ?? '') ?: ($existing?->company ?? $defaultCompany))),
                'department' => trim((string) (($data['department'] ?? '') ?: ($existing?->department ?? $defaultDept))),
                'job_title' => array_key_exists('job_title', $data)
                    ? $this->nullableString($data['job_title'])
                    : $existing?->job_title,
                'branch' => trim((string) (($data['branch'] ?? '') ?: ($existing?->branch ?? $defaultBranch))),
                'employment_status' => trim((string) (($data['employment_status'] ?? '') ?: ($existing?->employment_status ?? 'Active'))),
                'employment_start_date' => $providedStart
                    ?: ($existing?->employment_start_date?->toDateString() ?? now()->toDateString()),
                'employment_end_date' => trim((string) ($data['employment_end_date'] ?? '')) ?: null,
                'assignment_effective_date' => $providedAssignmentDate ?: now()->toDateString(),
                'assignment_change_reason' => $providedAssignmentReason,
            ];

            $rowValidator = Validator::make($rowData, [
                'staff_id' => ['required', 'string', 'max:32', 'regex:'.config('hg.staff_id_pattern')],
                'full_name' => ['required', 'string', 'max:255'],
                'company' => ['required', 'string', 'max:128', Rule::in($companies)],
                'department' => ['required', 'string', 'max:128', Rule::in($departments)],
                'job_title' => ['nullable', 'string', 'max:128'],
                'branch' => ['required', 'string', 'max:64', Rule::in($branches)],
                'employment_status' => ['required', Rule::in(['Active', 'Inactive'])],
                'employment_start_date' => ['required', 'date_format:Y-m-d'],
                'employment_end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:employment_start_date', 'before_or_equal:today', 'required_if:employment_status,Inactive', 'prohibited_if:employment_status,Active'],
                'assignment_effective_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'assignment_change_reason' => ['nullable', 'string', 'max:500'],
            ]);

            if ($existing?->employment_status === 'Inactive' && $rowData['employment_status'] === 'Active') {
                $priorEnd = $existing->employment_end_date?->toDateString();
                if ($providedStart === '' || ($priorEnd && Carbon::parse($providedStart)->lte(Carbon::parse($priorEnd)))) {
                    $rowValidator->after(function ($validator): void {
                        $validator->errors()->add(
                            'employment_start_date',
                            'Reactivating an employee requires an explicit start date after the prior employment end date.'
                        );
                    });
                }
            }

            $importAssignment = [
                'company' => $rowData['company'],
                'branch' => $rowData['branch'],
                'department' => $rowData['department'],
                'job_title' => $this->nullableString($rowData['job_title']),
            ];
            if ($existing && $this->assignmentValues($existing) !== $importAssignment) {
                $latestAssignmentStart = $existing->assignmentHistory()
                    ->orderByDesc('effective_from')
                    ->value('effective_from');
                $minimumDate = $latestAssignmentStart
                    ? Carbon::parse($latestAssignmentStart)->toDateString()
                    : ($existing->employment_start_date?->toDateString()
                        ?? Carbon::parse($existing->created_at ?? now())->toDateString());
                if ($rowData['assignment_effective_date'] < $minimumDate) {
                    $rowValidator->after(function ($validator) use ($minimumDate): void {
                        $validator->errors()->add(
                            'assignment_effective_date',
                            "The assignment change date cannot be earlier than {$minimumDate}."
                        );
                    });
                }
            }

            if ($rowValidator->fails()) {
                $this->addImportError(
                    $results,
                    "Row {$rowNumber} ({$staffId}): ".$rowValidator->errors()->first()
                );
                $results['skipped']++;

                continue;
            }

            try {
                DB::transaction(function () use ($rowData, $request): void {
                    $assignmentEffectiveDate = $rowData['assignment_effective_date'];
                    $assignmentReason = $rowData['assignment_change_reason'] ?: 'Organizational assignment updated by CSV import';
                    $attributes = $rowData;
                    unset($attributes['assignment_effective_date'], $attributes['assignment_change_reason']);

                    $staff = Staff::query()->where('staff_id', $attributes['staff_id'])->lockForUpdate()->first();
                    if (! $staff) {
                        $staff = Staff::query()->create($attributes);
                        $this->createInitialEmploymentHistory($staff, $request->user()->id, 'Staff CSV import');
                        $this->createInitialAssignmentHistory(
                            $staff,
                            $request->user()->id,
                            'Staff CSV import',
                            $staff->employment_start_date?->toDateString()
                        );

                        return;
                    }

                    $oldStatus = $staff->employment_status;
                    $oldStart = $staff->employment_start_date?->toDateString();
                    $oldEnd = $staff->employment_end_date?->toDateString();
                    $oldAssignment = $this->assignmentValues($staff);
                    $this->assertEmploymentTransitionIsSafe(
                        $staff,
                        $oldStatus,
                        $oldStart,
                        $oldEnd,
                        (string) $attributes['employment_status'],
                        Carbon::parse($attributes['employment_start_date'])->toDateString(),
                        $attributes['employment_end_date']
                            ? Carbon::parse($attributes['employment_end_date'])->toDateString()
                            : null,
                    );
                    $staff->update($attributes);

                    if ($oldStatus !== $staff->employment_status
                        || $oldStart !== $staff->employment_start_date?->toDateString()
                        || $oldEnd !== $staff->employment_end_date?->toDateString()) {
                        $this->syncEmploymentHistory(
                            $staff,
                            $oldStatus,
                            $oldStart,
                            $oldEnd,
                            $request->user()->id,
                            'Employment details updated by CSV import'
                        );
                    }

                    if ($oldAssignment !== $this->assignmentValues($staff)) {
                        $this->syncAssignmentHistory(
                            $staff,
                            $oldAssignment,
                            $assignmentEffectiveDate,
                            $request->user()->id,
                            $assignmentReason
                        );
                    }
                }, 3);
                $results['imported']++;
            } catch (\Throwable $exception) {
                report($exception);
                $this->addImportError($results, "Row {$rowNumber} ({$staffId}) could not be saved.");
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
        $existing = $ignoreId ? Staff::query()->find($ignoreId) : null;

        $data = $request->validate([
            'staff_id' => ['required', 'string', 'max:32', 'regex:'.$pattern, $unique],
            'company' => ['required', 'string', 'max:128', Rule::in($this->staffIds->companies())],
            'full_name' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', Rule::in($this->staffIds->departments())],
            'job_title' => ['nullable', 'string', 'max:128'],
            'branch' => ['required', 'string', 'max:64', Rule::in($this->staffIds->branches())],
            'employment_status' => ['nullable', 'in:Active,Inactive'],
            'employment_start_date' => ['nullable', 'date'],
            'employment_end_date' => ['nullable', 'date'],
            'employment_change_reason' => ['nullable', 'string', 'max:500'],
            'assignment_effective_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'assignment_change_reason' => ['nullable', 'string', 'max:500'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4000,max_height=4000'],
        ]);

        $status = $data['employment_status'] ?? $existing?->employment_status ?? 'Active';
        $start = $data['employment_start_date'] ?? $existing?->employment_start_date?->toDateString() ?? now()->toDateString();
        $end = array_key_exists('employment_end_date', $data)
            ? $data['employment_end_date']
            : $existing?->employment_end_date?->toDateString();

        if ($start && $end && Carbon::parse($end)->lt(Carbon::parse($start))) {
            throw ValidationException::withMessages([
                'employment_end_date' => ['Employment end date must be on or after the start date.'],
            ]);
        }

        if ($status === 'Inactive' && ! $end) {
            throw ValidationException::withMessages([
                'employment_end_date' => ['An inactive employee requires an employment end date.'],
            ]);
        }

        if ($status === 'Inactive' && Carbon::parse($end)->isFuture()) {
            throw ValidationException::withMessages([
                'employment_end_date' => ['An inactive employee cannot have a future end date. Keep the employee active until that date.'],
            ]);
        }

        if ($status === 'Active' && $end) {
            throw ValidationException::withMessages([
                'employment_end_date' => ['An active employee cannot have an employment end date. Clear it when reactivating the employee.'],
            ]);
        }

        if ($existing && $existing->employment_status === 'Inactive' && $status === 'Active') {
            $priorEnd = $existing->employment_end_date?->toDateString();
            if ($priorEnd && Carbon::parse($start)->lessThanOrEqualTo(Carbon::parse($priorEnd))) {
                throw ValidationException::withMessages([
                    'employment_start_date' => ['A reactivation start date must be after the prior employment end date.'],
                ]);
            }
        }

        $employmentChanged = $existing && (
            $existing->employment_status !== $status
            || $existing->employment_start_date?->toDateString() !== Carbon::parse($start)->toDateString()
            || $existing->employment_end_date?->toDateString() !== ($end ? Carbon::parse($end)->toDateString() : null)
        );
        if ($employmentChanged && trim((string) ($data['employment_change_reason'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'employment_change_reason' => ['Explain the employment status or date change for the history record.'],
            ]);
        }

        $assignmentChanged = $existing && $this->assignmentValues($existing) !== [
            'company' => (string) $data['company'],
            'branch' => (string) $data['branch'],
            'department' => (string) $data['department'],
            'job_title' => $this->nullableString($data['job_title'] ?? null),
        ];
        if ($assignmentChanged) {
            $assignmentReason = trim((string) ($data['assignment_change_reason'] ?? ''))
                ?: trim((string) ($data['employment_change_reason'] ?? ''));
            if ($assignmentReason === '') {
                throw ValidationException::withMessages([
                    'assignment_change_reason' => ['Explain the organizational assignment change for the history record.'],
                ]);
            }

            $effectiveDate = Carbon::parse($data['assignment_effective_date'] ?? now())->toDateString();
            $latestAssignmentStart = $existing->assignmentHistory()
                ->orderByDesc('effective_from')
                ->value('effective_from');
            $minimumDate = $latestAssignmentStart
                ? Carbon::parse($latestAssignmentStart)->toDateString()
                : ($existing->employment_start_date?->toDateString()
                    ?? Carbon::parse($existing->created_at ?? now())->toDateString());

            if ($effectiveDate < $minimumDate) {
                throw ValidationException::withMessages([
                    'assignment_effective_date' => ["The assignment change date cannot be earlier than {$minimumDate}."],
                ]);
            }

            $data['assignment_effective_date'] = $effectiveDate;
        }

        $data['employment_status'] = $status;
        $data['employment_start_date'] = Carbon::parse($start)->toDateString();
        $data['employment_end_date'] = $end ? Carbon::parse($end)->toDateString() : null;

        return $data;
    }

    private function createInitialEmploymentHistory(Staff $staff, int $changedBy, string $reason): void
    {
        $start = $staff->employment_start_date?->toDateString() ?? now()->toDateString();

        if ($staff->employment_status === 'Inactive' && $staff->employment_end_date) {
            $end = $staff->employment_end_date->toDateString();
            StaffEmploymentHistory::query()->create([
                'staff_id' => $staff->id,
                'status' => 'Active',
                'effective_from' => $start,
                'effective_to' => $end,
                'changed_by' => $changedBy,
                'reason' => $reason,
            ]);
            StaffEmploymentHistory::query()->create([
                'staff_id' => $staff->id,
                'status' => 'Inactive',
                'effective_from' => Carbon::parse($end)->addDay()->toDateString(),
                'effective_to' => null,
                'changed_by' => $changedBy,
                'reason' => $reason,
            ]);

            return;
        }

        StaffEmploymentHistory::query()->create([
            'staff_id' => $staff->id,
            'status' => 'Active',
            'effective_from' => $start,
            'effective_to' => null,
            'changed_by' => $changedBy,
            'reason' => $reason,
        ]);
    }

    private function syncEmploymentHistory(
        Staff $staff,
        string $oldStatus,
        ?string $oldStart,
        ?string $oldEnd,
        int $changedBy,
        string $reason
    ): void {
        if (! StaffEmploymentHistory::query()->where('staff_id', $staff->id)->exists()) {
            $this->createInitialEmploymentHistory($staff, $changedBy, $reason);

            return;
        }

        $newStart = $staff->employment_start_date?->toDateString() ?? now()->toDateString();
        $newEnd = $staff->employment_end_date?->toDateString();

        if ($oldStatus === 'Active' && $staff->employment_status === 'Inactive') {
            $active = StaffEmploymentHistory::query()
                ->where('staff_id', $staff->id)
                ->where('status', 'Active')
                ->whereNull('effective_to')
                ->latest('effective_from')
                ->first();

            if ($active) {
                $active->update([
                    'effective_to' => $newEnd,
                    'changed_by' => $changedBy,
                    'reason' => $reason,
                ]);
            } else {
                StaffEmploymentHistory::query()->create([
                    'staff_id' => $staff->id,
                    'status' => 'Active',
                    'effective_from' => $oldStart ?? $newStart,
                    'effective_to' => $newEnd,
                    'changed_by' => $changedBy,
                    'reason' => $reason,
                ]);
            }

            StaffEmploymentHistory::query()->create([
                'staff_id' => $staff->id,
                'status' => 'Inactive',
                'effective_from' => Carbon::parse($newEnd)->addDay()->toDateString(),
                'effective_to' => null,
                'changed_by' => $changedBy,
                'reason' => $reason,
            ]);

            return;
        }

        if ($oldStatus === 'Inactive' && $staff->employment_status === 'Active') {
            $inactive = StaffEmploymentHistory::query()
                ->where('staff_id', $staff->id)
                ->where('status', 'Inactive')
                ->whereNull('effective_to')
                ->latest('effective_from')
                ->first();
            $inactive?->update([
                'effective_to' => Carbon::parse($newStart)->subDay()->toDateString(),
                'changed_by' => $changedBy,
                'reason' => $reason,
            ]);

            StaffEmploymentHistory::query()->create([
                'staff_id' => $staff->id,
                'status' => 'Active',
                'effective_from' => $newStart,
                'effective_to' => null,
                'changed_by' => $changedBy,
                'reason' => $reason,
            ]);

            return;
        }

        throw ValidationException::withMessages([
            'employment_start_date' => ['Existing employment intervals are immutable. Change employment status to close or begin an interval.'],
        ]);
    }

    /**
     * Closing the current interval is the only normal way to change its end.
     * It must retain its original start and cannot make retained facts fall
     * outside employment. A rehire creates a new Active interval instead.
     */
    private function assertEmploymentTransitionIsSafe(
        Staff $staff,
        string $oldStatus,
        ?string $oldStart,
        ?string $oldEnd,
        string $newStatus,
        string $newStart,
        ?string $newEnd,
    ): void {
        if ($oldStatus === $newStatus) {
            return;
        }

        if ($oldStatus === 'Active' && $newStatus === 'Inactive') {
            if ($oldStart && $newStart !== $oldStart) {
                throw ValidationException::withMessages([
                    'employment_start_date' => ['Closing an employment interval cannot change its original start date.'],
                ]);
            }
            if (! $newEnd) {
                throw ValidationException::withMessages([
                    'employment_end_date' => ['An inactive employee requires an employment end date.'],
                ]);
            }

            $openInterval = StaffEmploymentHistory::query()
                ->where('staff_id', $staff->id)
                ->where('status', 'Active')
                ->whereNull('effective_to')
                ->latest('effective_from')
                ->lockForUpdate()
                ->first();
            if ($openInterval && $newEnd < $openInterval->effective_from->toDateString()) {
                throw ValidationException::withMessages([
                    'employment_end_date' => ['The end date cannot be before the start of the current employment interval.'],
                ]);
            }

            $hasAttendanceAfterEnd = Attendance::query()
                ->where('staff_id', $staff->id)
                ->whereDate('date', '>', $newEnd)
                ->exists();
            $hasActiveLeaveAfterEnd = Leave::query()
                ->where('staff_id', $staff->id)
                ->whereIn('status', ['Pending', 'Approved'])
                ->whereDate('end_date', '>', $newEnd)
                ->exists();
            if ($hasAttendanceAfterEnd || $hasActiveLeaveAfterEnd) {
                throw ValidationException::withMessages([
                    'employment_end_date' => ['This end date would place existing attendance or leave outside employment. Resolve those records first or use the audited data-repair process.'],
                ]);
            }

            return;
        }

        if ($oldStatus === 'Inactive' && $newStatus === 'Active'
            && $oldEnd && $newStart <= $oldEnd) {
            throw ValidationException::withMessages([
                'employment_start_date' => ['A reactivation start date must be after the prior employment end date.'],
            ]);
        }
    }

    private function createInitialAssignmentHistory(
        Staff $staff,
        int $changedBy,
        string $reason,
        ?string $effectiveFrom = null
    ): void {
        StaffAssignmentHistory::query()->create([
            'staff_id' => $staff->id,
            ...$this->assignmentValues($staff),
            'effective_from' => Carbon::parse(
                $effectiveFrom
                    ?? $staff->employment_start_date
                    ?? $staff->created_at
                    ?? now()
            )->toDateString(),
            'effective_to' => null,
            'changed_by' => $changedBy,
            'reason' => $reason,
        ]);
    }

    /** @param array{company:string,branch:string,department:string,job_title:?string} $oldAssignment */
    private function syncAssignmentHistory(
        Staff $staff,
        array $oldAssignment,
        string $effectiveDate,
        int $changedBy,
        string $reason
    ): void {
        $effectiveDate = Carbon::parse($effectiveDate)->toDateString();
        $latest = StaffAssignmentHistory::query()
            ->where('staff_id', $staff->id)
            ->orderByDesc('effective_from')
            ->lockForUpdate()
            ->first();

        if (! $latest) {
            $baseline = Carbon::parse(
                $staff->employment_start_date
                    ?? $staff->created_at
                    ?? $effectiveDate
            )->toDateString();
            $latest = StaffAssignmentHistory::query()->create([
                'staff_id' => $staff->id,
                ...$oldAssignment,
                'effective_from' => $baseline,
                'effective_to' => null,
                'changed_by' => $changedBy,
                'reason' => 'Assignment history initialized before update: '.$reason,
            ]);
        }

        $latestStart = $latest->effective_from->toDateString();
        if ($effectiveDate < $latestStart) {
            throw ValidationException::withMessages([
                'assignment_effective_date' => ["The assignment change date cannot be earlier than {$latestStart}."],
            ]);
        }

        $newAssignment = $this->assignmentValues($staff);
        if ($effectiveDate === $latestStart) {
            $latest->update([
                ...$newAssignment,
                'changed_by' => $changedBy,
                'reason' => $reason,
            ]);

            return;
        }

        if ($latest->effective_to !== null) {
            throw ValidationException::withMessages([
                'assignment_effective_date' => ['The latest assignment interval is already closed. Repair the assignment history before recording another transfer.'],
            ]);
        }

        $latest->update([
            'effective_to' => Carbon::parse($effectiveDate)->subDay()->toDateString(),
        ]);
        StaffAssignmentHistory::query()->create([
            'staff_id' => $staff->id,
            ...$newAssignment,
            'effective_from' => $effectiveDate,
            'effective_to' => null,
            'changed_by' => $changedBy,
            'reason' => $reason,
        ]);
    }

    /** @return array{company:string,branch:string,department:string,job_title:?string} */
    private function assignmentValues(Staff $staff): array
    {
        return [
            'company' => (string) ($staff->company ?? ''),
            'branch' => (string) ($staff->branch ?? ''),
            'department' => (string) $staff->department,
            'job_title' => $this->nullableString($staff->job_title),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    /** @param array{errors: array<int, string>} $results */
    private function addImportError(array &$results, string $message): void
    {
        if (count($results['errors']) < 100) {
            $results['errors'][] = $message;
        } elseif (count($results['errors']) === 100) {
            $results['errors'][] = 'Additional row errors were omitted.';
        }
    }

    public function employmentHistory(Staff $staff)
    {
        return response()->json($staff->employmentHistory()->with('changer:id,username')->get());
    }

    public function assignmentHistory(Staff $staff)
    {
        return response()->json($staff->assignmentHistory()->with('changer:id,username')->get());
    }

    public function photo(string $staffId): StreamedResponse
    {
        abort_unless(ctype_digit($staffId), 404);
        $staff = Staff::query()->findOrFail((int) $staffId);
        $path = (string) $staff->photo_path;
        abort_unless($this->isSafePhotoPath($path), 404);

        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            // Temporary compatibility for installations that have not yet
            // run staff:secure-photos. The signed route still protects it.
            $disk = Storage::disk('public');
        }
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, 'staff-photo-'.(int) $staff->id.'.'.pathinfo($path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function safeCsvCell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }

    private function deletePhotoFromAllDisks(string $path): void
    {
        if (! $this->isSafePhotoPath($path)) {
            return;
        }

        Storage::disk('local')->delete($path);
        Storage::disk('public')->delete($path);
    }

    private function isSafePhotoPath(string $path): bool
    {
        return str_starts_with($path, 'staff-photos/')
            && ! str_contains($path, '..')
            && preg_match('#^staff-photos/[A-Za-z0-9/_-]+\.(?:jpe?g|png|webp)$#i', $path) === 1;
    }
}
