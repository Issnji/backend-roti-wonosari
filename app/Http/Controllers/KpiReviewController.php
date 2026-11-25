<?php

namespace App\Http\Controllers;

use App\Models\KpiReview;
use App\Models\KpiSubmission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KpiReviewController extends Controller
{
    // ---------------- Alur utama: per laporan karyawan ----------------

    /** Daftar laporan yang menunggu keputusan. 1 baris = 1 karyawan + 1 tanggal. */
    public function reports(Request $request): JsonResponse
    {
        $f        = $request->validate($this->submissionFilterRules());
        $reviewer = $request->user();

        $page = KpiSubmission::query()
            ->select(
                'user_id',
                'report_date',
                DB::raw('COUNT(*) as total_indicators'),
                DB::raw('MAX(submitted_at) as submitted_at'),
            )
            ->where('status', 'SUBMITTED')
            ->where('user_id', '!=', $reviewer->id)
            ->when(! $reviewer->hasRole('admin'), fn ($q) => $q->whereHas(
                'user.role', fn ($r) => $r->whereRaw('LOWER(name) = ?', ['karyawan'])
            ))
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['position_id'] ?? null, fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('position_id', $v)))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->whereDate('report_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->whereDate('report_date', '<=', $v))
            ->groupBy('user_id', 'report_date')
            ->orderBy('report_date')
            ->orderBy('user_id')
            ->paginate((int) ($f['per_page'] ?? 15));

        $users = User::with(['role:id,name', 'position:id,name'])
            ->whereIn('id', $page->pluck('user_id'))
            ->get()
            ->keyBy('id');

        $page->getCollection()->transform(fn ($r) => [
            'user' => [
                'id'       => $r->user_id,
                'name'     => $users[$r->user_id]->name,
                'role'     => $users[$r->user_id]->role?->name,
                'position' => $users[$r->user_id]->position?->name,
            ],
            'report_date'      => $r->report_date->toDateString(),
            'total_indicators' => (int) $r->total_indicators,
            'submitted_at'     => $r->submitted_at,
        ]);

        return response()->json($page);
    }

    /** Isi laporan (semua indikator + bukti foto) sebelum diputuskan. */
    public function reportShow(Request $request, User $user, string $date): JsonResponse
    {
        $items = KpiSubmission::query()
            ->with([
                'user.role:id,name',
                'user.position:id,name',
                'positionKpi.kpiIndicator',
                'evidences',
            ])
            ->where('user_id', $user->id)
            ->whereDate('report_date', $date)
            ->where('status', 'SUBMITTED')
            ->orderBy('id')
            ->get();

        abort_if($items->isEmpty(), 404, 'Tidak ada laporan SUBMITTED pada tanggal tersebut.');

        foreach ($items as $s) {
            $this->authorizeReview($request->user(), $s);
        }

        return response()->json(['data' => $items->map(fn ($s) => $this->adminSubmissionData($s))]);
    }

    /** Approve / reject satu laporan utuh. */
    public function reportDecide(Request $request, User $user, string $date): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['APPROVED', 'REJECTED'])],
            'notes'    => ['nullable', 'string', 'max:255', Rule::requiredIf($request->input('decision') === 'REJECTED')],
        ], ['notes.required' => 'Alasan penolakan wajib diisi.']);

        $ids = KpiSubmission::query()
            ->where('user_id', $user->id)
            ->whereDate('report_date', $date)
            ->where('status', 'SUBMITTED')
            ->pluck('id')
            ->all();

        abort_if(empty($ids), 404, 'Tidak ada laporan SUBMITTED pada tanggal tersebut.');

        $saved = $this->decide($request->user(), $ids, $data['decision'], $data['notes'] ?? null);

        return response()->json([
            'data'           => $saved->map(fn ($s) => $this->adminSubmissionData($s)),
            'affected_count' => $saved->count(),
            'message'        => $data['decision'] === 'APPROVED'
                ? "Laporan {$user->name} tanggal {$date} disetujui ({$saved->count()} indikator)."
                : "Laporan {$user->name} tanggal {$date} ditolak ({$saved->count()} indikator).",
        ]);
    }

    // ---------------- Opsional: per indikator / bulk ----------------

    public function queue(Request $request)
    {
        $f        = $request->validate($this->submissionFilterRules());
        $reviewer = $request->user();

        $page = KpiSubmission::query()
            ->with([
                'user:id,name,role_id,position_id',
                'user.role:id,name',
                'user.position:id,name',
                'positionKpi:id,position_id,kpi_indicator_id,target,weight,calculation_type',
                'positionKpi.kpiIndicator:id,name,unit,assessment_type',
            ])
            ->withCount('evidences')
            ->where('status', 'SUBMITTED')
            ->where('user_id', '!=', $reviewer->id)
            ->when(! $reviewer->hasRole('admin'), fn ($q) => $q->whereHas(
                'user.role', fn ($r) => $r->whereRaw('LOWER(name) = ?', ['karyawan'])
            ))
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['position_id'] ?? null, fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('position_id', $v)))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->whereDate('report_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->whereDate('report_date', '<=', $v))
            ->orderBy('report_date')
            ->orderBy('id')
            ->paginate((int) ($f['per_page'] ?? 15));

        $page->through(fn ($s) => $this->adminSubmissionData($s));

        return JsonResource::collection($page);
    }

    public function show(Request $request, KpiSubmission $kpiSubmission): JsonResponse
    {
        $this->authorizeReview($request->user(), $kpiSubmission);

        $kpiSubmission->load([
            'user.role:id,name',
            'user.position:id,name',
            'positionKpi.kpiIndicator',
            'evidences',
            'review.reviewer:id,name',
        ]);

        return response()->json(['data' => $this->adminSubmissionData($kpiSubmission)]);
    }

    public function store(Request $request, KpiSubmission $kpiSubmission): JsonResponse
    {
        $data = $this->validateDecision($request, false);

        return $this->respond($request, [$kpiSubmission->id], $data);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $this->validateDecision($request, true);

        return $this->respond($request, $data['submission_ids'], $data);
    }

    // ---------------- fungsi bantu (private) ----------------

    private function validateDecision(Request $request, bool $isBulk): array
    {
        return $request->validate([
            'decision'         => ['required', Rule::in(['APPROVED', 'REJECTED'])],
            'notes'            => ['nullable', 'string', 'max:255', Rule::requiredIf($request->input('decision') === 'REJECTED')],
            'submission_ids'   => [Rule::requiredIf($isBulk), 'array', 'min:1', 'max:100'],
            'submission_ids.*' => ['integer', 'distinct', 'exists:kpi_submissions,id'],
        ], [
            'notes.required'          => 'Alasan penolakan wajib diisi.',
            'submission_ids.required' => 'Pilih minimal satu capaian.',
        ]);
    }

    private function respond(Request $request, array $ids, array $data): JsonResponse
    {
        $saved = $this->decide($request->user(), $ids, $data['decision'], $data['notes'] ?? null);

        return response()->json([
            'data'           => $saved->map(fn ($s) => $this->adminSubmissionData($s)),
            'affected_count' => $saved->count(),
            'message'        => $data['decision'] === 'APPROVED'
                ? "Seluruh laporan disetujui ({$saved->count()} indikator)."
                : "Laporan ditolak: {$saved->count()} indikator dikembalikan ke karyawan.",
        ]);
    }

    /**
     * Putuskan capaian yang dipilih; putusan otomatis berlaku untuk SELURUH laporan
     * (semua SUBMITTED milik user + report_date yang sama). Atomik: all-or-nothing.
     *
     * @param array<int, int|string> $ids
     * @param string $decision APPROVED | REJECTED
     */
    private function decide(User $reviewer, array $ids, string $decision, ?string $notes)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return DB::transaction(function () use ($reviewer, $ids, $decision, $notes) {
            // 1) Capaian yang dipilih (pemicu)
            $selected = KpiSubmission::query()
                ->with('positionKpi.kpiIndicator:id,name')
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            if ($selected->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'submission_ids' => 'Ada capaian yang tidak ditemukan.',
                ]);
            }

            foreach ($selected as $s) {
                if ($this->statusOf($s->status) !== 'SUBMITTED') {
                    throw ValidationException::withMessages([
                        'submission_ids' => "Capaian #{$s->id} berstatus {$this->statusOf($s->status)}; "
                            . 'hanya yang SUBMITTED bisa direview.',
                    ]);
                }
            }

            // 2) Perluas ke seluruh laporan: user + report_date yang sama
            $reports = $selected->unique(
                fn (KpiSubmission $s) => $s->user_id . '|' . $s->report_date->toDateString()
            );

            $batch = KpiSubmission::query()
                ->with(['user.role', 'user.position', 'positionKpi.kpiIndicator'])
                ->where('status', 'SUBMITTED')
                ->where(function ($q) use ($reports): void {
                    foreach ($reports as $r) {
                        $q->orWhere(fn ($w) => $w
                            ->where('user_id', $r->user_id)
                            ->whereDate('report_date', $r->report_date->toDateString()));
                    }
                })
                ->lockForUpdate()
                ->get();

            // 3) Reviewer harus berhak atas SEMUA capaian dalam laporan
            foreach ($batch as $submission) {
                $this->authorizeReview($reviewer, $submission);
            }

            // 4) Terapkan putusan ke seluruh laporan
            $approved   = $decision === 'APPROVED';
            $triggerIds = $selected->pluck('id');
            $firstName  = $selected->first()->positionKpi->kpiIndicator->name;

            foreach ($batch as $submission) {
                $isTrigger = $triggerIds->contains($submission->id);

                $reviewNotes = ($approved || $isTrigger)
                    ? $notes
                    : Str::limit(
                        "Ikut ditolak: indikator '{$firstName}' dalam laporan ini ditolak. Alasan: {$notes}",
                        255,
                        ''
                    );

                $submission->update(['status' => $decision]);

                KpiReview::updateOrCreate(
                    ['submission_id' => $submission->id],
                    [
                        'reviewer_id'  => $reviewer->id,
                        'review_type'  => $isTrigger ? 'MANUAL' : 'CASCADE',
                        'status'       => $decision,
                        'score'        => $approved
                            ? $this->achievement($submission, $submission->positionKpi)
                            : null,
                        'notes'        => $reviewNotes,
                        'reviewed_at'  => now(),
                        // keputusan manager baru selalu menghapus finalisasi Admin yang lama
                        'finalized_by' => null,
                        'finalized_at' => null,
                        'final_notes'  => null,
                    ]
                );
            }

            return $batch->load('review');
        });
    }
}
