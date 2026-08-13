<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PublicHoliday extends Model
{
    protected $hidden = ['recurring_month_day'];

    protected $fillable = [
        'date',
        'name',
        'description',
        'is_recurring',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_recurring' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PublicHoliday $holiday): void {
            $holiday->recurring_month_day = $holiday->is_recurring && $holiday->date
                ? $holiday->date->format('m-d')
                : null;
        });
    }

    public static function occursOn(CarbonInterface $date): bool
    {
        return static::occurrencesBetween($date, $date)->isNotEmpty();
    }

    /**
     * Return holiday models with recurring dates projected into the requested
     * range. The persisted source date is exposed as source_date.
     *
     * @return Collection<int, self>
     */
    public static function occurrencesBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $start = Carbon::instance($from)->startOfDay();
        $end = Carbon::instance($to)->startOfDay();

        if ($end->lessThan($start)) {
            return new Collection;
        }

        $cutoff = static::historyCutoff();
        $snapshots = new Collection;
        if ($cutoff && $start->lessThanOrEqualTo($cutoff)) {
            $snapshotEnd = $end->min($cutoff);
            $snapshots = HolidayOccurrenceSnapshot::query()
                ->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $snapshotEnd->toDateString())
                ->orderBy('date')
                ->get()
                ->map(function (HolidayOccurrenceSnapshot $snapshot): PublicHoliday {
                    $holiday = new static;
                    $holiday->id = $snapshot->public_holiday_id;
                    $holiday->exists = false;
                    $holiday->setAttribute('date', $snapshot->date->copy());
                    $holiday->setAttribute('name', $snapshot->name);
                    $holiday->setAttribute('description', $snapshot->description);
                    $holiday->setAttribute('is_recurring', $snapshot->is_recurring);
                    $holiday->setAttribute('source_date', $snapshot->source_date?->toDateString());
                    $holiday->setAttribute('historical', true);

                    return $holiday;
                });
        }

        $liveStart = $cutoff ? $start->max($cutoff->copy()->addDay()) : $start;
        $live = $liveStart->lessThanOrEqualTo($end)
            ? static::projectedOccurrences($liveStart, $end)
            : new Collection;

        return $snapshots
            ->concat($live)
            ->sortBy(fn (PublicHoliday $holiday) => $holiday->date->toDateString())
            ->values();
    }

    public static function historyCutoff(): ?Carbon
    {
        $value = Setting::getValue('holiday_history_frozen_through');

        return $value ? Carbon::parse($value)->startOfDay() : null;
    }

    public static function freezeHistoryThrough(CarbonInterface $through): int
    {
        return DB::transaction(function () use ($through): int {
            $end = Carbon::instance($through)->startOfDay();
            $now = now();
            // Ensure a row exists before locking it. This serializes the
            // snapshot range even when this method is called by the nightly
            // command and a holiday edit at the same time.
            Setting::query()->insertOrIgnore([[
                'key' => 'holiday_history_frozen_through',
                'value' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]]);
            $cutoffSetting = Setting::query()
                ->where('key', 'holiday_history_frozen_through')
                ->lockForUpdate()
                ->firstOrFail();
            $current = filled($cutoffSetting->value)
                ? Carbon::parse($cutoffSetting->value)->startOfDay()
                : null;
            if ($current && $current->greaterThanOrEqualTo($end)) {
                return 0;
            }

            $start = $current?->copy()->addDay() ?? Carbon::create(1970, 1, 1)->startOfDay();
            $occurrences = static::projectedOccurrences($start, $end);
            $rows = $occurrences->map(fn (PublicHoliday $holiday): array => [
                'date' => $holiday->date->toDateString(),
                'public_holiday_id' => $holiday->id,
                'source_date' => $holiday->source_date,
                'name' => $holiday->name,
                'description' => $holiday->description,
                'is_recurring' => (bool) $holiday->is_recurring,
                'created_at' => $now,
            ])->all();
            if ($rows !== []) {
                DB::table('holiday_occurrence_snapshots')->insertOrIgnore($rows);
            }

            $cutoffSetting->forceFill([
                'value' => $end->toDateString(),
                'updated_at' => $now,
            ])->save();

            return count($rows);
        }, 3);
    }

    /** @return Collection<int, self> */
    private static function projectedOccurrences(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $start = Carbon::instance($from)->startOfDay();
        $end = Carbon::instance($to)->startOfDay();
        if ($end->lessThan($start)) {
            return new Collection;
        }

        $holidays = static::query()
            ->where(function ($query) use ($start, $end): void {
                $query->whereDate('date', '>=', $start->toDateString())
                    ->whereDate('date', '<=', $end->toDateString())
                    ->orWhere('is_recurring', true);
            })
            ->get();

        $occurrences = new Collection;

        foreach ($holidays as $holiday) {
            $sourceDate = $holiday->date->toDateString();

            if (! $holiday->is_recurring) {
                $copy = $holiday->replicate();
                $copy->id = $holiday->id;
                $copy->exists = true;
                $copy->setAttribute('date', $holiday->date->copy());
                $copy->setAttribute('source_date', $sourceDate);
                $occurrences->push($copy);

                continue;
            }

            for ($year = max($start->year, $holiday->date->year); $year <= $end->year; $year++) {
                try {
                    $occurrence = Carbon::createSafe(
                        $year,
                        $holiday->date->month,
                        $holiday->date->day,
                        0,
                        0,
                        0,
                        $start->timezone
                    );
                } catch (\InvalidArgumentException) {
                    // A recurring leap-day holiday simply has no occurrence in
                    // a non-leap year.
                    continue;
                }

                if ($occurrence->betweenIncluded($start, $end)) {
                    $copy = $holiday->replicate();
                    $copy->id = $holiday->id;
                    $copy->exists = true;
                    $copy->setAttribute('date', $occurrence);
                    $copy->setAttribute('source_date', $sourceDate);
                    $occurrences->push($copy);
                }
            }
        }

        return $occurrences
            ->sort(function (PublicHoliday $left, PublicHoliday $right): int {
                $dateComparison = $left->date->toDateString() <=> $right->date->toDateString();
                if ($dateComparison !== 0) {
                    return $dateComparison;
                }

                // A one-off definition is the explicit override for that
                // year when legacy data also contains an annual definition.
                $recurrenceComparison = (int) $left->is_recurring <=> (int) $right->is_recurring;

                return $recurrenceComparison !== 0
                    ? $recurrenceComparison
                    : $left->id <=> $right->id;
            })
            ->unique(fn (PublicHoliday $holiday) => $holiday->date->toDateString())
            ->values();
    }

    public function toArray(): array
    {
        $array = parent::toArray();
        $array['date'] = $this->date?->format('Y-m-d');

        return $array;
    }
}
