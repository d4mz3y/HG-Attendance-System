<?php

namespace App\Http\Controllers\Api;

use App\Auth\Permission;
use App\Http\Controllers\Controller;
use App\Models\PublicHoliday;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicHolidayController extends Controller
{
    private function ensureCanManageHolidays(Request $request): void
    {
        if (! $request->user()->hasPermission(Permission::HOLIDAY_MANAGE)) {
            throw ValidationException::withMessages([
                'access' => ['Your role cannot manage public holidays.'],
            ]);
        }
    }

    public function index(Request $request)
    {
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);
        $year = (int) ($data['year'] ?? now()->year);
        $from = Carbon::create($year, 1, 1)->startOfDay();
        $to = $from->copy()->endOfYear();
        $holidays = PublicHoliday::occurrencesBetween($from, $to);

        return response()->json($holidays);
    }

    public function store(Request $request)
    {
        $this->ensureCanManageHolidays($request);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_recurring' => ['boolean'],
        ]);

        $holiday = DB::transaction(function () use ($data): PublicHoliday {
            PublicHoliday::freezeHistoryThrough(now()->subDay()->startOfDay());
            $date = Carbon::parse($data['date'])->startOfDay();
            $isRecurring = (bool) ($data['is_recurring'] ?? false);
            $this->ensureDateCanChangeHistory($date);
            $this->ensureDateIsAvailable($date, $isRecurring);

            return $this->saveOrFailWithValidation(new PublicHoliday, [
                ...$data,
                'date' => $date->toDateString(),
                'is_recurring' => $isRecurring,
            ]);
        }, 3);

        return response()->json($holiday, 201);
    }

    public function update(Request $request, PublicHoliday $publicHoliday)
    {
        $this->ensureCanManageHolidays($request);
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_recurring' => ['boolean'],
        ]);

        $publicHoliday = DB::transaction(function () use ($publicHoliday, $data): PublicHoliday {
            PublicHoliday::freezeHistoryThrough(now()->subDay()->startOfDay());
            $locked = PublicHoliday::query()->whereKey($publicHoliday->id)->lockForUpdate()->firstOrFail();
            $date = isset($data['date'])
                ? Carbon::parse($data['date'])->startOfDay()
                : $locked->date->copy()->startOfDay();
            $isRecurring = array_key_exists('is_recurring', $data)
                ? (bool) $data['is_recurring']
                : (bool) $locked->is_recurring;

            $cutoff = PublicHoliday::historyCutoff();
            $dateChanged = ! $date->isSameDay($locked->date);
            if ($dateChanged || ($locked->is_recurring && ! $isRecurring)) {
                $this->ensureDateCanChangeHistory($date);
            }
            if (! $locked->is_recurring && $cutoff && $locked->date->lessThanOrEqualTo($cutoff)) {
                throw ValidationException::withMessages([
                    'date' => ['A completed one-off holiday is immutable because it is already part of attendance history.'],
                ]);
            }

            $this->ensureDateIsAvailable($date, $isRecurring, $locked->id);

            return $this->saveOrFailWithValidation($locked, [
                ...$data,
                'date' => $date->toDateString(),
                'is_recurring' => $isRecurring,
            ]);
        }, 3);

        return response()->json($publicHoliday->fresh());
    }

    public function destroy(Request $request, PublicHoliday $publicHoliday)
    {
        $this->ensureCanManageHolidays($request);
        DB::transaction(function () use ($publicHoliday): void {
            PublicHoliday::freezeHistoryThrough(now()->subDay()->startOfDay());
            PublicHoliday::query()->whereKey($publicHoliday->id)->lockForUpdate()->firstOrFail()->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function upcoming(Request $request)
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'between:1,366']]);
        $days = (int) ($data['days'] ?? 30);
        $from = now()->startOfDay();
        $to = $from->copy()->addDays($days);

        $holidays = PublicHoliday::occurrencesBetween($from, $to)
            ->map(fn (PublicHoliday $holiday) => [
                'id' => $holiday->id,
                'date' => $holiday->date->toDateString(),
                'name' => $holiday->name,
                'is_recurring' => $holiday->is_recurring,
                'source_date' => $holiday->source_date,
            ])
            ->values();

        return response()->json($holidays);
    }

    public function show(PublicHoliday $publicHoliday)
    {
        return response()->json($publicHoliday);
    }

    private function ensureDateIsAvailable(Carbon $date, bool $isRecurring, ?int $exceptId = null): void
    {
        $conflict = PublicHoliday::query()
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->where(function ($query) use ($date, $isRecurring): void {
                $query->whereDate('date', $date->toDateString());

                if ($isRecurring) {
                    $query->orWhere(function ($sameMonthDay) use ($date): void {
                        $sameMonthDay->whereMonth('date', $date->month)
                            ->whereDay('date', $date->day);
                    });
                } else {
                    $query->orWhere(function ($annual) use ($date): void {
                        $annual->where('is_recurring', true)
                            ->whereMonth('date', $date->month)
                            ->whereDay('date', $date->day);
                    });
                }
            })
            ->lockForUpdate()
            ->first();

        if ($conflict) {
            throw ValidationException::withMessages([
                'date' => ["{$conflict->name} already covers this calendar date."],
            ]);
        }
    }

    private function ensureDateCanChangeHistory(Carbon $date): void
    {
        $cutoff = PublicHoliday::historyCutoff();
        if ($cutoff && $date->lessThanOrEqualTo($cutoff)) {
            throw ValidationException::withMessages([
                'date' => ["Historical holidays are frozen through {$cutoff->toDateString()}. Choose a later effective date."],
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function saveOrFailWithValidation(PublicHoliday $holiday, array $attributes): PublicHoliday
    {
        try {
            $holiday->fill($attributes)->save();

            return $holiday;
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)
                || str_contains(strtolower($exception->getMessage()), 'unique')
                || str_contains(strtolower($exception->getMessage()), 'duplicate')) {
                throw ValidationException::withMessages([
                    'date' => ['A public holiday already covers this calendar date.'],
                ]);
            }

            throw $exception;
        }
    }
}
