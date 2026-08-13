<?php

namespace App\Services;

use App\Models\DefaultScheduleVersion;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\StaffScheduleVersion;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleService
{
    private ?Collection $defaultVersionsCache = null;

    /** @var array<int, \Illuminate\Support\Collection> */
    private array $staffVersionsCache = [];

    /** @var array<int, \Illuminate\Support\Collection> */
    private array $currentSchedulesCache = [];

    public function __construct(protected AppConfigService $config) {}

    /**
     * @return array{is_day_off: bool, shift_start: ?string, shift_end: ?string, break_minutes: int, works_on_public_holiday: bool, is_overnight: bool}
     */
    public function effectiveShift(Staff $staff, CarbonInterface $date): array
    {
        $dateString = $date->toDateString();

        return $this->effectiveShifts([$staff->id], [$dateString])[$staff->id][$dateString];
    }

    /**
     * @param  list<int>  $staffIds
     * @param  list<string>  $dates
     * @return array<int, array<string, array{is_day_off:bool,shift_start:?string,shift_end:?string,break_minutes:int,works_on_public_holiday:bool,is_overnight:bool}>>
     */
    public function effectiveShifts(array $staffIds, array $dates): array
    {
        if ($staffIds === [] || $dates === []) {
            return [];
        }

        sort($dates);
        $this->defaultVersionsCache ??= DefaultScheduleVersion::query()
            ->orderBy('effective_from')
            ->get();
        $missingStaffIds = array_values(array_diff($staffIds, array_keys($this->staffVersionsCache)));
        if ($missingStaffIds !== []) {
            $versions = StaffScheduleVersion::query()
                ->whereIn('staff_id', $missingStaffIds)
                ->orderBy('effective_from')
                ->get()
                ->groupBy('staff_id');
            $currentSchedules = StaffSchedule::query()
                ->whereIn('staff_id', $missingStaffIds)
                ->get()
                ->groupBy('staff_id');
            foreach ($missingStaffIds as $staffId) {
                $this->staffVersionsCache[$staffId] = $versions->get($staffId, collect());
                $this->currentSchedulesCache[$staffId] = $currentSchedules->get($staffId, collect());
            }
        }
        $result = [];

        foreach ($staffIds as $staffId) {
            foreach ($dates as $date) {
                $default = $this->defaultVersionsCache->last(
                    fn (DefaultScheduleVersion $version): bool => $version->effective_from->toDateString() <= $date
                );
                $staffVersion = $this->staffVersionsCache[$staffId]->last(
                    fn (StaffScheduleVersion $version): bool => $version->effective_from->toDateString() <= $date
                );
                $scheduleRows = $staffVersion
                    ? collect($staffVersion->schedule)
                    : $this->currentSchedulesCache[$staffId]->map(fn (StaffSchedule $row): array => $this->versionRow($row));
                $schedule = $scheduleRows->first(
                    fn (array $row): bool => (int) $row['day_of_week'] === (int) Carbon::parse($date)->format('w')
                );
                $result[$staffId][$date] = $this->resolveShift($schedule, $default, $date);
            }
        }

        return $result;
    }

    public function forStaff(int $staffId): array
    {
        return StaffSchedule::query()
            ->where('staff_id', $staffId)
            ->orderBy('day_of_week')
            ->get()
            ->map(fn (StaffSchedule $schedule) => $this->serializeSchedule($schedule))
            ->all();
    }

    /**
     * @param  list<int>  $staffIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function forStaffIds(array $staffIds): array
    {
        if ($staffIds === []) {
            return [];
        }

        return StaffSchedule::query()
            ->whereIn('staff_id', $staffIds)
            ->orderBy('day_of_week')
            ->get()
            ->groupBy('staff_id')
            ->map(fn (Collection $schedules) => $schedules
                ->map(fn (StaffSchedule $schedule) => $this->serializeSchedule($schedule))
                ->values()
                ->all())
            ->all();
    }

    /** @return array<string, mixed> */
    private function serializeSchedule(StaffSchedule $schedule): array
    {
        $start = $schedule->shift_start ? substr($schedule->shift_start, 0, 5) : null;
        $end = $schedule->shift_end ? substr($schedule->shift_end, 0, 5) : null;

        return [
            'id' => $schedule->id,
            'day_of_week' => (int) $schedule->day_of_week,
            'day_name' => Carbon::create(2024, 1, 7 + (int) $schedule->day_of_week)->format('l'),
            'shift_start' => $start,
            'shift_end' => $end,
            'break_minutes' => (int) $schedule->break_minutes,
            'is_day_off' => (bool) $schedule->is_day_off,
            'works_on_public_holiday' => (bool) ($schedule->works_on_public_holiday ?? false),
            'is_overnight' => $start !== null && $end !== null && $end < $start,
        ];
    }

    public function upsert(int $staffId, array $items, ?int $changedBy = null, string $reason = 'Staff schedule updated'): void
    {
        $this->validateItems($items);

        DB::transaction(function () use ($staffId, $items, $changedBy, $reason): void {
            $staff = Staff::query()->whereKey($staffId)->lockForUpdate()->firstOrFail();
            $this->ensureStaffBaseline($staff, $changedBy);

            foreach ($items as $item) {
                $isDayOff = (bool) ($item['is_day_off'] ?? false);

                StaffSchedule::query()->updateOrCreate(
                    [
                        'staff_id' => $staffId,
                        'day_of_week' => (string) $item['day_of_week'],
                    ],
                    [
                        'shift_start' => $isDayOff ? null : ($item['shift_start'] ?? null),
                        'shift_end' => $isDayOff ? null : ($item['shift_end'] ?? null),
                        'break_minutes' => $isDayOff ? 0 : (int) ($item['break_minutes'] ?? $this->config->defaultBreakMinutes()),
                        'is_day_off' => $isDayOff,
                        'works_on_public_holiday' => ! $isDayOff && (bool) ($item['works_on_public_holiday'] ?? false),
                    ]
                );
            }

            $this->persistStaffVersion($staffId, $changedBy, $reason);
            unset($this->staffVersionsCache[$staffId], $this->currentSchedulesCache[$staffId]);
        }, 3);
    }

    public function reset(int $staffId, ?int $changedBy = null, string $reason = 'Staff schedule reset to defaults'): void
    {
        DB::transaction(function () use ($staffId, $changedBy, $reason): void {
            $staff = Staff::query()->whereKey($staffId)->lockForUpdate()->firstOrFail();
            $this->ensureStaffBaseline($staff, $changedBy);
            StaffSchedule::query()->where('staff_id', $staffId)->delete();
            $this->persistStaffVersion($staffId, $changedBy, $reason);
            unset($this->staffVersionsCache[$staffId], $this->currentSchedulesCache[$staffId]);
        }, 3);
    }

    public function recordDefaultVersion(?int $changedBy = null, string $reason = 'Default schedule settings updated'): void
    {
        $now = now();
        DB::table('default_schedule_versions')->upsert(
            [[
                'effective_from' => $now->toDateString(),
                'shift_start' => $this->config->shiftStart()->format('H:i'),
                'shift_end' => $this->config->shiftEnd()->format('H:i'),
                'default_work_days' => json_encode($this->config->defaultWorkDays(), JSON_THROW_ON_ERROR),
                'default_break_minutes' => $this->config->defaultBreakMinutes(),
                'changed_by' => $changedBy,
                'reason' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['effective_from'],
            ['shift_start', 'shift_end', 'default_work_days', 'default_break_minutes', 'changed_by', 'reason', 'updated_at']
        );
        $this->defaultVersionsCache = null;
    }

    private function ensureStaffBaseline(Staff $staff, ?int $changedBy): void
    {
        if (StaffScheduleVersion::query()->where('staff_id', $staff->id)->exists()) {
            return;
        }

        $effectiveFrom = $staff->employment_start_date?->toDateString()
            ?? $staff->created_at?->toDateString()
            ?? now()->toDateString();
        if (Carbon::parse($effectiveFrom)->greaterThanOrEqualTo(now()->startOfDay())) {
            return;
        }
        $this->persistStaffVersion($staff->id, $changedBy, 'Initial staff schedule history', $effectiveFrom);
    }

    private function persistStaffVersion(
        int $staffId,
        ?int $changedBy,
        string $reason,
        ?string $effectiveFrom = null
    ): void {
        $rows = StaffSchedule::query()
            ->where('staff_id', $staffId)
            ->orderBy('day_of_week')
            ->get()
            ->map(fn (StaffSchedule $row): array => $this->versionRow($row))
            ->values()
            ->all();

        $now = now();
        DB::table('staff_schedule_versions')->upsert(
            [[
                'staff_id' => $staffId,
                'effective_from' => $effectiveFrom ?? $now->toDateString(),
                'schedule' => json_encode($rows, JSON_THROW_ON_ERROR),
                'changed_by' => $changedBy,
                'reason' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['staff_id', 'effective_from'],
            ['schedule', 'changed_by', 'reason', 'updated_at']
        );
    }

    /** @return array{day_of_week:int,shift_start:?string,shift_end:?string,break_minutes:int,is_day_off:bool,works_on_public_holiday:bool} */
    private function versionRow(StaffSchedule $row): array
    {
        return [
            'day_of_week' => (int) $row->day_of_week,
            'shift_start' => $row->shift_start ? substr((string) $row->shift_start, 0, 5) : null,
            'shift_end' => $row->shift_end ? substr((string) $row->shift_end, 0, 5) : null,
            'break_minutes' => (int) $row->break_minutes,
            'is_day_off' => (bool) $row->is_day_off,
            'works_on_public_holiday' => (bool) $row->works_on_public_holiday,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $schedule
     * @return array{is_day_off:bool,shift_start:?string,shift_end:?string,break_minutes:int,works_on_public_holiday:bool,is_overnight:bool}
     */
    private function resolveShift(?array $schedule, ?DefaultScheduleVersion $default, string $date): array
    {
        if ($schedule && (bool) ($schedule['is_day_off'] ?? false)) {
            return [
                'is_day_off' => true,
                'shift_start' => null,
                'shift_end' => null,
                'break_minutes' => 0,
                'works_on_public_holiday' => false,
                'is_overnight' => false,
            ];
        }

        if ($schedule && ! empty($schedule['shift_start']) && ! empty($schedule['shift_end'])) {
            $shiftStart = substr((string) $schedule['shift_start'], 0, 5);
            $shiftEnd = substr((string) $schedule['shift_end'], 0, 5);

            return [
                'is_day_off' => false,
                'shift_start' => $shiftStart,
                'shift_end' => $shiftEnd,
                'break_minutes' => (int) ($schedule['break_minutes'] ?? 0),
                'works_on_public_holiday' => (bool) ($schedule['works_on_public_holiday'] ?? false),
                'is_overnight' => $shiftEnd <= $shiftStart,
            ];
        }

        $workDays = $default?->default_work_days ?? $this->config->defaultWorkDays();
        if (! in_array((int) Carbon::parse($date)->format('w'), array_map('intval', $workDays), true)) {
            return [
                'is_day_off' => true,
                'shift_start' => null,
                'shift_end' => null,
                'break_minutes' => 0,
                'works_on_public_holiday' => false,
                'is_overnight' => false,
            ];
        }

        $shiftStart = substr((string) ($default?->shift_start ?? $this->config->shiftStart()->format('H:i')), 0, 5);
        $shiftEnd = substr((string) ($default?->shift_end ?? $this->config->shiftEnd()->format('H:i')), 0, 5);

        return [
            'is_day_off' => false,
            'shift_start' => $shiftStart,
            'shift_end' => $shiftEnd,
            'break_minutes' => $default?->default_break_minutes ?? $this->config->defaultBreakMinutes(),
            'works_on_public_holiday' => false,
            'is_overnight' => $shiftEnd <= $shiftStart,
        ];
    }

    /**
     * Validate cross-field schedule rules that Laravel's wildcard `after`
     * rule cannot express for overnight shifts.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function validateItems(array $items): void
    {
        $errors = [];
        $seenDays = [];

        foreach ($items as $index => $item) {
            $day = (int) $item['day_of_week'];

            if (isset($seenDays[$day])) {
                $errors["schedules.{$index}.day_of_week"][] = 'Each day may only appear once.';
            }
            $seenDays[$day] = true;

            if ((bool) ($item['is_day_off'] ?? false)) {
                continue;
            }

            $start = $item['shift_start'] ?? null;
            $end = $item['shift_end'] ?? null;

            if (! $start || ! $end) {
                $errors["schedules.{$index}.shift_start"][] = 'Working days require both a shift start and shift end.';

                continue;
            }

            if ($start === $end) {
                $errors["schedules.{$index}.shift_end"][] = 'Shift end must differ from shift start.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
