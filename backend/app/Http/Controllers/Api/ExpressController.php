<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenseRequest;
use App\Services\ExpressService;
use Illuminate\Http\Request;
use Throwable;
use App\Exceptions\AccountingPeriodException;

class ExpressController extends Controller
{
    public function __construct(private ExpressService $expenses) {}

    public function index(Request $request)
    {
        return $this->collection(\DB::table('expenses')->orderByDesc('id')->paginate(min(max((int) $request->input('per_page', 15), 1), 100)), 'Expenses retrieved.');
    }

    public function store(StoreExpenseRequest $request)
    {
        $data = $request->validated();
        try {
            $id = $this->expenses->store((object) $data, $request->user());
            return $this->success($this->expenses->show($id), 201, 'Expense posted.');
        } catch (Throwable $e) {
            if ($e instanceof AccountingPeriodException) throw $e;
            report($e); return $this->failure('Server error.', 500);
        }
    }

    public function show($id)
    {
        $expense = $this->expenses->show($id);
        return $expense ? $this->success($expense, 200, 'Expense retrieved.') : $this->failure('Expense not found.', 404);
    }

    public function destroy(Request $request, $id)
    {
        try {
            $result = $this->expenses->destroy($id, $request->user());
            if (!$result['status']) return $this->failure($result['message'], 409);
            return $this->success($this->expenses->show($id), 200, $result['message']);
        } catch (Throwable $e) {
            if ($e instanceof AccountingPeriodException) throw $e;
            report($e); return $this->failure('Server error.', 500);
        }
    }
}
