<?php

namespace App\Http\Controllers;

use App\Models\KpiCategory;
use App\Models\KpiIndicator;
use App\Models\KpiSubmission;
use App\Models\PositionKpi;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Controller induk. Semua controller lain "extends" ke sini,
 * jadi fungsi di bawah ini bisa dipakai bersama (tanpa folder Resource/Service/Support).
 */
abstract class Controller
{
    /** Batas pencapaian maksimum (%) */
    protected const SCORE_CAP = 120.0;

    // ------------------------------------------------------------------
    // Status & periode
    // ------------------------------------------------------------------

    /** Ambil nilai status berupa string ('DRAFT', 'SUBMITTED', ...). */
    protected function statusOf($status): string
    {
        return $status instanceof \BackedEnum ? $status->value : (string) $status;
    }

    /** Karyawan hanya boleh mengubah saat DRAFT atau setelah ditolak. */
    protected function isEditable($status): bool
    {
        return in_array($this->statusOf($status), ['DRAFT', 'REJECTED'], true);
    }

    /** Status laporan harian: REVISION / PENDING / APPROVED. */
    protected function reportStatus(int $submitted, int $rejected): string
    {
        return match (true) {
            $rejected > 0  => 'REVISION',
            $submitted > 0 => 'PENDING',
            default        => 'APPROVED',
        };
    }

    protected function reportStatusLabel(string $status): string
    {
        return match ($status) {
            'APPROVED' => 'Disetujui',
            'PENDING'  => 'Menunggu',
            default    => 'Perlu Revisi',
        };
    }

    /** @return array{start: string, end: string} */
    protected function period(string $frequency, CarbonInterface $date): array
    {
        [$start, $end] = match ($frequency) {
            'WEEKLY'  => [$date->copy()->startOfWeek(), $date->copy()->endOfWeek()],
            'MONTHLY' => [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()],
            default   => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
        };

        return ['start' => $start->toDateString(), 'end' => $end->toDateString()];
    }

    /** Persentase pencapaian 0..120 untuk satu capaian. */
    protected function achievement(KpiSubmission $submission, PositionKpi $pk): float
    {
        if ($pk->kpiIndicator->assessment_type === 'YES_NO') {
            $raw = $submission->answer_yes_no ? 100.0 : 0.0;
        } else {
            $actual = (float) $submission->numeric_value;
            $target = (float) $pk->target;

            if ($pk->calculation_type === 'LOWER_IS_BETTER') {
                if ($actual <= 0) {
                    $raw = self::SCORE_CAP;   // tidak ada waste/biaya = hasil terbaik (hindari bagi nol)
                } else {
                    $raw = $target <= 0 ? 0.0 : ($target / $actual) * 100;
                }
            } else {
                $raw = $target <= 0 ? 100.0 : ($actual / $target) * 100;
            }
        }

        return round(min(max($raw, 0.0), self::SCORE_CAP), 2);
    }

    // ------------------------------------------------------------------
    // Review (pengganti KpiSubmissionPolicy) & filter capaian
    // ------------------------------------------------------------------

    /**
     * Boleh tidaknya $reviewer me-review satu capaian.
     * Admin: boleh semua. Store Manager: hanya capaian karyawan. Tidak boleh review capaian sendiri.
     */
    protected function canReview(User $reviewer, KpiSubmission $submission): bool
    {
        if ($submission->user_id === $reviewer->id) {
            return false;
        }

        if ($reviewer->hasRole('admin')) {
            return true;
        }

        if ($reviewer->hasRole('manager') && $reviewer->hasPosition('store manager')) {
            $submission->loadMissing('user.role');

            return (bool) $submission->user?->hasRole('karyawan');
        }

        return false;
    }

    /** Hentikan dengan 403 bila tidak berhak me-review. */
    protected function authorizeReview(User $reviewer, KpiSubmission $submission): void
    {
        abort_unless($this->canReview($reviewer, $submission), 403, 'This action is unauthorized.');
    }

