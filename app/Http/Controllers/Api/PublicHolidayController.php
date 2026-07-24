<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PublicHoliday;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PublicHolidayController extends Controller
{
    private function ensureSuperAdmin(Request $request): void
    {
        if ($request->user()->role !== 'super_admin') {
            throw ValidationException::withMessages([
                'access' => ['Only super admins can perform this action.'],
            ]);
        }
    }

    public function index(Request $request)
    {
        $year = $request->integer('year') ?: now()->year;
        
        $holidays = PublicHoliday::query()
            ->whereYear('date', $year)
            ->orWhere('is_recurring', true)
            ->orderBy('date')
            ->get();

        return response()->json($holidays);
    }

    public function store(Request $request)
    {
        $this->ensureSuperAdmin($request);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_recurring' => ['boolean'],
        ]);

        $holiday = PublicHoliday::query()->create($data);

        return response()->json($holiday, 201);
    }

    public function update(Request $request, PublicHoliday $publicHoliday)
    {
        $this->ensureSuperAdmin($request);
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_recurring' => ['boolean'],
        ]);

        $publicHoliday->update($data);

        return response()->json($publicHoliday->fresh());
    }

    public function destroy(Request $request, PublicHoliday $publicHoliday)
    {
        $this->ensureSuperAdmin($request);
        $publicHoliday->delete();

        return response()->json(['ok' => true]);
    }

    public function upcoming(Request $request)
    {
        $days = (int) $request->integer('days', 30);
        $from = now()->startOfDay();
        $to = $from->copy()->addDays($days);

        $holidays = PublicHoliday::query()
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('date', [$from, $to])
                  ->orWhere('is_recurring', true);
            })
            ->orderBy('date')
            ->get(['id', 'date', 'name', 'is_recurring']);

        return response()->json($holidays);
    }
}
