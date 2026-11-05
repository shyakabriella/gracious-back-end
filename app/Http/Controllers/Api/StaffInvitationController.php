<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffInvitationController extends Controller
{
    private function canManageRole(User $actor, string $role): bool
    {
        return $actor->hasRole('super_admin')
            || !in_array($role, ['super_admin', 'admin'], true);
    }

    private function inviteUrl(string $email, string $token): string
    {
        $base = rtrim(
            config('staff_invitations.frontend_url'),
            '/'
        );

        return $base.'/staff/setup-password?'.http_build_query([
            'email' => $email,
            'token' => $token,
        ]);
    }

    private function issueInvitation(User $user, User $actor): string
    {
        $token = Str::random(64);
        $minutes = (int) config(
            'staff_invitations.expires_minutes',
            60
        );

        DB::table('staff_invitations')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'created_by' => $actor->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes($minutes),
                'sent_at' => null,
                'used_at' => null,
                'revoked_at' => null,
                'updated_at' => now(),
            ]
        );

        return $token;
    }

    private function sendInvitation(User $user, string $token): bool
    {
        try {
            $user->notify(new StaffInvitationNotification(
                $this->inviteUrl($user->email, $token),
                $user->name
            ));

            DB::table('staff_invitations')
                ->where('user_id', $user->id)
                ->update([
                    'sent_at' => now(),
                    'updated_at' => now(),
                ]);

            return true;
        } catch (\Throwable $exception) {
            Log::error('Staff invitation delivery failed', [
                'user_id' => $user->id,
                'exception' => get_class($exception),
            ]);

            return false;
        }
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('users.create'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => [
                'required',
                'string',
                Rule::exists('roles', 'name')
                    ->where('guard_name', 'web'),
            ],
        ]);

        abort_unless(
            $this->canManageRole($request->user(), $data['role']),
            403
        );

        [$user, $token] = DB::transaction(function () use ($request, $data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make(Str::random(80)),
                'status' => 'suspended',
            ]);

            $user->assignRole($data['role']);

            $token = $this->issueInvitation(
                $user,
                $request->user()
            );

            return [$user, $token];
        });

        $sent = $this->sendInvitation($user, $token);

        return response()->json([
            'message' => $sent
                ? 'Staff account created. Invitation email sent.'
                : 'Staff account created, but email delivery failed. Please resend the invitation.',
            'email_sent' => $sent,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'roles' => $user->getRoleNames(),
                'invitation_status' => 'pending',
            ],
        ], 201);
    }

    public function resend(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('users.create'), 403);

        $role = $user->getRoleNames()->first();

        abort_unless(
            $role && $this->canManageRole($request->user(), $role),
            403
        );

        $token = DB::transaction(function () use ($user, $request) {
            $lockedUser = User::whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $invitation = DB::table('staff_invitations')
                ->where('user_id', $lockedUser->id)
                ->lockForUpdate()
                ->first();

            if (
                !$invitation ||
                $invitation->used_at ||
                $invitation->revoked_at ||
                $lockedUser->status !== 'suspended'
            ) {
                throw ValidationException::withMessages([
                    'invitation' => [
                        'This account cannot receive a new invitation.',
                    ],
                ]);
            }

            return $this->issueInvitation(
                $lockedUser,
                $request->user()
            );
        });

        $sent = $this->sendInvitation($user, $token);

        return response()->json([
            'message' => $sent
                ? 'A new invitation email was sent.'
                : 'Email delivery failed. Please retry.',
            'email_sent' => $sent,
        ], $sent ? 200 : 503);
    }

    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'min:12',
                'confirmed',
            ],
        ]);

        DB::transaction(function () use ($data) {
            $user = User::where('email', $data['email'])
                ->lockForUpdate()
                ->first();

            $invitation = $user
                ? DB::table('staff_invitations')
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first()
                : null;

            $valid = $user
                && $invitation
                && !$invitation->used_at
                && !$invitation->revoked_at
                && $user->status === 'suspended'
                && now()->lessThan($invitation->expires_at)
                && hash_equals(
                    $invitation->token_hash,
                    hash('sha256', $data['token'])
                );

            if (!$valid) {
                throw ValidationException::withMessages([
                    'token' => [
                        'This invitation is invalid or has expired.',
                    ],
                ]);
            }

            $user->update([
                'password' => $data['password'],
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            $user->tokens()->delete();

            DB::table('staff_invitations')
                ->where('user_id', $user->id)
                ->update([
                    'used_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return response()->json([
            'message' => 'Password created successfully. You can now sign in.',
        ]);
    }
}