    /** Aturan filter yang sama untuk daftar capaian/laporan/antrean. */
    protected function submissionFilterRules(): array
    {
        return [
            'user_id'     => ['nullable', 'integer', 'exists:users,id'],
            'role_id'     => ['nullable', 'integer', 'exists:roles,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'status'      => ['nullable', Rule::in(['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED'])],
            'date_from'   => ['nullable', 'date_format:Y-m-d'],
            'date_to'     => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** Bentuk JSON satu capaian untuk admin/manager (dulu AdminKpiSubmissionResource). */
    protected function adminSubmissionData(KpiSubmission $s): array
    {
        $data = [
            'id'            => $s->id,
            'report_date'   => $s->report_date?->toDateString(),
            'period_start'  => $s->period_start?->toDateString(),
            'period_end'    => $s->period_end?->toDateString(),
            'status'        => $this->statusOf($s->status),
            'answer_yes_no' => $s->answer_yes_no,
            'numeric_value' => $s->numeric_value !== null ? (float) $s->numeric_value : null,
            'submitted_at'  => $s->submitted_at,
        ];

        if ($s->relationLoaded('user')) {
            $data['user'] = $s->user ? [
                'id'       => $s->user->id,
                'name'     => $s->user->name,
                'role'     => $s->user->role?->name,
                'position' => $s->user->position?->name,
            ] : null;
        }

        if ($s->relationLoaded('positionKpi')) {
            $data['indicator'] = $s->positionKpi ? [
                'position_kpi_id'  => $s->positionKpi->id,
                'name'             => $s->positionKpi->kpiIndicator?->name,
                'unit'             => $s->positionKpi->kpiIndicator?->unit,
                'input_type'       => $s->positionKpi->kpiIndicator?->assessment_type,
                'calculation_type' => $s->positionKpi->calculation_type,
                'target'           => (float) $s->positionKpi->target,
                'weight'           => (float) $s->positionKpi->weight,
            ] : null;
        }

        if (array_key_exists('evidences_count', $s->getAttributes())) {
            $data['evidences_count'] = $s->evidences_count;
        }

        if ($s->relationLoaded('evidences')) {
            $data['evidences'] = $s->evidences->map(fn ($e) => [
                'id'        => $e->id,
                'file_name' => $e->file_name,
                'url'       => Storage::disk('public')->url($e->file_path),
            ])->values();
        }

        if ($s->relationLoaded('review')) {
            $r = $s->review;

            $data['review'] = $r ? [
                'status'        => $r->status,
                'review_type'   => $r->review_type,
                'reviewer'      => $r->relationLoaded('reviewer') ? $r->reviewer?->name : null,
                'reviewer_role' => $r->relationLoaded('reviewer') && $r->reviewer?->relationLoaded('role')
                    ? $r->reviewer->role?->name : null,
                'score'         => $r->score !== null ? (float) $r->score : null,
                'notes'         => $r->notes,
                'reviewed_at'   => $r->reviewed_at,
                'finalized_at'  => $r->finalized_at,
                'final_notes'   => $r->final_notes,
            ] : null;
        }

        return $data;
    }

    // ------------------------------------------------------------------
    // Bentuk JSON bersama
    // ------------------------------------------------------------------

    /** Bentuk JSON satu indikator. */
    protected function indicatorData(KpiIndicator $i): array
    {
        $data = [
            'id'               => $i->id,
            'name'             => $i->name,
            'description'      => $i->description,
            'unit'             => $i->unit,
            'assessment_type'  => $i->assessment_type,   // NUMERIC | YES_NO
            'calculation_type' => $i->calculation_type,  // HIGHER_IS_BETTER | LOWER_IS_BETTER
            'is_active'        => $i->is_active,
        ];

        // Hanya muncul kalau relasinya memang di-load (sama seperti whenLoaded)
        if ($i->relationLoaded('kpiCategory')) {
            $data['category'] = $i->kpiCategory ? $this->categoryData($i->kpiCategory) : null;
        }

        $data['requires_evidence'] = $i->requires_evidence;

        // Hanya muncul kalau pakai withCount('positionKpis') (sama seperti whenCounted)
        if (array_key_exists('position_kpis_count', $i->getAttributes())) {
            $data['position_kpis_count'] = $i->position_kpis_count;
        }

        $data['code'] = $i->code;

        return $data;
    }

    /** Bentuk JSON satu kategori. */
    protected function categoryData(KpiCategory $c): array
    {
        $data = [
            'id'          => $c->id,
            'name'        => $c->name,
            'description' => $c->description,
        ];

        if ($c->relationLoaded('kpiIndicators')) {
            $data['indicators'] = $c->kpiIndicators->map(fn ($i) => $this->indicatorData($i))->values();
        }

        return $data;
    }

    /** Daftar KPI aktif milik satu jabatan. */
    protected function positionKpis(int $positionId): Collection
    {
        return PositionKpi::query()
            ->with('kpiIndicator.kpiCategory')   // eager load: cegah N+1
            ->where('position_id', $positionId)
            ->where('is_active', true)
            ->orderByDesc('weight')
            ->get();
    }

    /**
     * KPI milik user + periode berjalan + submission di periode itu (jika ada).
     * Hasilnya punya atribut 'current_period' dan relasi 'currentSubmission'.
     */
    protected function kpisForUser(User $user, ?CarbonInterface $date = null): Collection
    {
        abort_if(! $user->position_id, 422, 'Akun belum memiliki posisi/jabatan.');

        $date ??= now();
        $items   = $this->positionKpis($user->position_id);

        if ($items->isEmpty()) {
            return $items;
        }

        $windows = $items->mapWithKeys(
            fn (PositionKpi $pk) => [$pk->id => $this->period($pk->frequency, $date)]
        );

        // 1 query untuk semua submission di rentang periode (bukan 1 query per KPI)
        $submissions = KpiSubmission::query()
            ->with('review')
            ->withCount('evidences')
            ->where('user_id', $user->id)
            ->whereIn('position_kpi_id', $items->pluck('id'))
            ->whereDate('period_start', '>=', $windows->min('start'))
            ->whereDate('period_end', '<=', $windows->max('end'))
            ->get()
            ->groupBy('position_kpi_id');

        return $items->each(function (PositionKpi $pk) use ($windows, $submissions): void {
            $window = $windows[$pk->id];

            $pk->setAttribute('current_period', $window);
            $pk->setRelation(
                'currentSubmission',
                ($submissions[$pk->id] ?? collect())
                    ->first(fn (KpiSubmission $s) => $s->period_start->toDateString() === $window['start'])
            );
        });
    }

    /** Bentuk JSON satu KPI jabatan (+ periode & submission bila ada). */
    protected function positionKpiData(PositionKpi $pk): array
    {
        $data = [
            'requires_evidence' => (bool) $pk->kpiIndicator?->requires_evidence,
            'id'                => $pk->id,
            'target'            => (float) $pk->target,
            'weight'            => (float) $pk->weight,
            'frequency'         => $pk->frequency,
            'calculation_type'  => $pk->calculation_type,
            'input_type'        => $pk->kpiIndicator?->assessment_type,
            'indicator'         => $pk->kpiIndicator ? $this->indicatorData($pk->kpiIndicator) : null,
        ];

        // hanya ada di /my-kpis
        if (isset($pk->current_period)) {
            $data['current_period'] = $pk->current_period;
        }

        if ($pk->relationLoaded('currentSubmission')) {
            $sub = $pk->currentSubmission;

            $data['submission'] = $sub ? [
                'is_editable'     => $this->isEditable($sub->status),
                'evidences_count' => $sub->evidences_count ?? 0,
                'id'              => $sub->id,
                'status'          => $this->statusOf($sub->status),
                'report_date'     => $sub->report_date?->toDateString(),
                'answer_yes_no'   => $sub->answer_yes_no,
                'numeric_value'   => $sub->numeric_value !== null ? (float) $sub->numeric_value : null,
                'submitted_at'    => $sub->submitted_at,
                'review'          => $sub->review ? [
                    'status'      => $sub->review->status,
                    'score'       => $sub->review->score !== null ? (float) $sub->review->score : null,
                    'notes'       => $sub->review->notes,
                    'reviewed_at' => $sub->review->reviewed_at,
                ] : null,
            ] : null;
        }

        return $data;
    }
}