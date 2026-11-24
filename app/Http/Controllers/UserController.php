<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $f = $request->validate([
            'search'      => ['nullable', 'string', 'max:100'],
            'role_id'     => ['nullable', 'integer'],
            'position_id' => ['nullable', 'integer'],
            'is_active'   => ['nullable', 'boolean'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with(['role:id,name', 'position:id,name'])
            ->when($f['search'] ?? null, fn ($q, $v) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")
            ))
            ->when($f['role_id'] ?? null, fn ($q, $v) => $q->where('role_id', $v))
            ->when($f['position_id'] ?? null, fn ($q, $v) => $q->where('position_id', $v))
            ->when(isset($f['is_active']), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($f['per_page'] ?? 15);

        $users->through(fn ($u) => $this->userData($u));

        return JsonResource::collection($users);
    }

    public function store(Request $request): JsonResponse
    {
        // non-admin wajib punya jabatan
        $roleName = Role::whereKey($request->input('role_id'))->value('name');
        $isAdmin  = $roleName !== null && strtolower(trim($roleName)) === 'admin';

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'email'       => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'    => ['required', 'string', 'min:8'],
            'role_id'     => ['required', 'integer', 'exists:roles,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id', Rule::requiredIf(! $isAdmin)],
            'is_active'   => ['sometimes', 'boolean'],
        ], [
            'email.unique'         => 'Email sudah terdaftar.',
            'position_id.required' => 'Jabatan wajib diisi untuk karyawan/manager.',
        ]);

        // password di-hash otomatis oleh cast 'hashed' di model User
        $user = User::create($data + ['is_active' => true])->load(['role', 'position']);

        return response()->json([
            'data'    => $this->userData($user),
            'message' => 'Akun berhasil dibuat.',
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json([
            'data' => $this->userData($user->load(['role:id,name', 'position:id,name'])),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'email'       => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password'    => ['sometimes', 'string', 'min:8'],
            'role_id'     => ['sometimes', 'integer', 'exists:roles,id'],
            'position_id' => ['sometimes', 'nullable', 'integer', 'exists:positions,id'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        return response()->json([
            'data'    => $this->userData($this->updateUser($user, $data)),
            'message' => 'Akun berhasil diperbarui.',
        ]);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $user = $this->updateUser($user, ['is_active' => (bool) $data['is_active']]);

        return response()->json([
            'data'    => $this->userData($user),
            'message' => 'Status akun diperbarui.',
        ]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:8']]);

        DB::transaction(function () use ($user, $data): void {
            $user->update(['password' => $data['password']]);
            $user->tokens()->delete();
        });

        return response()->json(['message' => 'Password berhasil direset. Semua sesi akun dikeluarkan.']);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($user->is($request->user()), 422, 'Anda tidak bisa menghapus akun sendiri.');
        $this->assertNotLastActiveAdmin($user);

        // FK submissions memakai cascadeOnDelete -> hapus akun = hapus riwayat capaian.
        abort_if(
            $user->kpiSubmissions()->exists(),
            409,
            'Akun memiliki riwayat capaian. Nonaktifkan akun, jangan dihapus.'
        );

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->json(['message' => 'Akun berhasil dihapus.']);
    }

    // ---------------- fungsi bantu (private) ----------------

    /** Bentuk JSON satu user (dulu UserResource). */
    private function userData(User $u): array
    {
        $data = [
            'id'        => $u->id,
            'name'      => $u->name,
            'email'     => $u->email,
            'is_active' => $u->is_active,
        ];

        if ($u->relationLoaded('role')) {
            $data['role'] = $u->role?->only(['id', 'name']);
        }
        if ($u->relationLoaded('position')) {
            $data['position'] = $u->position?->only(['id', 'name']);
        }

        $data['created_at'] = $u->created_at;

        return $data;
    }

    /** Dipakai update() dan updateStatus(). */
    private function updateUser(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $deactivating = array_key_exists('is_active', $data) && ! $data['is_active'];
            $roleChanging = isset($data['role_id']) && (int) $data['role_id'] !== (int) $user->role_id;

            if ($deactivating || $roleChanging) {
                $this->assertNotLastActiveAdmin($user);
            }

            $user->update($data);

            // Paksa login ulang bila akses/kredensial berubah
            if ($deactivating || $roleChanging || isset($data['password'])) {
                $user->tokens()->delete();
            }

            return $user->load(['role', 'position']);
        });
    }

    private function assertNotLastActiveAdmin(User $user): void
    {
        if (! $user->hasRole('admin')) {
            return;
        }

        $otherAdmins = User::query()
            ->whereKeyNot($user->id)
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->whereRaw('LOWER(name) = ?', ['admin']))
            ->exists();

        if (! $otherAdmins) {
            throw ValidationException::withMessages([
                'user' => 'Tidak bisa menonaktifkan/mengubah admin aktif terakhir.',
            ]);
        }
    }
}
