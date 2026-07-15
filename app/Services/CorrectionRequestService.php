<?php

namespace App\Services;

use App\Models\CorrectionRequest;
use App\Models\User;
use Carbon\Carbon;

class CorrectionRequestService
{
    public function create(array $data): CorrectionRequest
    {
        return CorrectionRequest::query()->create($data);
    }

    public function review(int $id, string $status, ?int $reviewerId = null, ?string $notes = null): CorrectionRequest
    {
        $request = CorrectionRequest::query()->findOrFail($id);
        $request->status = $status;
        $request->reviewed_by = $reviewerId;
        $request->reviewed_at = Carbon::now();
        $request->review_notes = $notes;
        $request->save();

        return $request;
    }

    public function pending(): array
    {
        return CorrectionRequest::query()
            ->with(['staff', 'reviewer'])
            ->where('status', 'Pending')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'staff_id' => $r->staff_id,
                'staff_name' => $r->staff?->full_name,
                'staff_code' => $r->staff?->staff_id,
                'attendance_id' => $r->attendance_id,
                'requested_clock_in' => $r->requested_clock_in?->toIso8601String(),
                'requested_clock_out' => $r->requested_clock_out?->toIso8601String(),
                'reason' => $r->reason,
                'status' => $r->status,
                'reviewed_by' => $r->reviewer?->username,
                'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                'review_notes' => $r->review_notes,
                'created_at' => $r->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
