<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Clinician registration.
     * The role is always "clinician" and is never read from the request,
     * so nobody can register themselves as an admin.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'], // hashed by the User model's "hashed" cast
            'role' => 'clinician',
        ]);

        return response()->json([
            'user' => $user,
            'token' => $user->createToken($data['device_name'] ?? 'dashboard')->plainTextToken,
        ], 201);
    }

    /** Issue a Sanctum token for valid credentials. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        return response()->json([
            'user' => $user,
            'token' => $user->createToken($data['device_name'] ?? 'dashboard')->plainTextToken,
        ]);
    }

    /** Revoke the token used for this request. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /** The currently authenticated clinician. */
    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }
}
