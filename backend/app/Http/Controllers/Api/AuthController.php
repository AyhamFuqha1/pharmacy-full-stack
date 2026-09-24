<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\SafeUserResource;
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
        return $this->failure($login['message'], $login['status']);
      }

      $user = User::where('email', $request->email)->first();

      return $this->success([
        'token' => $login['token'],
        'requires_password_change' => (bool) ($user?->force_change_password ?? false),
      ], 200, $login['message']);
    } catch (Throwable $e) {
      report($e);

      return $this->failure('Server error.', 500);
    }
  }

  public function register(RegisterRequest $request)
  {
    try {
      $this->authorize('create', User::class);
      $admin = auth()->user();
      $register = $this->authService->register($request, $admin);

      if ($register) {
        return $this->success((new SafeUserResource($register))->resolve(), 201, 'User created.');
      }

      return $this->failure('User creation failed.', 500);
    } catch (AuthorizationException $e) {
      return $this->failure('Forbidden.', 403);
    } catch (Throwable $e) {
      report($e);

      return $this->failure('Server error.', 500);
    }
  }

  public function logout(Request $request)
  {
    $user = $request->user();

    try {
      $logout = $this->authService->logout($user);
      if ($logout['status'] == 200) {
        return $this->success(null, 200, $logout['message']);
      }

      return $this->failure($logout['message'] ?? 'Unable to log out.', $logout['status'] ?? 500);
    } catch (Throwable $e) {
      report($e);

      return $this->failure('Server error.', 500);
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
      return $this->failure('Current password is incorrect.', 422, ['current_password' => ['Current password is incorrect.']]);
    }

    $user->password = Hash::make($validated['new_password']);
    $user->force_change_password = false;
    $user->save();

    return $this->success(null, 200, 'Password changed successfully.');
  }
}
