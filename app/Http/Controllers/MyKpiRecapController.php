<?php

namespace App\Http\Controllers;

use App\Http\Requests\MyKpiRecapRequest;
use App\Services\KpiRecapService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class MyKpiRecapController extends Controller
{
    public function __construct(private readonly KpiRecapService $service) {}

    public function index(MyKpiRecapRequest $request): JsonResponse
    {
        $month = $request->filled('month')
            ? Carbon::createFromFormat('Y-m-d', $request->input('month') . '-01')
            : now();

        return response()->json(['data' => $this->service->monthly($request->user(), $month)]);
    }
}