<?php

namespace App\Http\Controllers;

use App\Models\KpiSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminKpiSubmissionController extends Controller
{
    public function index(Request $request)
    {
        $f = $request->validate($this->submissionFilterRules());

        $page = KpiSubmission::query()
            ->with([
                'user:id,name,role_id,position_id',
                'user.role:id,name',
                'user.position:id,name',
                'positionKpi:id,position_id,kpi_indicator_id,target,weight,calculation_type',
                'positionKpi.kpiIndicator:id,name,unit,assessment_type',
                // Catatan & peninjau dipakai halaman Tinjau Revisi Admin
                'review:id,submission_id,reviewer_id,review_type,status,score,notes,reviewed_at,finalized_at,final_notes',
                'review.reviewer:id,name,role_id',
                'review.reviewer.role:id,name',
            ])
            ->withCount('evidences')
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['role_id'] ?? null, fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('role_id', $v)))
            ->when($f['position_id'] ?? null, fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('position_id', $v)))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->whereDate('period_start', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->whereDate('period_end', '<=', $v))
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->paginate((int) ($f['per_page'] ?? 15));

        $page->through(fn ($s) => $this->adminSubmissionData($s));

        return JsonResource::collection($page);
    }

    public function show(KpiSubmission $kpiSubmission): JsonResponse
    {
        $kpiSubmission->load([
            'user.role:id,name',
            'user.position:id,name',
            'positionKpi.kpiIndicator',
            'evidences',
            'review.reviewer:id,name',
        ]);

        return response()->json(['data' => $this->adminSubmissionData($kpiSubmission)]);
    }
}