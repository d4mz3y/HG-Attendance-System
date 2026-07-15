<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BiometricService;
use Illuminate\Http\Request;

class BiometricController extends Controller
{
    public function __construct(
        protected BiometricService $biometric
    ) {}

    public function punch(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:128'],
            'device_id' => ['nullable', 'string', 'max:128'],
            'metadata' => ['nullable', 'array'],
        ]);

        $result = $this->biometric->handlePunch(
            $data['identifier'],
            $data['device_id'] ?? 'unknown',
            $data['metadata'] ?? null
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
