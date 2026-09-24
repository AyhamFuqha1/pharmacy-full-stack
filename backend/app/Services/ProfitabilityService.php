<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProfitabilityService
{
    public function product(int $productId, ?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        abort_unless(DB::table('products')->where('id', $productId)->exists(), 404);
        $sales = DB::table('prod_sales as l')->join('sales_invoices as i','i.id','=','l.sales_invoice_id')
            ->join('product_batches as b','b.id','=','l.batch_id')->where('b.product_id',$productId)->whereNull('i.deleted_at')
            ->when($from,fn($q)=>$q->whereDate('i.date','>=',$from))->when($to,fn($q)=>$q->whereDate('i.date','<=',$to))->when($branchId,fn($q)=>$q->where('i.branch_id',$branchId));
        $returns = DB::table('invoice_returns as r')->join('sales_invoices as i','i.id','=','r.invoice_id')
            ->leftJoin('prod_sales as original','original.id','=','r.original_sale_line_id')
            ->where('r.product_id',$productId)->when($from,fn($q)=>$q->whereDate('r.created_at','>=',$from))->when($to,fn($q)=>$q->whereDate('r.created_at','<=',$to))->when($branchId,fn($q)=>$q->where('i.branch_id',$branchId));

        $sold=(float)(clone $sales)->sum('l.amount');
        $grossRevenue=(float)(clone $sales)->sum('l.final_total');
        $grossCogs=(float)(clone $sales)->selectRaw('COALESCE(SUM(l.amount*l.cost_price),0) total')->value('total');
        $returned=(float)(clone $returns)->sum('r.quantity');
        $returnRevenue=(float)(clone $returns)->selectRaw('COALESCE(SUM(r.quantity*COALESCE(r.original_returnable_unit_amount,r.price)),0) total')->value('total');
        $returnCredit=(float)(clone $returns)->selectRaw('COALESCE(SUM(r.quantity*COALESCE(r.customer_credit_unit_amount,r.price)),0) total')->value('total');
        $costExpression='COALESCE(original.cost_price,(SELECT fallback.cost_price FROM prod_sales fallback WHERE fallback.sales_invoice_id=r.invoice_id AND fallback.batch_id=r.batch_id ORDER BY fallback.id LIMIT 1),0)';
        $returnCogs=(float)(clone $returns)->selectRaw("COALESCE(SUM(r.quantity*$costExpression),0) total")->value('total');
        $nonRestockLoss=(float)(clone $returns)->where('r.return_to_inventory',false)->selectRaw("COALESCE(SUM(r.quantity*$costExpression),0) total")->value('total');
        $adjustmentLoss=(float)DB::table('stock_adjustments')->where('product_id',$productId)->where('quantity','<',0)
            ->when($from,fn($q)=>$q->whereDate('posted_at','>=',$from))->when($to,fn($q)=>$q->whereDate('posted_at','<=',$to))->when($branchId,fn($q)=>$q->where('branch_id',$branchId))
            ->selectRaw('COALESCE(SUM(ABS(quantity)*unit_cost),0) total')->value('total');
        $netRevenue=round($grossRevenue-$returnRevenue,2); $netCogs=round($grossCogs-$returnCogs,2); $grossProfit=round($netRevenue-$netCogs,2); $netQty=$sold-$returned;
        $current=DB::table('product_batches')->where('product_id',$productId)->whereNull('deleted_at')->orderByDesc('id')->first(['sale_price','purchase_price']);

        return ['product_id'=>$productId,'from'=>$from,'to'=>$to,'branch_id'=>$branchId,'sold_quantity'=>$sold,'sales_return_quantity'=>$returned,'net_sold_quantity'=>$netQty,
            'gross_sales_revenue'=>round($grossRevenue,2),'sales_return_revenue_reversal'=>round($returnRevenue,2),'net_sales_revenue'=>$netRevenue,'cogs'=>$netCogs,'gross_profit'=>$grossProfit,
            'gross_margin_percent'=>$netRevenue!=0?round($grossProfit/$netRevenue*100,2):0.0,'average_actual_sale_price'=>$netQty!=0?round($netRevenue/$netQty,4):0.0,'average_historical_cost'=>$netQty!=0?round($netCogs/$netQty,4):0.0,
            'return_deductions_other_income'=>round($returnRevenue-$returnCredit,2),'non_restocked_return_loss'=>round($nonRestockLoss,2),'inventory_adjustment_losses'=>round($adjustmentLoss,2),
            'theoretical_margin'=>['sale_price'=>(float)($current->sale_price??0),'reference_cost'=>(float)($current->purchase_price??0),'margin_amount'=>round((float)($current->sale_price??0)-(float)($current->purchase_price??0),2),'informational_only'=>true]];
    }

    public function products(Request $request): LengthAwarePaginator
    {
        $per=min(max($request->integer('per_page',25),1),100); $page=max($request->integer('page',1),1);
        $products=DB::table('products')->when($request->integer('product_id'),fn($q,$id)=>$q->where('id',$id))->orderBy('name')->get(['id','name','barcode']);
        $rows=$products->map(function($p)use($request){$x=$this->product($p->id,$request->from,$request->to,$request->integer('branch_id')?:null);return array_merge(['product_name'=>$p->name,'barcode'=>$p->barcode],$x);});
        $sort=$request->input('sort','gross_profit'); $direction=$request->input('direction','desc');
        $rows=$direction==='asc'?$rows->sortBy($sort,SORT_REGULAR):$rows->sortByDesc($sort,SORT_REGULAR);$rows=$rows->values();
        return new LengthAwarePaginator($rows->slice(($page-1)*$per,$per)->values(),$rows->count(),$per,$page,['path'=>$request->url(),'query'=>$request->query()]);
    }
}
