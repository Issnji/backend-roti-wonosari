<?php

namespace App\Http\Controllers;

use App\Models\KpiSubmission;
use App\Models\PositionKpi;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MyKpiHistoryController extends Controller
{
    /** Halaman Riwayat: chip ringkasan + daftar laporan (filter ?status=APPROVED|PENDING|REVISION). */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status'    => ['nullable', Rule::in(['APPROVED', 'PENDING', 'REVISION'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user          = $request->user();
        $perPage       = (int) ($f['per_page'] ?? 15);
        $totalExpected = $this->assignedPerCategory($user)->sum();

        // 1 query agregat; per user jumlah laporan kecil (maks ±1 baris/hari)
        $all = KpiSubmission::query()
            ->select(
                'report_date',
                DB::raw('COUNT(*) as filled_count'),
                DB::raw("SUM(CASE WHEN status = 'SUBMITTED' THEN 1 ELSE 0 END) as submitted_count"),
                DB::raw("SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) as rejected_count"),
                DB::raw('MAX(submitted_at) as submitted_at'),
            )
            ->where('user_id', $user->id)
            ->whereNotNull('report_date')
            ->where('status', '!=', 'DRAFT')
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->whereDate('report_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->whereDate('report_date', '<=', $v))
            ->groupBy('report_date')
            ->orderByDesc('report_date')
            ->get()
            ->map(function ($r) use ($totalExpected): array {
                $status = $this->reportStatus((int) $r->submitted_count, (int) $r->rejected_count);
                $filled = (int) $r->filled_count;

                return [
                    'report_date'  => $r->report_date->toDateString(),
                    'status'       => $status,
                    'filled_count' => $filled,
                    'total_count'  => max($totalExpected, $filled),
                    'submitted_at' => $r->submitted_at
                        ? \Carbon\Carbon::parse($r->submitted_at)->toIso8601String()
                        : null,
                ];
            });

        // Ringkasan chip (Semua / Disetujui / Menunggu / Revisi), tidak terpengaruh filter status
        $counts  = $all->countBy(fn (array $r) => $r['status']);
        $summary = [
            'all'      => $all->count(),
            'approved' => $counts['APPROVED'] ?? 0,
            'pending'  => $counts['PENDING'] ?? 0,
            'revision' => $counts['REVISION'] ?? 0,
        ];

        $filtered = $all
            ->when($f['status'] ?? null, fn (Collection $c, string $s) => $c->filter(
                fn (array $r) => $r['status'] === $s
            ))
            ->values();

        $page = max(1, (int) request('page', 1));

        $data = $filtered->forPage($page, $perPage)->map(fn (array $r) => [
            ...$r,
            'status_label' => $this->reportStatusLabel($r['status']),
        ])->values();

        return response()->json([
            'summary' => $summary,
            'data'    => $data,
            'meta'    => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => $filtered->count(),
                'last_page'    => max(1, (int) ceil($filtered->count() / $perPage)),
            ],
        ]);
    }

    /** Halaman Detail Riwayat: ringkasan per kategori + catatan revisi. */
    public function show(Request $request, string $date): JsonResponse
    {
        $user = $request->user();

        $items = KpiSubmission::query()
            ->with([
                'positionKpi.kpiIndicator.kpiCategory:id,name',
                'evidences',
                'review.reviewer:id,name,position_id',
                'review.reviewer.position:id,name',
            ])
            ->where('user_id', $user->id)
            ->whereDate('report_date', $date)
            ->where('status', '!=', 'DRAFT')
            ->orderBy('id')
            ->get();

        abort_if($items->isEmpty(), 404, 'Tidak ada riwayat pada tanggal tersebut.');

        $is = fn (KpiSubmission $s, string $st): bool => $this->statusOf($s->status) === $st;

        $status = $this->reportStatus(
            $items->filter(fn ($s) => $is($s, 'SUBMITTED'))->count(),
            $items->filter(fn ($s) => $is($s, 'REJECTED'))->count(),
        );

        // Indikator yang memicu penolakan (MANUAL), lawan dari yang ikut ditolak (CASCADE)
        $flagged = fn (KpiSubmission $s): bool => $is($s, 'REJECTED')
            && $s->review?->review_type === 'MANUAL';

        $trigger = $items->first($flagged)
            ?? $items->first(fn ($s) => $is($s, 'REJECTED'));

        $revision = ($status === 'REVISION' && $trigger?->review) ? [
            'requested_by' => [
                'name'     => $trigger->review->reviewer?->name,
                'position' => $trigger->review->reviewer?->position?->name,
            ],
            'reviewed_at'  => $trigger->review->reviewed_at?->toIso8601String(),
            'notes'        => $trigger->review->notes,
        ] : null;

        $assigned = $this->assignedPerCategory($user);

        $categories = $items
            ->groupBy(fn (KpiSubmission $s) => $s->positionKpi->kpiIndicator->category_id)
            ->map(function (Collection $group, int|string $categoryId) use ($assigned, $flagged): array {
                $category   = $group->first()->positionKpi->kpiIndicator->kpiCategory;
                $flaggedOne = $group->first($flagged);

                return [
                    'category'       => ['id' => $category->id, 'name' => $category->name],
                    'filled_count'   => $group->count(),
                    'total_count'    => max((int) ($assigned[$categoryId] ?? 0), $group->count()),
                    'needs_revision' => $group->contains($flagged),
                    'revision_note'  => $flaggedOne?->review?->notes,
                    'indicators'     => $group->map(fn ($s) => $this->indicatorRow($s))->values()->all(),
                ];
            })
            ->values();

        return response()->json(['data' => [
            'report_date'  => $date,
            'status'       => $status,
            'status_label' => $this->reportStatusLabel($status),
            'submitted_at' => $items->max('submitted_at')?->toIso8601String(),
            'can_fix'      => $status === 'REVISION',
            'revision'     => $revision,
            'categories'   => $categories,
        ]]);
    }

    // ---------------- fungsi bantu (private) ----------------

    /** Satu indikator di detail riwayat (dulu KpiHistoryIndicatorResource). */
    private function indicatorRow(KpiSubmission $s): array
    {
        $indicator = $s->positionKpi->kpiIndicator;
        $status    = $this->statusOf($s->status);

        // Review lama bisa tertinggal saat karyawan kirim ulang -> tampilkan hanya jika sudah diputuskan
        $review = in_array($status, ['APPROVED', 'REJECTED'], true) ? $s->review : null;

        return [
            'id'              => $s->id,
            'position_kpi_id' => $s->position_kpi_id,
            'name'            => $indicator->name,
            'unit'            => $indicator->unit,
            'input_type'      => $indicator->assessment_type,
            'target'          => (float) $s->positionKpi->target,
            'weight'          => (float) $s->positionKpi->weight,
            'answer_yes_no'   => $s->answer_yes_no,
            'numeric_value'   => $s->numeric_value !== null ? (float) $s->numeric_value : null,
            'status'          => $status,
            'is_editable'     => $this->isEditable($s->status),
            'evidences'       => $s->evidences->map(fn ($e) => [
                'id'        => $e->id,
                'file_name' => $e->file_name,
                'url'       => Storage::disk('public')->url($e->file_path),
            ])->values(),
            'review'          => $review ? [
                'status'      => $review->status,
                'review_type' => $review->review_type, // MANUAL | CASCADE
                'score'       => $review->score !== null ? (float) $review->score : null,
                'notes'       => $review->notes,
                'reviewed_at' => $review->reviewed_at?->toIso8601String(),
            ] : null,
        ];
    }

    /** @return Collection<int, int> category_id => jumlah KPI aktif jabatan user */
    private function assignedPerCategory(User $user): Collection
    {
        if (! $user->position_id) {
            return collect();
        }

        return PositionKpi::query()
            ->with('kpiIndicator:id,category_id')
            ->where('position_id', $user->position_id)
            ->where('is_active', true)
            ->get()
            ->groupBy(fn (PositionKpi $pk) => $pk->kpiIndicator->category_id)
            ->map->count();
    }
}
