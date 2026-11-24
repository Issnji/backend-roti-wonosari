<?php

namespace App\Http\Controllers;

use App\Models\Position;
use Illuminate\Http\JsonResponse;

class PositionController extends Controller
{
    public function kpis(Position $position): JsonResponse
    {
        $items = $this->positionKpis($position->id);

        return response()->json([
            'data' => $items->map(fn ($pk) => $this->positionKpiData($pk)),
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => Position::orderBy('name')->get(['id', 'name'])]);
    }
}
