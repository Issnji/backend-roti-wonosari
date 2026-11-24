<?php

namespace App\Http\Controllers;

use App\Models\KpiIndicator;
use App\Models\Position;
use App\Models\PositionKpi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PositionKpisController extends Controller
{
    /**
     * Timpa seluruh daftar KPI sebuah jabatan (tambah / ubah / kurangi sekaligus).
     * Total bobot wajib 100. Atomik lewat transaksi.
     */
    public function sync(Request $request, Position $position): JsonResponse
    {
        $data = $request->validate([
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.kpi_indicator_id' => ['required', 'integer', 'distinct', 'exists:kpi_indicators,id'],
            'items.*.target'           => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'items.*.weight'           => ['required', 'numeric', 'min:0.01', 'max:100'],
            'items.*.frequency'        => ['required', Rule::in(['DAILY', 'WEEKLY', 'MONTHLY'])],
        ], [
            'items.required'                    => 'Minimal satu indikator harus dipilih.',
            'items.*.kpi_indicator_id.distinct' => 'Indikator tidak boleh duplikat.',
        ]);

        $items = $data['items'];

        // Total bobot per jabatan wajib tepat 100
        $total = round((float) array_sum(array_column($items, 'weight')), 2);
        if ($total !== 100.0) {
            throw ValidationException::withMessages([
                'items' => "Total bobot harus 100 (saat ini {$total}).",
            ]);
        }

        $result = DB::transaction(function () use ($position, $items) {
            $indicators = KpiIndicator::query()
                ->whereIn('id', array_column($items, 'kpi_indicator_id'))
                ->get()
                ->keyBy('id');

            $inactive = $indicators->where('is_active', false)->pluck('name');
            if ($inactive->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Indikator nonaktif tidak bisa ditugaskan: ' . $inactive->implode(', '),
                ]);
            }

            $existing = PositionKpi::query()
                ->where('position_id', $position->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('kpi_indicator_id');

            foreach ($items as $i => $item) {
                $indicator = $indicators[(int) $item['kpi_indicator_id']];
                $pk        = $existing->get($indicator->id)
                    ?? new PositionKpi(['position_id' => $position->id, 'kpi_indicator_id' => $indicator->id]);

                // Ganti frekuensi saat ada capaian berjalan akan membuat periode tumpang tindih
                if ($pk->exists && $pk->frequency !== $item['frequency'] && $this->hasOpenSubmissions($pk)) {
                    throw ValidationException::withMessages([
                        "items.$i.frequency" => "Frekuensi '{$indicator->name}' tidak bisa diubah: masih ada capaian DRAFT/SUBMITTED.",
                    ]);
                }

                $pk->fill([
                    // YES_NO selalu bertarget 1 (ya = 100%, tidak = 0%)
                    'target'           => $indicator->assessment_type === 'YES_NO' ? 1 : $item['target'],
                    'weight'           => $item['weight'],
                    'frequency'        => $item['frequency'],
                    'calculation_type' => $indicator->calculation_type,
                    'is_active'        => true,
                ])->save();
            }

            // Indikator yang dikurangi: hapus jika belum punya capaian, kalau sudah -> nonaktifkan
            $keepIds = collect($items)->pluck('kpi_indicator_id')->map(fn ($v) => (int) $v);

            foreach ($existing as $pk) {
                if ($keepIds->contains($pk->kpi_indicator_id) || ! $pk->is_active) {
                    continue;
                }

                $pk->kpiSubmissions()->exists()
                    ? $pk->update(['is_active' => false])
                    : $pk->delete();
            }

            return $this->positionKpis($position->id);
        });

        return response()->json([
            'data'         => $result->map(fn ($pk) => $this->positionKpiData($pk)),
            'message'      => "KPI jabatan {$position->name} berhasil diperbarui.",
            'total_weight' => round((float) $result->sum('weight'), 2),
        ]);
    }

    private function hasOpenSubmissions(PositionKpi $pk): bool
    {
        return $pk->kpiSubmissions()
            ->whereIn('status', ['DRAFT', 'SUBMITTED'])
            ->exists();
    }
}
