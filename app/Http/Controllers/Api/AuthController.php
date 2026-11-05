<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()
                ->pluck('name'),
        ];
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where(
            'email',
            $data['email']
        )->first();

        if (!$user || !Hash::check(
            $data['password'],
            $user->password
        )) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        if (!$user->isActive()) {
            return response()->json([
                'message' => 'Your account is suspended.',
            ], 403);
        }

        $token = $user->createToken(
            'gracious-api'
        )->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => $this->userData($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userData($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()
            ->currentAccessToken()
            ->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    public function changePassword(
        Request $request
    ): JsonResponse {
        $data = $request->validate([
            'current_password' => [
                'required',
                'current_password',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        $user = $request->user();

        $user->update([
            'password' => $data['password'],
        ]);

        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password changed. Please log in again.',
        ]);
    }
}
