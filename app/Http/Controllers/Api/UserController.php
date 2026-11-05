<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    private function authorizeAction(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403);
    }

    private function canManageRole(User $actor, string $role): bool
    {
        return $actor->hasRole('super_admin')
            || !in_array($role, ['super_admin', 'admin'], true);
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'roles' => $user->getRoleNames(),
            'created_at' => $user->created_at,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'users.view');

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'exists:roles,name'],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with('roles')
            ->when($data['search'] ?? null, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($data['role'] ?? null, fn ($query, $role) =>
                $query->role($role))
            ->when($data['status'] ?? null, fn ($query, $status) =>
                $query->where('status', $status))
            ->latest()
            ->paginate($data['per_page'] ?? 15);

        // Include invitation state without exposing setup tokens.
        $userIds = $users->getCollection()->pluck('id');

        $invitations = \Illuminate\Support\Facades\DB::table('staff_invitations')
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $users->getCollection()->each(function ($user) use ($invitations) {
            $invitation = $invitations->get($user->id);

            $status = null;

            if ($invitation) {
                if ($invitation->used_at) {
                    $status = 'accepted';
                } elseif ($invitation->revoked_at) {
                    $status = 'revoked';
                } elseif (now()->greaterThanOrEqualTo($invitation->expires_at)) {
                    $status = 'expired';
                } else {
                    $status = 'pending';
                }
            }

            $user->setAttribute('invitation_status', $status);
        });

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'users.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', Rule::exists('roles', 'name')
                ->where('guard_name', 'web')],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        abort_unless(
            $this->canManageRole($request->user(), $data['role']),
            403
        );

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => 'active',
        ]);

        $user->assignRole($data['role']);

        return response()->json([
            'message' => 'User created successfully',
            'user' => $this->userData($user),
        ], 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeAction($request, 'users.view');

        return response()->json([
            'user' => $this->userData($user),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeAction($request, 'users.update');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'role' => [
                'sometimes',
                'required',
                Rule::exists('roles', 'name')->where('guard_name', 'web'),
            ],
        ]);

        // Pending staff invitation prevents email or role changes.
        // The invitation recipient and assigned access must remain
        // unchanged until the employee completes password setup.
        $hasUnacceptedInvitation = \Illuminate\Support\Facades\DB::table(
            'staff_invitations'
        )
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->exists();

        if ($hasUnacceptedInvitation) {
            $errors = [];

            if (
                array_key_exists('email', $data)
                && $data['email'] !== $user->email
            ) {
                $errors['email'] = [
                    'The email address cannot be changed while '
                    .'a staff invitation has not been accepted.',
                ];
            }

            if (
                array_key_exists('role', $data)
                && !$user->hasRole($data['role'])
            ) {
                $errors['role'] = [
                    'The staff role cannot be changed while '
                    .'an invitation has not been accepted.',
                ];
            }

            if ($errors !== []) {
                throw \Illuminate\Validation\ValidationException::withMessages(
                    $errors
                );
            }
        }


        if ($user->hasRole('super_admin')
            && !$request->user()->hasRole('super_admin')) {
            abort(403);
        }

        if (isset($data['role'])) {
            abort_if($request->user()->is($user), 403);

            abort_unless(
                $this->canManageRole($request->user(), $data['role']),
                403
            );

            if ($user->hasRole('super_admin')
                && $data['role'] !== 'super_admin') {
                $remaining = User::role('super_admin')
                    ->where('status', 'active')
                    ->whereKeyNot($user->id)
                    ->count();

                if ($user->isActive() && $remaining === 0) {
                    throw ValidationException::withMessages([
                        'role' => ['Cannot remove the last active Super Admin.'],
                    ]);
                }
            }
        }

        $user->update(collect($data)->only([
            'name',
            'email',
        ])->all());

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $this->userData($user->fresh()),
        ]);
    }

    public function changeStatus(
        Request $request,
        User $user
    ): JsonResponse {
        $this->authorizeAction($request, 'users.suspend');

        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);

        abort_if($request->user()->is($user), 403);

        if ($user->hasRole('super_admin')) {
            abort_unless($request->user()->hasRole('super_admin'), 403);

            if ($data['status'] === 'suspended' && $user->isActive()) {
                $remaining = User::role('super_admin')
                    ->where('status', 'active')
                    ->whereKeyNot($user->id)
                    ->count();

                if ($remaining === 0) {
                    throw ValidationException::withMessages([
                        'status' => ['Cannot suspend the last active Super Admin.'],
                    ]);
                }
            }
        }

        // Invited employees must complete password setup before activation.
        // Ordinary reactivation must not bypass a pending invitation.
        if ($data['status'] === 'active') {
            $hasUnacceptedInvitation = \Illuminate\Support\Facades\DB::table(
                'staff_invitations'
            )
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->exists();

            if ($hasUnacceptedInvitation) {
                throw ValidationException::withMessages([
                    'status' => [
                        'This staff member must complete the email invitation '
                        .'and create a password before activation.',
                    ],
                ]);
            }
        }

        $user->update(['status' => $data['status']]);

        if ($data['status'] === 'suspended') {
            $user->tokens()->delete();

            // Explicit suspension also revokes outstanding setup links.
            \Illuminate\Support\Facades\DB::table('staff_invitations')
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->update([
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'message' => 'User status updated successfully',
            'user' => $this->userData($user),
        ]);
    }

    public function roles(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'roles.view');

        return response()->json([
            'roles' => Role::where('guard_name', 'web')
                ->orderBy('name')
                ->pluck('name'),
        ]);
    }
}
