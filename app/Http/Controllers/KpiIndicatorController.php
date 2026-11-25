<?php

namespace App\Http\Controllers;

use App\Models\KpiIndicator;
use App\Models\PositionKpi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KpiIndicatorController extends Controller
{
    public function index(Request $request)
    {
        $f = $request->validate([
            'search'      => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'is_active'   => ['nullable', 'boolean'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = KpiIndicator::query()
            ->with('kpiCategory:id,name')
            ->withCount(['positionKpis' => fn ($q) => $q->where('is_active', true)])
            ->when($f['search'] ?? null, fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->when($f['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->when(isset($f['is_active']), fn ($q) => $q->where('is_active', (bool) $f['is_active']))
            ->orderBy('category_id')
            ->orderBy('name')
            ->paginate((int) ($f['per_page'] ?? 15));

        // ubah tiap item jadi array JSON, format paginasi (data/links/meta) tetap sama
        $page->through(fn ($i) => $this->indicatorData($i));

        return JsonResource::collection($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id'       => ['required', 'integer', 'exists:kpi_categories,id'],
            'name'              => ['required', 'string', 'max:255', 'unique:kpi_indicators,name'],
            'description'       => ['nullable', 'string', 'max:255'],
            'unit'              => ['nullable', 'string', 'max:50'],
            'assessment_type'   => ['required', Rule::in(['NUMERIC', 'YES_NO'])],
            'calculation_type'  => ['required', Rule::in(['HIGHER_IS_BETTER', 'LOWER_IS_BETTER'])],
            'requires_evidence' => ['sometimes', 'boolean'],
            'is_active'         => ['sometimes', 'boolean'],
            'code'              => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/', 'unique:kpi_indicators,code'],
        ], [
            'name.unique' => 'Nama indikator sudah ada.',
        ]);

        // FE mencocokkan indikator lewat code -> selalu terisi, unik
        $data['code']              ??= $this->makeCode($data['name']);
        $data['requires_evidence'] ??= false;
        $data['is_active']         ??= true;

        $indicator = KpiIndicator::create($data)->load('kpiCategory');

        return response()->json([
            'data'    => $this->indicatorData($indicator),
            'message' => 'Indikator berhasil ditambahkan.',
        ], 201);
    }

    public function show(KpiIndicator $kpiIndicator): JsonResponse
    {
        return response()->json([
            'data' => $this->indicatorData($kpiIndicator->load('kpiCategory')),
        ]);
    }

    public function update(Request $request, KpiIndicator $kpiIndicator): JsonResponse
    {
        $data = $request->validate([
            'category_id'       => ['sometimes', 'integer', 'exists:kpi_categories,id'],
            'name'              => ['sometimes', 'string', 'max:255', Rule::unique('kpi_indicators', 'name')->ignore($kpiIndicator->id)],
            'description'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'unit'              => ['sometimes', 'nullable', 'string', 'max:50'],
            'assessment_type'   => ['sometimes', Rule::in(['NUMERIC', 'YES_NO'])],
            'calculation_type'  => ['sometimes', Rule::in(['HIGHER_IS_BETTER', 'LOWER_IS_BETTER'])],
            'requires_evidence' => ['sometimes', 'boolean'],
            'is_active'         => ['sometimes', 'boolean'],
            'code'              => ['sometimes', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/', Rule::unique('kpi_indicators', 'code')->ignore($kpiIndicator->id)],
        ]);

        DB::transaction(function () use ($kpiIndicator, $data) {
            // Code dipakai FE sebagai kunci pemetaan -> tidak boleh berubah
            if (isset($data['code']) && $kpiIndicator->code !== null && $data['code'] !== $kpiIndicator->code) {
                throw ValidationException::withMessages(['code' => 'Kode indikator tidak bisa diubah.']);
            }

            if (
                isset($data['assessment_type'])
                && $data['assessment_type'] !== $kpiIndicator->assessment_type
                && $this->hasSubmissions($kpiIndicator)
            ) {
                throw ValidationException::withMessages([
                    'assessment_type' => 'Tipe penilaian tidak bisa diubah karena indikator sudah memiliki capaian. Buat indikator baru.',
                ]);
            }

            if (array_key_exists('is_active', $data) && ! $data['is_active'] && $kpiIndicator->is_active) {
                $this->assertNotAssigned($kpiIndicator);
            }

            $kpiIndicator->update($data);

            if ($kpiIndicator->wasChanged('calculation_type')) {
                PositionKpi::where('kpi_indicator_id', $kpiIndicator->id)
                    ->update(['calculation_type' => $kpiIndicator->calculation_type]);
            }

            // Ganti ke YES_NO: target jabatan harus 1 (YES_NO selalu bertarget 1)
            if ($kpiIndicator->wasChanged('assessment_type') && $kpiIndicator->assessment_type === 'YES_NO') {
                PositionKpi::where('kpi_indicator_id', $kpiIndicator->id)->update(['target' => 1]);
            }
        });

        return response()->json([
            'data'    => $this->indicatorData($kpiIndicator->load('kpiCategory')),
            'message' => 'Indikator berhasil diperbarui.',
        ]);
    }

    /** Hapus fisik bila belum pernah dipakai; jika sudah ada capaian -> nonaktifkan saja. */
    public function destroy(KpiIndicator $kpiIndicator): JsonResponse
    {
        $result = DB::transaction(function () use ($kpiIndicator): string {
            $this->assertNotAssigned($kpiIndicator);

            if ($this->hasSubmissions($kpiIndicator)) {
                $kpiIndicator->update(['is_active' => false]);

                return 'deactivated';
            }

            $kpiIndicator->delete(); // position_kpis nonaktif tanpa capaian ikut terhapus (cascade)

            return 'deleted';
        });

        return response()->json([
            'message' => $result === 'deleted'
                ? 'Indikator dihapus.'
                : 'Indikator sudah punya riwayat capaian, jadi dinonaktifkan (bukan dihapus).',
            'result'  => $result,
        ]);
    }

    // ---------------- fungsi bantu (private) ----------------

    private function hasSubmissions(KpiIndicator $indicator): bool
    {
        return $indicator->positionKpis()->whereHas('kpiSubmissions')->exists();
    }

    private function assertNotAssigned(KpiIndicator $indicator): void
    {
        $positions = $indicator->positionKpis()
            ->where('is_active', true)
            ->with('position:id,name')
            ->get()
            ->pluck('position.name');

        if ($positions->isNotEmpty()) {
            throw ValidationException::withMessages([
                'indicator' => 'Indikator masih dipakai oleh jabatan: ' . $positions->implode(', ')
                    . '. Lepas dulu dari jabatan tersebut.',
            ]);
        }
    }

    private function makeCode(string $name): string
    {
        $base = Str::limit(Str::upper(Str::slug($name, '_')), 40, '');
        $code = $base;

        for ($i = 2; KpiIndicator::where('code', $code)->exists(); $i++) {
            $code = "{$base}_{$i}";
        }

        return $code;
    }
}
