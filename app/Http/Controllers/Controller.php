<?php

namespace App\Http\Controllers;

use App\Models\KpiCategory;
use App\Models\KpiIndicator;
use App\Models\PositionKpi;
use Illuminate\Database\Eloquent\Collection;

/**
 * Controller induk. Semua controller lain "extends" ke sini,
 * jadi fungsi di bawah ini bisa dipakai bersama (tanpa folder Resource/Service).
 */
abstract class Controller
{
    /** Bentuk JSON satu indikator (sama persis dengan KpiIndicatorResource yang lama). */
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
            $data['category'] = $this->categoryData($i->kpiCategory);
        }

        $data['requires_evidence'] = $i->requires_evidence;

        // Hanya muncul kalau pakai withCount('positionKpis') (sama seperti whenCounted)
        if (array_key_exists('position_kpis_count', $i->getAttributes())) {
            $data['position_kpis_count'] = $i->position_kpis_count;
        }

        $data['code'] = $i->code;

        return $data;
    }

    /** Bentuk JSON satu kategori (sama dengan KpiCategoryResource yang lama). */
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

    /** Daftar KPI aktif milik satu jabatan (dulu KpiCatalogService::forPosition). */
    protected function positionKpis(int $positionId): Collection
    {
        return PositionKpi::query()
            ->with('kpiIndicator.kpiCategory')   // eager load: cegah N+1
            ->where('position_id', $positionId)
            ->where('is_active', true)
            ->orderByDesc('weight')
            ->get();
    }

    /** Bentuk JSON satu KPI jabatan (dulu PositionKpiResource). */
    protected function positionKpiData(PositionKpi $pk): array
    {
        return [
            'requires_evidence' => (bool) $pk->kpiIndicator?->requires_evidence,
            'id'                => $pk->id,
            'target'            => (float) $pk->target,
            'weight'            => (float) $pk->weight,
            'frequency'         => $pk->frequency,
            'calculation_type'  => $pk->calculation_type,
            'input_type'        => $pk->kpiIndicator?->assessment_type,
            'indicator'         => $this->indicatorData($pk->kpiIndicator),
        ];
    }
}
