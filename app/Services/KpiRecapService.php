<?php

namespace App\Services;

use App\Enums\KpiReportStatus;
use App\Enums\KpiSubmissionStatus as S;
use App\Models\KpiSubmission;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class KpiRecapService
{
    /** @return array<string, mixed> */
    public function monthly(User $user, CarbonInterface $month): array
    {
        $start     = $month->copy()->startOfMonth();
        $end       = $month->copy()->endOfMonth();
        $threshold = (float) config('kpi.achieved_threshold', 100.0);

        // 1 query; semua relasi di-eager load
        $rows = KpiSubmission::query()
            ->with(['positionKpi.kpiIndicator.kpiCategory:id,name', 'review'])
            ->where('user_id', $user->id)
            ->where('status', '!=', S::Draft->value)
            ->whereDate('report_date', '>=', $start->toDateString())
            ->whereDate('report_date', '<=', $end->toDateString())
            ->orderBy('report_date')
            ->get();

        $days = $rows->groupBy(fn (KpiSubmission $s) => $s->report_date->toDateString())
            ->map(function (Collection $day, string $date): array {
                $status = KpiReportStatus::fromCounts(
                    $day->where('status', S::Submitted)->count(),
                    $day->where('status', S::Rejected)->count(),
                );

                return [
                    'date'         => $date,
                    'status'       => $status->value,
                    'status_label' => $status->label(),
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

        $count = fn (string $s): int => $days->where('status', $s)->count();

        return [
            'month'   => $start->format('Y-m'),
            'summary' => [
                'days_reported'       => $days->count(),
                'days_approved'       => $count(KpiReportStatus::Approved->value),
                'days_pending'        => $count(KpiReportStatus::Pending->value),
                'days_revision'       => $count(KpiReportStatus::Revision->value),
                'indicators_total'    => $rows->count(),
                'indicators_approved' => $approved->count(),
                'score'               => $this->score($rows),   // rata-rata tertimbang indikator yang disetujui
            ],
            'categories' => $categories,
            'days'       => $days,
        ];
    }

    /** @return Collection<int, KpiSubmission> yang disetujui dan sudah punya skor */
    private function approved(Collection $rows): Collection
    {
        return $rows->filter(fn (KpiSubmission $s) => $s->status === S::Approved && $s->review?->score !== null)->values();
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