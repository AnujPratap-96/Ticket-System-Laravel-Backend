<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\Ai\AiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiSettingsController extends Controller
{
    public function show(AiClient $ai): JsonResponse
    {
        return response()->json($this->state($ai));
    }

    public function update(Request $request, AiClient $ai): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        AppSetting::put('ai_enabled', $data['enabled'] ? '1' : '0');

        return response()->json($this->state($ai));
    }

    private function state(AiClient $ai): array
    {
        return [
            'configured' => $ai->configured(),
            'enabled' => AppSetting::get('ai_enabled', '1') === '1',
            'model' => config('services.ai.model'),
            'usage_today' => $ai->usageToday(),
            'daily_limit' => (int) config('services.ai.daily_limit'),
        ];
    }
}
