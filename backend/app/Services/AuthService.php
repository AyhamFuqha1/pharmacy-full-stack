<?php

namespace App\Services;

use App\Jobs\SsendRegisterEmailJob;
use App\Models\User;
use DB;
use Hash;
use Illuminate\Support\Str;

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
            }

            $attendance = DB::table('attendance_logs')
                ->where('user_id', $user->id)
                ->where('active', true)
                ->first();

            if ($attendance) {
                return [
                    "message" => "User already logged in ",
                    "status" => 409
                ];
            }

            DB::table('attendance_logs')->insert([
                'user_id' => $user->id,
                'work_date' => date('Y-m-d'),
                'check_in_at' => now(),
                'active' => true,
            ]);

            $token = $user->createToken('api-token')->plainTextToken;

            return [
                "message" => "Login successful",
                "status" => 200,
                "token" => $token
            ];
        });
    }

    public function register($data, $admin)
    {
        return DB::transaction(function () use ($data, $admin) {
            $tempPassword = Str::random(16);
            $userId = DB::table('users')->insertGetId([
                'name' => $data->name,
                'email' => $data->email,
                'password' => Hash::make($tempPassword),
                'role' => $data->role,
                'force_change_password' => true,
            ]);

            DB::table('audit_logs')->insert([
                'user_id' => $admin->id,
                'table_name' => 'users',
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
            }

            $total_minutes = abs(now()->diffInMinutes($attendance->check_in_at));
            DB::table('attendance_logs')->where('id', $attendance->id)->update([
                'check_out_at' => now(),
                'total_minutes' => $total_minutes,
                'active' => false
            ]);

            $user->currentAccessToken()?->delete();

            return [
                'status' => 200,
                'message' => 'logout successful'
            ];
        });
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): bool
    {
        if (!Hash::check($currentPassword, $user->password)) {
            return false;
        }

        $user->password = Hash::make($newPassword);
        $user->force_change_password = false;
        $user->save();

        return true;
    }
}
