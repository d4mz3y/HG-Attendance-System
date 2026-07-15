<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Leave;
use App\Services\LeaveService;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function __construct(
        protected LeaveService $leaves
    ) {}

    public function index(Request $request)
    {
        $from = $request->date('date_from') ?? now()->startOfMonth();
        $to = $request->date('date_to') ?? now()->endOfMonth();
        $status = $request->string('status')->toString();
        $department = $request->string('department')->toString();

        $q = Leave::query()
            ->with(['staff', 'creator'])
            ->whereBetween('start_date', [$from->toDateString(), $to->toDateString()]);

        if ($status) {
            $q->where('status', $status);
        }

        if ($department) {
            $q->whereHas('staff', fn ($s) => $s->where('department', $department));
        }

        return $q->orderByDesc('start_date')->paginate((int) $request->get('per_page', 25));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'staff_id' => ['required', 'integer', 'exists:staff,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'type' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:Pending,Approved,Rejected'],
        ]);

        if (isset($data['status']) && $data['status'] === 'Approved') {
            $data['approved_at'] = now();
        }

        $data['created_by'] = $request->user()->id;

        $leave = Leave::query()->create($data);

        return response()->json($leave->load('staff', 'creator'), 201);
    }

    public function update(Request $request, Leave $leave)
    {
        $data = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'type' => ['nullable', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:Pending,Approved,Rejected'],
        ]);

        if (isset($data['status']) && $data['status'] === 'Approved' && ! $leave->approved_at) {
            $data['approved_at'] = now();
        }

        $leave->update($data);

        return response()->json($leave->fresh()->load('staff', 'creator'));
    }

    public function destroy(Leave $leave)
    {
        $leave->delete();

        return response()->json(['ok' => true]);
    }

    public function upcoming(Request $request, int $staffId)
    {
        $days = (int) $request->integer('days', 30);

        return response()->json($this->leaves->upcomingForStaff(\App\Models\Staff::findOrFail($staffId), $days));
    }

    public function show(Leave $leave)
    {
        return response()->json($leave->load('staff', 'creator'));
    }
}
