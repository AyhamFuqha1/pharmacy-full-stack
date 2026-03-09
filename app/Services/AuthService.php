<?php

namespace App\Services;

use App\Jobs\SsendRegisterEmailJob;
use App\Models\User;
use DB;
use Hash;

class AuthService
{

    public function login($data)
    {
        return DB::transaction(function () use ($data) {
            $user = User::where('email', $data->email)->first();
            if (!$user || !Hash::check($data->password, $user->password)) {
                return [
                    "message" => "Email or password is incorrect",
                    'status' => 401
                ];
            } else {
                $attendance = DB::table('attendance_logs')->where('user_id', $user->id)->where("active", true)->first();
                if ($attendance) {
                    return [
                        "message" => "User already logged in ",
                        "status" => 409
                    ];
                } else {
                    DB::table('attendance_logs')->insert(['user_id' => $user->id, 'work_date' => date('Y-m-d'), 'check_in_at' => now(), 'active' => true]);
                    $token = $user->createToken('api-token')->plainTextToken;
                    return [
                        "message" => "Login successful",
                        "status" => 200,
                        "token" => $token
                    ];
                }
            }
        });
    }

    public function register($data, $admin)
    {
        return DB::transaction(function () use ($data, $admin) {
           // $tempPassword = random_int(10000000, 99999999);
            $tempPassword = 123456;
            $userId = DB::table('users')->insertGetId(['name' => $data->name, 'email' => $data->email, 'password' => Hash::make($tempPassword), 'role' => $data->role, 'force_change_password' => true]);
            DB::table('audit_logs')->insert([
                'user_id' => $admin->id,
                'table_name' => "users",
                'record_id' => $userId,
                'action' => 'create',
            ]);
            SsendRegisterEmailJob::dispatch($data->email, $data->name, $tempPassword);
            return true;
        });
    }

    public function logout($user)
    {
        return DB::transaction(function () use ($user) {
            $attendance = DB::table('attendance_logs')->where('user_id', $user->id)->where('active', true)->first();
            if (!$attendance) {
                return [
                    'status' => 409,
                    'message' => 'User Already logged out'
                ];
            } else {
                $total_minutes = abs(now()->diffInMinutes($attendance->check_in_at));
                DB::table('attendance_logs')->where('id', $attendance->id)->update([
                    'check_out_at' => now(),
                    'total_minutes' => $total_minutes,
                    'active' => false
                ]);
                $user->currentAccessToken()->delete();
                return [
                    'status' => 200,
                    'message' => 'logout successful'
                ];
            }
        });
    }
}
