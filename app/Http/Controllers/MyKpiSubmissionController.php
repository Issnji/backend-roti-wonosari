<?php

namespace App\Http\Controllers;

use App\Models\KpiSubmission;
use App\Models\PositionKpi;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MyKpiSubmissionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'                    => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'submit'                  => ['sometimes', 'boolean'],
            'items'                   => ['required', 'array', 'min:1'],
            'items.*.position_kpi_id' => ['required', 'integer', 'distinct'],
            'items.*.answer_yes_no'   => ['nullable', 'boolean'],
            'items.*.numeric_value'   => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ], [
            'items.required'                   => 'Minimal satu indikator harus diisi.',
            'items.*.position_kpi_id.distinct' => 'Indikator tidak boleh duplikat dalam satu pengiriman.',
            'items.*.numeric_value.min'        => 'Nilai tidak boleh negatif.',
            'date.before_or_equal'             => 'Tanggal tidak boleh di masa depan.',
        ]);

        $user   = $request->user();
        $items  = $data['items'];
        $submit = $request->boolean('submit');
        $date   = $request->filled('date') ? Carbon::parse($request->input('date')) : now();

        abort_if(! $user->position_id, 422, 'Akun belum memiliki posisi/jabatan.');

        $positionKpis = PositionKpi::query()
            ->with('kpiIndicator:id,name,assessment_type,requires_evidence')
            ->where('position_id', $user->position_id)
            ->where('is_active', true)
            ->whereIn('id', collect($items)->pluck('position_kpi_id'))
            ->get()
            ->keyBy('id');

        $this->assertItemsValid($items, $positionKpis);

        $result = DB::transaction(function () use ($user, $items, $submit, $date, $positionKpis): array {
            $saved   = collect();
            $skipped = [];

            foreach ($items as $i => $item) {
                $pk      = $positionKpis[(int) $item['position_kpi_id']];
                $period  = $this->period($pk->frequency, $date);
                $isYesNo = $pk->kpiIndicator->assessment_type === 'YES_NO';

                $submission = KpiSubmission::query()
                    ->withCount('evidences')
                    ->where('user_id', $user->id)
                    ->where('position_kpi_id', $pk->id)
                    ->whereDate('period_start', $period['start'])
                    ->lockForUpdate()
                    ->first();

                if ($submission && ! $this->isEditable($submission->status)) {
                    // Indikator mingguan/bulanan yang periodenya sudah terkirim itu normal di hari berikutnya:
                    // lewati saja, jangan gagalkan seluruh laporan. Harian tetap ditolak (kirim ganda).
                    if ($pk->frequency !== 'DAILY') {
                        $skipped[] = [
                            'position_kpi_id' => $pk->id,
                            'name'            => $pk->kpiIndicator->name,
                            'frequency'       => $pk->frequency,
                            'status'          => $this->statusOf($submission->status),
                            'period_end'      => $period['end'],
                        ];
                        continue;
                    }

                    throw ValidationException::withMessages([
                        "items.$i.position_kpi_id" => "KPI '{$pk->kpiIndicator->name}' periode ini sudah "
                            . strtolower($this->statusOf($submission->status)) . ' dan tidak bisa diubah.',
                    ]);
                }

                if (
                    $submit
                    && $pk->kpiIndicator->requires_evidence
                    && (! $submission || $submission->evidences_count === 0)
                ) {
                    throw ValidationException::withMessages([
                        "items.$i.position_kpi_id" => "Unggah foto bukti untuk '{$pk->kpiIndicator->name}' sebelum mengirim.",
                    ]);
                }

                $submission ??= new KpiSubmission([
                    'user_id'         => $user->id,
                    'position_kpi_id' => $pk->id,
                    'period_start'    => $period['start'],
                    'period_end'      => $period['end'],
                ]);

                $submission->fill([
                    'answer_yes_no' => $isYesNo ? (bool) $item['answer_yes_no'] : null,
                    'numeric_value' => $isYesNo ? null : $item['numeric_value'],
                    'status'        => $submit ? 'SUBMITTED' : 'DRAFT',
                    'submitted_at'  => $submit ? now() : null,
                    'report_date'   => $submission->report_date?->toDateString() ?? $date->toDateString(),
                ])->save();

                $saved->push($submission);
            }

            if ($saved->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Semua indikator pada kiriman ini sudah terkirim untuk periodenya.',
                ]);
            }

            return ['saved' => $saved, 'skipped' => $skipped];
        });

        return response()->json([
            'data' => $result['saved']->map(fn ($s) => [
                'id'              => $s->id,
                'position_kpi_id' => $s->position_kpi_id,
                'period_start'    => $s->period_start?->toDateString(),
                'period_end'      => $s->period_end?->toDateString(),
                'status'          => $this->statusOf($s->status),
                'answer_yes_no'   => $s->answer_yes_no,
                'numeric_value'   => $s->numeric_value !== null ? (float) $s->numeric_value : null,
                'submitted_at'    => $s->submitted_at,
            ])->values(),
            'message' => $submit ? 'Capaian KPI berhasil dikirim.' : 'Draft capaian KPI tersimpan.',
            'skipped' => $result['skipped'],
        ]);
    }

    /** Pastikan tiap item valid untuk jabatan user & isinya lengkap. */
    private function assertItemsValid(array $items, $positionKpis): void
    {
        $errors = [];

        foreach ($items as $i => $item) {
            $pk = $positionKpis->get((int) $item['position_kpi_id']);

            if (! $pk) {
                $errors["items.$i.position_kpi_id"] = 'KPI tidak valid untuk jabatan Anda.';
                continue;
            }

            if ($pk->kpiIndicator->assessment_type === 'YES_NO') {
                if (! isset($item['answer_yes_no'])) {
                    $errors["items.$i.answer_yes_no"] = "Jawaban ya/tidak wajib diisi untuk '{$pk->kpiIndicator->name}'.";
                }
            } elseif (! isset($item['numeric_value'])) {
                $errors["items.$i.numeric_value"] = "Nilai wajib diisi untuk '{$pk->kpiIndicator->name}'.";
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
