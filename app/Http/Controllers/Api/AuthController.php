<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Services\AuthService;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
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
      } else {
        return response()->json([
          'message' => $login['message'],
          'token' => $login['token'],
        ], 200);

      }
    } catch (Throwable $e) {
      return response()->json([
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
      ], 500);
    }
  }

  public function register(RegisterRequest $request)
  {
    try {
     $this->authorize('create', User::class);
      $admin = auth()->user();
      $register = $this->authService->register($request,$admin);
      if ($register) {
        return response()->json(true, 200);
      } else {
        return response()->json(false, 500);
      }
    }catch(AuthorizationException $e){
       return response()->json(
        ['message'=>$e->getMessage()],
        403
       );
    } 
    catch (Throwable $e) {
      return response()->json([
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
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
    } catch (Throwable $e) {
      return response()->json([
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
      ], 500);
    }
  }
}
