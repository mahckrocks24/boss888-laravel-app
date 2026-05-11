<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Core\Auth\AuthService;
use App\Core\Auth\WorkspaceSwitchService;

class AuthController
{
    public function __construct(
        private AuthService $authService,
        private WorkspaceSwitchService $switchService,
    ) {}

    public function register(Request $request): JsonResponse
    {
        // 2026-05-11: signups blocked on production hostnames only.
        // staging.levelupgrowth.io and other hosts still accept registrations.
        if (in_array($request->getHost(), ['levelupgrowth.io', 'www.levelupgrowth.io'], true)) {
            abort(403, 'Signups are temporarily disabled.');
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
            ],
            'workspace_name' => 'nullable|string|max:255',
        ], [
            'password.regex' => 'Password must contain at least one uppercase letter and one number.',
            'password.confirmed' => 'Passwords do not match.',
        ]);

        $result = $this->authService->register($data);

        // T_NOTIF — notify platform admin (user_id=1) of new signup. Wrapped in try/catch
        // so a notification failure never blocks user registration.
        try {
            app(\App\Core\Notifications\NotificationService::class)->dispatch(
                type: \App\Core\Notifications\NotificationTypes::SYSTEM_USER_SIGNUP,
                userId: 1,
                title: 'New user registered',
                body: "{$data['email']} just signed up.",
                severity: 'info'
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('User signup notification failed', ['error' => $e->getMessage()]);
        }

        return response()->json($result, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $result = $this->authService->login(
            $request->input('email'),
            $request->input('password'),
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json($result);
    }

    public function refresh(Request $request): JsonResponse
    {
        $request->validate(['refresh_token' => 'required|string']);
        return response()->json($this->authService->refresh($request->input('refresh_token')));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->validate(['refresh_token' => 'required|string']);
        $this->authService->logout($request->input('refresh_token'));
        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->authService->me($request->user()));
    }

    public function switchWorkspace(Request $request): JsonResponse
    {
        $request->validate(['workspace_id' => 'required|integer']);
        $result = $this->switchService->switchWorkspace(
            $request->user(),
            $request->input('workspace_id'),
        );
        return response()->json($result);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        return response()->json(
            $this->authService->forgotPassword($request->input('email'))
        );
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        return response()->json(
            $this->authService->resetPassword(
                $request->input('token'),
                $request->input('password')
            )
        );
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255',
        ]);

        $user = $request->user();

        // Check email uniqueness if changing email
        if (isset($data['email']) && $data['email'] !== $user->email) {
            $exists = \App\Models\User::where('email', $data['email'])
                ->where('id', '!=', $user->id)
                ->exists();
            if ($exists) {
                return response()->json(['message' => 'Email already in use'], 422);
            }
        }

        $user->update($data);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (! \Illuminate\Support\Facades\Hash::check($request->input('current_password'), $user->password)) {
            return response()->json(['message' => 'Current password is incorrect'], 422);
        }

        $user->update([
            'password' => \Illuminate\Support\Facades\Hash::make($request->input('password')),
        ]);

        return response()->json(['message' => 'Password changed successfully']);
    }
}
