<?php

namespace App\Services;

use DB;

class ChequeService
{
    /**
     * Create a new class instance.
     */
    public function index()
    {
        return DB::table('checks')->get();
    }

    public function store($request, $referenceType, $referenceId, $direction)
    {
        DB::table('checks')->insertGetId([
            "check_number" => $request->check_number,
            "bank_name" => $request->bank_name,
            "amount" => $request->amount,
            "due_date" => $request->due_date,
            "status" => 0,
            "direction" => $direction,
            "reference_type" => $referenceType,
            "reference_id" => $referenceId
        ]);
        return true;
    }


    public function show($cheque)
    {
        return DB::table('checks')->where('id', $cheque)->first();
    }
    public function clear($id)
    {
        $check = DB::table('checks')->where('id', $id)->first();
        if (!$check) {
            return [
                "status" => false,
                "message" => "Cheque Not Found"
            ];
        }
        if ($check->status !== "pending") {
            return [
                "status" => false,
                "message" => "Cheque already processed"
            ];
        }
        DB::table('checks')->where('id', $id)->update(["status" => "cleared"]);
        return [
            "status" => true,
            "message" => "Cheque cleared successfully"
        ];
    }

    public function bounce($id)
    {
        $check = DB::table('checks')->where('id', $id)->first();
        if (!$check) {
            return [
                "status" => false,
                "message" => "Cheque Not Found"
            ];
        }
        if ($check->status !== "pending") {
            return [
                "status" => false,
                "message" => "Cheque already processed"
            ];
        }
        DB::table('checks')->where('id', $id)->update(["status" => "bounced"]);
        return [
            "status" => true,
            "message" => "Cheque bounced successfully"
        ];
    }
}
