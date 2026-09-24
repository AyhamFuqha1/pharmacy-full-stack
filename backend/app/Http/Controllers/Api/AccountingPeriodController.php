<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AccountingPeriodException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountingPeriodRequest;
use App\Models\AccountingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingPeriodController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['status' => 'nullable|in:open,closed', 'year' => 'nullable|integer', 'month' => 'nullable|integer|min:0|max:12', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $periods = AccountingPeriod::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))->when($request->filled('year'), fn ($q) => $q->where('period_year', $request->year))
            ->when($request->filled('month'), fn ($q) => $q->where('period_month', $request->month))->when($request->filled('from'), fn ($q) => $q->whereDate('end_date', '>=', $request->from))->when($request->filled('to'), fn ($q) => $q->whereDate('start_date', '<=', $request->to))
            ->orderByDesc('start_date')
            ->paginate(min(max((int) $request->input('per_page', 15), 1), 100));

        return $this->collection($periods, 'Accounting periods retrieved.');
    }

    public function show(int $id)
    {
        return $this->success(AccountingPeriod::query()->findOrFail($id), 200, 'Accounting period retrieved.');
    }

    public function store(StoreAccountingPeriodRequest $request)
    {
        $data = $request->validated();
        $start = CarbonImmutable::parse($data['start_date'])->startOfDay();
        $end = CarbonImmutable::parse($data['end_date'])->startOfDay();

        $period = DB::transaction(function () use ($data, $start, $end) {
            $overlap = AccountingPeriod::query()
                ->whereDate('start_date', '<=', $end->toDateString())
                ->whereDate('end_date', '>=', $start->toDateString())
                ->lockForUpdate()
                ->exists();

            if ($overlap) {
                throw new AccountingPeriodException('The accounting period overlaps an existing period.');
            }

            $isFullYear = $start->isStartOfYear() && $end->isEndOfYear() && $start->year === $end->year;
            $periodMonth = $isFullYear ? 0 : $start->month;

            if (AccountingPeriod::query()->where('period_year', $start->year)->where('period_month', $periodMonth)->exists()) {
                throw new AccountingPeriodException('An accounting period already exists for the selected year and month.');
            }

            return AccountingPeriod::query()->create([
                'name' => $data['name'],
                'period_year' => $start->year,
                'period_month' => $periodMonth,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'status' => 'open',
            ]);
        });

        return $this->success($period, 201, 'Accounting period created.');
    }

    public function close(int $id)
    {
        $period = DB::transaction(function () use ($id) {
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($id);
            if ($period->status !== 'open') {
                throw new AccountingPeriodException('Only an open accounting period can be closed.');
            }

            $period->update(['status' => 'closed']);
            return $period->fresh();
        });

        return $this->success($period, 200, 'Accounting period closed.');
    }

    public function reopen(Request $request,int $id)
    {
        $data=$request->validate(['reason'=>'required|string|min:3|max:1000']);
        $period=DB::transaction(function()use($request,$id,$data){$period=AccountingPeriod::query()->lockForUpdate()->findOrFail($id);if($period->status!=='closed')throw new AccountingPeriodException('Only a closed accounting period can be reopened.');$old=$period->toArray();$period->update(['status'=>'open']);DB::table('audit_logs')->insert(['user_id'=>$request->user()->id,'table_name'=>'accounting_periods','model_name'=>AccountingPeriod::class,'record_id'=>$period->id,'action'=>'reopen','action_type'=>'accounting_period_reopen','old_data'=>json_encode($old),'new_data'=>json_encode(array_merge($period->fresh()->toArray(),['reason'=>$data['reason'],'reopened_by'=>$request->user()->id,'reopened_at'=>now()->toISOString()])),'old_values'=>json_encode(['status'=>'closed']),'new_values'=>json_encode(['status'=>'open','reason'=>$data['reason']]),'created_at'=>now()]);return$period->fresh();});
        return$this->success($period,200,'Accounting period reopened.');
    }
}
