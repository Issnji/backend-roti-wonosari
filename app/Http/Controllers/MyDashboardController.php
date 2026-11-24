<?php

namespace App\Http\Controllers;

use App\Models\PositionKpi;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class MyDashboardController extends Controller
{
    public function index(Request $request): JsonResource
    {
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $date = $request->filled('date') ? \Carbon\Carbon::parse($request->input('date')) : now();

        return new JsonResource($this->build($request->user(), $date));
    }

    /** @return array<string, mixed> */
    private function build(User $user, CarbonInterface $date): array
    {
        $user->loadMissing('position:id,name');

        $items     = $this->kpisForUser($user, $date);   // 1 query KPI + 1 query submission
        $threshold = (float) config('kpi.achieved_threshold', 100.0);
        $rows      = $items->map(fn (PositionKpi $pk) => $this->row($pk, $threshold));

        $total  = $rows->count();
        $filled = $rows->where('counted', true)->count();
        $weight = (float) $items->sum('weight');

        // state: ACHIEVED (tercapai) | UNFILLED (belum diisi) | NOT_ACHIEVED (tidak tercapai)
        $count = fn (string $state): int => $rows->filter(fn (array $r) => $r['state'] === $state)->count();

        $statuses = $items
            ->map(fn (PositionKpi $pk) => $pk->currentSubmission ? $this->statusOf($pk->currentSubmission->status) : null)
            ->filter()
            ->values();
        $has = fn (string $s): bool => $statuses->contains($s);

        $status = match (true) {
            $statuses->isEmpty()  => 'NOT_REPORTED',
            $has('REJECTED')      => 'REVISION',
            $has('SUBMITTED')     => 'PENDING',
            $has('DRAFT')         => 'DRAFT',
            $filled === $total    => 'APPROVED',
            default               => 'IN_PROGRESS',
        };

        $order = array_values(array_unique(array_values(config('kpi.dashboard_groups', []))));

        $groups = $rows->groupBy('group')
            ->sortBy(fn ($g, $label) => ($i = array_search($label, $order, true)) === false ? PHP_INT_MAX : $i)
            ->map(fn (Collection $g, string $label) => [
                'label'      => $label,
                'indicators' => $g->pluck('data')->values()->all(),
            ])
            ->values()
            ->all();

        $submittedAt = $items->map(fn (PositionKpi $pk) => $pk->currentSubmission?->submitted_at)->filter()->max();

        return [
            'date' => $date->toDateString(),
            'user' => [
                'id'       => $user->id,
                'name'     => $user->name,
                'position' => $user->position?->name,
            ],
            'report' => [
                'status'            => $status,
                'submitted_at'      => $submittedAt?->toIso8601String(),
                'estimated_minutes' => config('kpi.estimated_minutes'),
            ],
            'progress' => [
                'filled'  => $filled,
                'total'   => $total,
                'percent' => $total > 0 ? round($filled / $total * 100, 1) : 0.0,
            ],
            // rata-rata tertimbang pencapaian (cap 120% per indikator); belum diisi dihitung 0
            'score'   => $filled > 0 && $weight > 0 ? round($rows->sum('weighted') / $weight, 2) : null,
            'summary' => [
                'achieved'     => $count('ACHIEVED'),
                'not_achieved' => $count('NOT_ACHIEVED'),
                'unfilled'     => $count('UNFILLED'),
            ],
            'groups' => $groups,
        ];
    }

    /** @return array{group: string, state: string, counted: bool, weighted: float, data: array<string, mixed>} */
    private function row(PositionKpi $pk, float $threshold): array
    {
        $ind = $pk->kpiIndicator;
        $sub = $pk->currentSubmission;
        $st  = $sub ? $this->statusOf($sub->status) : null;

        // Hanya yang sudah dikirim/disetujui yang dihitung; Draft & Ditolak = belum selesai
        $counted     = in_array($st, ['SUBMITTED', 'APPROVED'], true);
        $achievement = null;

        if ($counted) {
            $achievement = $st === 'APPROVED' && $sub->review?->score !== null
                ? (float) $sub->review->score
                : $this->achievement($sub, $pk);
        }

        $state = match (true) {
            $achievement === null      => 'UNFILLED',
            $achievement >= $threshold => 'ACHIEVED',
            default                    => 'NOT_ACHIEVED',
        };

        $category = $ind->kpiCategory?->name ?? 'Lainnya';

        return [
            'group'    => config("kpi.dashboard_groups.$category", $category),
            'state'    => $state,
            'counted'  => $counted,
            'weighted' => $achievement !== null ? $achievement * (float) $pk->weight : 0.0,
            'data'     => [
                'position_kpi_id'   => $pk->id,
                'code'              => $ind->code,
                'name'              => $ind->name,
                'unit'              => $ind->unit,
                'input_type'        => $ind->assessment_type,
                'frequency'         => $pk->frequency,
                'target'            => (float) $pk->target,
                'weight'            => (float) $pk->weight,
                'requires_evidence' => (bool) $ind->requires_evidence,
                'state'             => $state,
                'status'            => $st,
                'achievement'       => $achievement,
                'is_editable'       => $sub ? $this->isEditable($sub->status) : true,
                'evidences_count'   => $sub->evidences_count ?? 0,
            ],
        ];
    }
}
