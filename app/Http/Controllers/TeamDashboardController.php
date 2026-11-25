<?php

namespace App\Http\Controllers;

use App\Models\KpiSubmission;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeamDashboardController extends Controller
{
    /** Urutan default daftar: yang perlu tindakan di atas. */
    private const PRIORITY = [
        'PENDING'      => 0,   // badge oranye "Approval"
        'REJECTED'     => 1,
        'NOT_REPORTED' => 2,   // badge abu "Belum Lapor"
        'APPROVED'     => 3,   // badge hijau "Disetujui"
    ];

    public function index(Request $request): JsonResource
    {
        abort_unless($request->user()?->canReviewTeam(), 403, 'This action is unauthorized.');

        $f = $request->validate([
            'date'   => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'status' => ['nullable', Rule::in(array_keys(self::PRIORITY))],
            'sort'   => ['nullable', Rule::in(['priority', 'name'])],
        ]);

        $date = $request->filled('date') ? Carbon::parse($request->input('date')) : now();

        return new JsonResource($this->build($request->user(), $date, $f));
    }

    /**
     * @param array{status?: string|null, sort?: string|null} $filters
     * @return array<string, mixed>
     */
    private function build(User $reviewer, CarbonInterface $date, array $filters): array
    {
        // Anggota tim = karyawan aktif (SM hanya me-review role karyawan)
        $members = User::query()
            ->with('position:id,name,description')
            ->where('is_active', true)
            ->where('id', '!=', $reviewer->id)
            ->whereHas('role', fn ($q) => $q->whereRaw('LOWER(name) = ?', ['karyawan']))
            ->get(['id', 'name', 'position_id']);

        // 1 query untuk semua anggota
        $statusMap = KpiSubmission::query()
            ->select(
                'user_id',
                'status',
                DB::raw('COUNT(*) as total'),
                DB::raw('MAX(submitted_at) as submitted_at'),
            )
            ->whereIn('user_id', $members->pluck('id'))
            ->whereDate('report_date', $date->toDateString())
            ->groupBy('user_id', 'status')
            ->get()
            ->groupBy('user_id');

        $rows = $members->map(fn (User $u) => $this->row($u, $statusMap->get($u->id), $date));

        $summary = $this->summary($rows);

        $rows = $rows->when($filters['status'] ?? null, fn (Collection $c, string $v) => $c->filter(
            fn (array $r) => $r['status'] === $v
        ));

        $name = fn (array $r): string => mb_strtolower($r['data']['user']['name']);

        $rows = ($filters['sort'] ?? 'priority') === 'name'
            ? $rows->sortBy($name)
            : $rows->sortBy(fn (array $r) => self::PRIORITY[$r['status']] . '|' . $name($r));

        return [
            'date'          => $date->toDateString(),
            'summary'       => $summary,
            'pending_count' => $summary['pending'],       // banner "3 Karyawan Menunggu Persetujuan"
            'members_count' => $summary['total'],         // "8 Anggota"
            'members'       => $rows->pluck('data')->values()->all(),
        ];
    }

    /** @return array{status: string, data: array<string, mixed>} */
    private function row(User $user, ?Collection $agg, CarbonInterface $date): array
    {
        $agg ??= collect();
        $by   = $agg->mapWithKeys(fn ($r) => [$this->statusOf($r->status) => (int) $r->total]);

        // Hanya DRAFT / tidak ada submission = belum lapor
        $status = match (true) {
            $by->get('REJECTED', 0) > 0  => 'REJECTED',
            $by->get('SUBMITTED', 0) > 0 => 'PENDING',
            $by->get('APPROVED', 0) > 0  => 'APPROVED',
            default                      => 'NOT_REPORTED',
        };

        $reported = $status !== 'NOT_REPORTED';

        return [
            'status' => $status,
            'data'   => [
                'user' => [
                    'id'              => $user->id,
                    'name'            => $user->name,
                    'position'        => $user->position?->name,
                    'position_detail' => $user->position?->description,  // "Kasir & Display"
                ],
                'report_date'      => $date->toDateString(),
                'status'           => $status,
                'indicators_count' => $reported
                    ? (int) $agg->reject(fn ($r) => $this->statusOf($r->status) === 'DRAFT')->sum('total')
                    : 0,
                'submitted_at'     => $reported ? $agg->max('submitted_at') : null,
                'can_review'       => $status === 'PENDING',
            ],
        ];
    }

    /** @param Collection<int, array{status: string, data: array}> $rows */
    private function summary(Collection $rows): array
    {
        $count = fn (string $s): int => $rows->filter(fn (array $r) => $r['status'] === $s)->count();

        $total    = $rows->count();
        $reported = $total - $count('NOT_REPORTED');

        return [
            'total'            => $total,
            'reported'         => $reported,                                   // "5 dari 8 sudah lapor"
            'reported_percent' => $total > 0 ? round($reported / $total * 100, 1) : 0.0,  // 62.5%
            'pending'          => $count('PENDING'),
            'approved'         => $count('APPROVED'),
            'rejected'         => $count('REJECTED'),
            'not_reported'     => $count('NOT_REPORTED'),
        ];
    }
}