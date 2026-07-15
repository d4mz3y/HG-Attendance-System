<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\AuditService;
use Illuminate\Http\Request;

class CorrectionRequestController extends Controller
{
    public function __construct(
        protected AuditService $audits,
        protected \App\Services\CorrectionRequestService $requests
    ) {}

    public function index(Request $request)
    {
        $status = $request->string('status')->toString();
        $staffPk = $request->integer('staff_pk');

        $q = \App\Models\CorrectionRequest::query()
            ->with(['staff', 'reviewer'])
            ->orderByDesc('created_at');

        if ($status) {
            $q->where('status', $status);
        }

        if ($staffPk) {
            $q->where('staff_id', $staffPk);
        }

        return $q->paginate((int) $request->get('per_page', 25));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'staff_id' => ['required', 'integer', 'exists:staff,id'],
            'attendance_id' => ['nullable', 'integer', 'exists:attendances,id'],
            'requested_clock_in' => ['nullable', 'date'],
            'requested_clock_out' => ['nullable', 'date', 'after:requested_clock_in'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['requested_clock_in'] = isset($data['requested_clock_in']) ? \Carbon\Carbon::parse($data['requested_clock_in']) : null;
        $data['requested_clock_out'] = isset($data['requested_clock_out']) ? \Carbon\Carbon::parse($data['requested_clock_out']) : null;

        $item = $this->requests->create($data);

        return response()->json($item->load('staff'), 201);
    }

    public function review(Request $request, \App\Models\CorrectionRequest $correctionRequest)
    {
        $data = $request->validate([
            'status' => ['required', 'in:Approved,Rejected'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $item = $this->requests->review(
            $correctionRequest->id,
            $data['status'],
            $request->user()->id,
            $data['review_notes'] ?? null
        );

        if ($data['status'] === 'Approved' && $correctionRequest->attendance_id) {
            $attendance = Attendance::find($correctionRequest->attendance_id);
            if ($attendance) {
                $oldIn = $attendance->clock_in;
                $oldOut = $attendance->clock_out;

                if ($item->requested_clock_in) {
                    $attendance->clock_in = $item->requested_clock_in;
                    $attendance->date = $item->requested_clock_in->toDateString();
                }
                if ($item->requested_clock_out) {
                    $attendance->clock_out = $item->requested_clock_out;
                }

                $attendance->save();

                $this->audits->log(
                    $attendance,
                    ['clock_in', 'clock_out'],
                    ['clock_in' => $oldIn, 'clock_out' => $oldOut],
                    ['clock_in' => $attendance->clock_in, 'clock_out' => $attendance->clock_out],
                    $request->user()->id,
                    $request->ip(),
                    'Approved via correction request'
                );
            }
        }

        return response()->json($item->load('reviewer', 'attendance'));
    }
}
