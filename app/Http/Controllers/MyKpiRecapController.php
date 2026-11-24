<?php

namespace App\Http\Controllers;

use App\Models\KpiSubmission;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MyKpiRecapController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $month = $request->filled('month')
            ? Carbon::createFromFormat('Y-m-d', $request->input('month') . '-01')
            : now();

        $user      = $request->user();
        $start     = $month->copy()->startOfMonth();
        $end       = $month->copy()->endOfMonth();
        $threshold = (float) config('kpi.achieved_threshold', 100.0);

        // 1 query; semua relasi di-eager load
        $rows = KpiSubmission::query()
            ->with(['positionKpi.kpiIndicator.kpiCategory:id,name', 'review'])
            ->where('user_id', $user->id)
            ->where('status', '!=', 'DRAFT')
            ->whereDate('report_date', '>=', $start->toDateString())
            ->whereDate('report_date', '<=', $end->toDateString())
            ->orderBy('report_date')
            ->get();

        // hitung capaian berstatus tertentu dalam satu kelompok
        $countStatus = fn (Collection $c, string $st): int => $c
            ->filter(fn (KpiSubmission $s) => $this->statusOf($s->status) === $st)
            ->count();

        $days = $rows->groupBy(fn (KpiSubmission $s) => $s->report_date->toDateString())
            ->map(function (Collection $day, string $date) use ($countStatus): array {
                $status = $this->reportStatus(
                    $countStatus($day, 'SUBMITTED'),
                    $countStatus($day, 'REJECTED'),
                );

                return [
                    'date'         => $date,
                    'status'       => $status,
                    'status_label' => $this->reportStatusLabel($status),
                    'filled_count' => $day->count(),
                    'score'        => $this->score($day),
                ];
            })
            ->values();

        $approved = $this->approved($rows);

        $categories = $approved
            ->groupBy(function (KpiSubmission $s): string {
                $name = $s->positionKpi?->kpiIndicator?->kpiCategory?->name ?? 'Lainnya';

                return config("kpi.dashboard_groups.$name", $name);
            })
            ->map(function (Collection $g, string $label) use ($threshold): array {
                $achieved = $g->filter(fn ($s) => (float) $s->review->score >= $threshold)->count();

                return [
                    'label'    => $label,
                    'achieved' => $achieved,
                    'total'    => $g->count(),
                    'percent'  => round($achieved / $g->count() * 100, 1),
                    'score'    => $this->score($g),
                ];
            })
            ->values();

        $countDays = fn (string $s): int => $days->where('status', $s)->count();

        return response()->json(['data' => [
            'month'   => $start->format('Y-m'),
            'summary' => [
                'days_reported'       => $days->count(),
                'days_approved'       => $countDays('APPROVED'),
                'days_pending'        => $countDays('PENDING'),
                'days_revision'       => $countDays('REVISION'),
                'indicators_total'    => $rows->count(),
                'indicators_approved' => $approved->count(),
                'score'               => $this->score($rows),   // rata-rata tertimbang indikator yang disetujui
            ],
            'categories' => $categories,
            'days'       => $days,
        ]]);
    }

    /** @return Collection<int, KpiSubmission> yang disetujui dan sudah punya skor */
    private function approved(Collection $rows): Collection
    {
        return $rows
            ->filter(fn (KpiSubmission $s) => $this->statusOf($s->status) === 'APPROVED' && $s->review?->score !== null)
            ->values();
    }

    private function score(Collection $rows): ?float
    {
        $approved = $this->approved($rows);
        $weight   = $approved->sum(fn (KpiSubmission $s) => (float) ($s->positionKpi?->weight ?? 0));

        if ($weight <= 0) {
            return null;
        }

        return round(
            $approved->sum(fn (KpiSubmission $s) => (float) ($s->positionKpi?->weight ?? 0) * (float) $s->review->score) / $weight,
            2
        );
    }
}
