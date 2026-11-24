<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyKpiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date  = $request->filled('date') ? Carbon::parse($request->input('date')) : null;
        $items = $this->kpisForUser($request->user(), $date);

        return response()->json([
            'data' => $items->map(fn ($pk) => $this->positionKpiData($pk)),
        ]);
    }
}
