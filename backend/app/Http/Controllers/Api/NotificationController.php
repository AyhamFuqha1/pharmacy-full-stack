<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;

class NotificationController extends Controller
{
    public function index(NotificationService $notifications)
    {
        return response()->json($notifications->forUser((int) auth()->id()));
    }
}