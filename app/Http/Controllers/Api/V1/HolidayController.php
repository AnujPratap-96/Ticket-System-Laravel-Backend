<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\HolidayRequest;
use App\Models\BusinessHoliday;
use Illuminate\Http\JsonResponse;

class HolidayController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'holidays' => BusinessHoliday::orderBy('holiday_date')->get()->map(fn ($h) => [
                'id' => $h->id,
                'holiday_date' => $h->holiday_date instanceof \DateTimeInterface ? $h->holiday_date->format('Y-m-d') : substr((string) $h->holiday_date, 0, 10),
                'name' => $h->name,
            ]),
        ]);
    }

    /**
     * Holidays affect SLA deadlines calculated after they are added.
     */
    public function store(HolidayRequest $request): JsonResponse
    {
        $holiday = BusinessHoliday::create($request->validated());

        return response()->json(['message' => 'Holiday added', 'holiday' => $holiday], 201);
    }

    public function destroy(BusinessHoliday $holiday): JsonResponse
    {
        $holiday->delete();

        return response()->json(['message' => 'Holiday removed']);
    }
}
