<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgingService
{
    public function report(Request $request, string $side): array
    {
        $v=$request->validate(['as_of'=>'nullable|date','branch_id'=>'nullable|exists:branches,id','party_id'=>'nullable|integer','minimum_balance'=>'nullable|numeric|min:0','outstanding_only'=>'nullable|boolean','detail'=>'nullable|boolean','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:1|max:100']);
        $as=$v['as_of']??now()->toDateString(); $per=$v['per_page']??25; $page=$v['page']??1;
        $partyTable=$side==='customer'?'customers':'providers'; $invoiceTable=$side==='customer'?'sales_invoices':'purchase_invoices';
        $partyColumn=$side==='customer'?'customer_id':'provider_id'; $invoiceType=$side==='customer'?'sale':'purchase';
        $parties=DB::table($partyTable)->when($v['party_id']??null,fn($q,$x)=>$q->where('id',$x))->orderBy('id')->get();
        $rows=[];
        foreach($parties as $party){
            $q=DB::table($invoiceTable)->where($partyColumn,$party->id)->whereDate('date','<=',$as)->when($v['branch_id']??null,fn($q,$x)=>$q->where('branch_id',$x));
            if($side==='customer')$q->whereNull('deleted_at');else$q->where('status','posted');
            $docs=$q->orderBy('date')->orderBy('id')->get(); $details=[];$buckets=['current_not_due'=>0.0,'0_30'=>0.0,'31_60'=>0.0,'61_90'=>0.0,'over_90'=>0.0];$hasAlloc=false;
            foreach($docs as$d){
                $original=(float)($side==='customer'?$d->total_price:((float)($d->total_after_discount??0)>0?$d->total_after_discount:$d->amount));
                $settled=(float)DB::table('settlement_allocations')->where(['invoice_type'=>$invoiceType,'invoice_id'=>$d->id])->whereDate('created_at','<=',$as)->sum('amount');if($settled>0)$hasAlloc=true;
                $sourcePaid=$side==='customer'?(float)($d->paid_amount??0):0.0;
                $returns=$side==='customer'?(float)DB::table('invoice_returns')->where('invoice_id',$d->id)->whereDate('created_at','<=',$as)->selectRaw('COALESCE(SUM(quantity * COALESCE(customer_credit_unit_amount, price)), 0) as total')->value('total'):(float)DB::table('purch_returns')->where('invoice_id',$d->id)->whereDate('created_at','<=',$as)->sum('line_total_return');
                $out=max(0,round($original-$settled-$sourcePaid-$returns,2));$due=$d->due_date??$d->date;$days=max(0,now()->parse($due)->diffInDays(now()->parse($as),false));
                $bucket=now()->parse($due)->gt(now()->parse($as))?'current_not_due':($days<=30?'0_30':($days<=60?'31_60':($days<=90?'61_90':'over_90')));$buckets[$bucket]+=$out;
                if($out>0||!($v['outstanding_only']??true))$details[]=['invoice_id'=>$d->id,'invoice_number'=>$d->document_number??('Legacy '.($side==='customer'?'sales':'purchase').' invoice (no document number)'),'invoice_date'=>$d->date,'due_date'=>$due,'original_amount'=>round($original,2),'settled_amount'=>round($settled+$sourcePaid+$returns,2),'outstanding_amount'=>$out,'age_days'=>$days,'bucket'=>$bucket];
            }
            $total=round(array_sum($buckets),2);if($total<(float)($v['minimum_balance']??0)||(($v['outstanding_only']??false)&&$total<=0))continue;$rows[]=array_merge((array)$party,['total_outstanding'=>$total,'buckets'=>array_map(fn($x)=>round($x,2),$buckets),'aging_basis'=>$hasAlloc?'invoice_allocation':'legacy_pooled_allocation','invoices'=>($v['detail']??false)?$details:[]]);
        }
        $total=count($rows);$data=array_slice($rows,($page-1)*$per,$per);return ['data'=>$data,'meta'=>['current_page'=>$page,'per_page'=>$per,'total'=>$total,'last_page'=>max(1,(int)ceil($total/$per))],'as_of'=>$as,'side'=>$side];
    }
}
