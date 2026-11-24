<?php

namespace App\Http\Controllers;

use App\Models\KpiCategory;
use Illuminate\Http\JsonResponse;

class KpiCategoriesController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = KpiCategory::query()
            ->with(['kpiIndicators' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $categories->map(fn ($c) => $this->categoryData($c)),
        ]);
    }
}
