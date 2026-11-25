<?php

namespace App\Http\Controllers;

use App\Models\KpiReview;
use App\Models\KpiSubmission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class KpiFinalReviewController extends Controller
{
    /** Laporan yang sudah di-approve manager, menunggu keputusan akhir Admin. 1 baris = 1 karyawan + 1 tanggal. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate($this->submissionFilterRules());

        $page = KpiSubmission::query()
            ->select(
                'user_id',
                'report_date',
                DB::raw('COUNT(*) as total_indicators'),
                DB::raw('MAX(submitted_at) as submitted_at'),
            )
            ->where(fn ($q) => $this->scopePending($q))
            ->where('user_id', '!=', $request->user()->id)
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

    public function show(User $user, string $date): JsonResponse
    {
        $items = $this->pending($user, $date)
            ->with([
                'user.role:id,name',
                'user.position:id,name',
                'positionKpi.kpiIndicator',
                'evidences',
                'review.reviewer:id,name',
            ])
            ->orderBy('id')
            ->get();

        abort_if($items->isEmpty(), 404, 'Tidak ada laporan yang menunggu persetujuan akhir pada tanggal tersebut.');

        return response()->json(['data' => $items->map(fn ($s) => $this->adminSubmissionData($s))]);
    }

    public function decide(Request $request, User $user, string $date): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['APPROVED', 'REJECTED'])],
            'notes'    => ['nullable', 'string', 'max:255', Rule::requiredIf($request->input('decision') === 'REJECTED')],
        ], ['notes.required' => 'Alasan penolakan wajib diisi.']);

        $admin    = $request->user();
        $decision = $data['decision'];
        $notes    = $data['notes'] ?? null;

        abort_unless($admin->hasRole('admin'), 403, 'Anda tidak memiliki akses.');
        abort_if($user->is($admin), 422, 'Tidak bisa memutuskan laporan sendiri.');

        $saved = DB::transaction(function () use ($admin, $user, $date, $decision, $notes) {
            $batch = $this->pending($user, $date)
                ->with(['review', 'positionKpi.kpiIndicator'])
                ->lockForUpdate()
                ->get();

            abort_if($batch->isEmpty(), 404, 'Tidak ada laporan yang menunggu persetujuan akhir pada tanggal tersebut.');

            foreach ($batch as $submission) {
                if ($decision === 'APPROVED') {
                    // Laporan Store Manager (belum ada review Manager): Admin sekaligus reviewer & penyetuju akhir
                    if ($this->statusOf($submission->status) === 'SUBMITTED') {
                        $submission->update(['status' => 'APPROVED']);
                        KpiReview::updateOrCreate(['submission_id' => $submission->id], [
                            'reviewer_id' => $admin->id,
                            'review_type' => 'MANUAL',
                            'status'      => 'APPROVED',
                            'score'       => $this->achievement($submission, $submission->positionKpi),
                            'notes'       => $notes,
                            'reviewed_at' => now(),
                        ]);
                        $submission->load('review');
                    }

                    $submission->review->update([
                        'finalized_by' => $admin->id,
                        'finalized_at' => now(),
                        'final_notes'  => $notes,
                    ]);

                    continue;
                }

                // Ditolak Admin: kembali ke karyawan sebagai revisi (semua indikator laporan itu)
                $submission->update(['status' => 'REJECTED']);
                KpiReview::updateOrCreate(['submission_id' => $submission->id], [
                    'reviewer_id'  => $admin->id,
                    'review_type'  => 'MANUAL',
                    'status'       => 'REJECTED',
                    'score'        => null,
                    'notes'        => $notes,
                    'reviewed_at'  => now(),
                    'finalized_by' => null,
                    'finalized_at' => null,
                    'final_notes'  => null,
                ]);
            }

            return $batch->load(['user.role', 'user.position', 'evidences', 'review']);
        });

        return response()->json([
            'data'           => $saved->map(fn ($s) => $this->adminSubmissionData($s)),
            'affected_count' => $saved->count(),
            'message'        => $decision === 'APPROVED'
                ? "Laporan {$user->name} tanggal {$date} disetujui final."
                : "Laporan {$user->name} tanggal {$date} dikembalikan ke karyawan.",
        ]);
    }

    /** Capaian APPROVED milik karyawan belum difinalisasi, atau SUBMITTED milik Manager. */
    private function pending(User $employee, string $date)
    {
        return KpiSubmission::query()
            ->where('user_id', $employee->id)
            ->whereDate('report_date', $date)
            ->where(fn ($q) => $this->scopePending($q));
    }

    /**
     * Menunggu keputusan akhir Admin:
     *  - capaian karyawan yang sudah di-approve Manager tetapi belum difinalisasi, atau
     *  - capaian Manager (Store Manager) yang sudah dikirim — tidak ada atasan lain selain Admin.
     */
    private function scopePending($query): void
    {
        $query->where(fn ($q) => $q
            ->where('status', 'APPROVED')
            ->whereHas('review', fn ($r) => $r->whereNull('finalized_at')))
            ->orWhere(fn ($q) => $q
                ->where('status', 'SUBMITTED')
                ->whereHas('user.role', fn ($r) => $r->whereRaw('LOWER(name) = ?', ['manager'])));
    }
}
