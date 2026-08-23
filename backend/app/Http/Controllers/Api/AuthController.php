<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Throwable;

class AuthController extends Controller
{
  private AuthService $authService;
  use AuthorizesRequests;

  public function __construct(AuthService $auth)
  {
    $this->authService = $auth;
  }

  public function login(LoginRequest $request)
  {
    try {
      $login = $this->authService->login($request);
      if ($login['status'] !== 200) {
        return response()->json(
          ['message' => $login['message']],
          $login['status']
        );
      }

      $user = User::where('email', $request->email)->first();

      return response()->json([
        'message' => $login['message'],
        'token' => $login['token'],
        'requires_password_change' => (bool) ($user?->force_change_password ?? false),
      ], 200);
    } catch (Throwable $e) {
      report($e);

      return response()->json([
        'message' => 'Something went wrong.',
      ], 500);
    }
  }

  public function register(RegisterRequest $request)
  {
    try {
      $this->authorize('create', User::class);
      $admin = auth()->user();
      $register = $this->authService->register($request, $admin);

      if ($register) {
        return response()->json(['message' => 'User created successfully.'], 200);
      }

      return response()->json(['message' => 'User creation failed.'], 500);
    } catch (AuthorizationException $e) {
      return response()->json(
        ['message' => 'You are not authorized to create users.'],
        403
      );
    } catch (Throwable $e) {
      report($e);

      return response()->json([
        'message' => 'Something went wrong.',
      ], 500);
    }
  }

  public function logout(Request $request)
  {
    $user = $request->user();

    try {
      $logout = $this->authService->logout($user);
      if ($logout['status'] == 200) {
        return response()->json($logout['message'], 200);
      }

      return response()->json(['message' => $logout['message'] ?? 'Unable to log out.'], $logout['status'] ?? 500);
    } catch (Throwable $e) {
      report($e);

      return response()->json([
        'message' => 'Something went wrong.',
      ], 500);
    }
  }

  public function changePassword(Request $request)
  {
    $validated = $request->validate([
      'current_password' => ['required', 'string'],
      'new_password' => ['required', 'string', 'min:8', 'confirmed'],
    ]);

    $user = $request->user();

    if (! $user || ! Hash::check($validated['current_password'], $user->password)) {
      return response()->json(['message' => 'Current password is incorrect.'], 422);
    }

    $user->password = Hash::make($validated['new_password']);
    $user->force_change_password = false;
    $user->save();

    return response()->json(['message' => 'Password changed successfully.'], 200);
  }
}
