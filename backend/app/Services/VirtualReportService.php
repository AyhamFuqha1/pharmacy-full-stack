<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class VirtualReportService
{
    public function execute(string$key,array$filters,$user,bool$all=false):array
    {
        $r=Request::create('/','GET',array_merge($filters,$all?['per_page'=>100]:[]));$r->setUserResolver(fn()=>$user);
        return match($key){
            'customer_statement'=>$this->statement('customer',(int)($filters['customer_id']??0),$filters),
            'supplier_statement'=>$this->statement('supplier',(int)($filters['provider_id']??0),$filters),
            'product_statement'=>$this->productStatement($r,(int)($filters['product_id']??0),$filters['batch_id']??null,$all),
            'batch_statement'=>$this->batchStatement($r,(int)($filters['product_id']??0),(int)($filters['batch_id']??0),$all),
            'ar_aging'=>$this->aging($r,'customer',$all),
            'ap_aging'=>$this->aging($r,'supplier',$all),
            'controlled_medicine_ledger'=>app(ErpAnalyticsService::class)->controlled($r,$all),
            'recall_traceability'=>$this->recall($r,(int)($filters['recall_id']??0)),
        };
    }

    private function statement(string$side,int$id,array$f):array
    {
        abort_if($id<=0,422,($side==='customer'?'customer_id':'provider_id').' is required.');$s=app(AccountingReportService::class)->{$side==='customer'?'customerStatement':'supplierStatement'}($id,$f['from']??null,$f['to']??null);return['rows'=>$s['lines'],'meta'=>['current_page'=>1,'per_page'=>count($s['lines']),'total'=>count($s['lines']),'last_page'=>1],'summary'=>collect($s)->except('lines')->all()];
    }
    private function productStatement(Request$r,int$product,$batch,bool$all):array
    {
        abort_if($product<=0,422,'product_id is required.');if($batch)$r->merge(['batch_id'=>$batch]);$page=app(ProductReportingService::class)->stockLedger($product,$r);$rows=collect($page->items());if($all){for($n=2;$n<=$page->lastPage();$n++){$r->merge(['page'=>$n]);$rows=$rows->concat(app(ProductReportingService::class)->stockLedger($product,$r)->items());}}return['rows'=>$rows,'meta'=>['current_page'=>$all?1:$page->currentPage(),'per_page'=>$all?$rows->count():$page->perPage(),'total'=>$page->total(),'last_page'=>$all?1:$page->lastPage()],'summary'=>app(ProductReportingService::class)->productReport($product,$r)];
    }
    private function batchStatement(Request$r,int$product,int$batch,bool$all):array
    {
        abort_if($batch<=0,422,'batch_id is required.');
        if($product<=0)$product=(int)\Illuminate\Support\Facades\DB::table('product_batches')->where('id',$batch)->value('product_id');
        abort_if($product<=0,422,'The selected batch is unavailable.');
        return $this->productStatement($r,$product,$batch,$all);
    }
    private function aging(Request$r,string$side,bool$all):array
    {
        $r->merge(['detail'=>true]);
        if($all)$r->merge(['per_page'=>100,'page'=>1]);
        $x=app(AgingService::class)->report($r,$side);
        $parties=collect($x['data']);
        if($all){
            for($page=2;$page<=($x['meta']['last_page']??1);$page++){
                $r->merge(['page'=>$page]);
                $parties=$parties->concat(app(AgingService::class)->report($r,$side)['data']);
            }
        }
        $bucketTotals=['current_not_due'=>0.0,'0_30'=>0.0,'31_60'=>0.0,'61_90'=>0.0,'over_90'=>0.0];
        foreach($parties as $party){
            foreach($bucketTotals as $bucket=>$amount)$bucketTotals[$bucket]+=(float)data_get($party,"buckets.$bucket",0);
        }
        $rows=$parties->flatMap(fn($party)=>collect(data_get($party,'invoices',[]))->map(fn($invoice)=>array_merge([
            'party_id'=>data_get($party,'id'),
            'party_name'=>data_get($party,'name'),
            'aging_basis'=>data_get($party,'aging_basis'),
        ],$invoice)))->values();
        return[
            'rows'=>$rows,
            'meta'=>$all?['current_page'=>1,'per_page'=>$rows->count(),'total'=>$rows->count(),'last_page'=>1]:$x['meta'],
            'summary'=>array_merge(['as_of'=>$x['as_of'],'side'=>$side,'total_outstanding'=>round((float)$parties->sum(fn($party)=>data_get($party,'total_outstanding',0)),2)],array_map(fn($amount)=>round($amount,2),$bucketTotals)),
        ];
    }
    private function recall(Request$r,int$id):array
    {
        abort_if($id<=0,422,'recall_id is required.');$x=app(ErpAnalyticsService::class)->recall($r,$id);$rows=collect($x['source_purchases'])->map(fn($v)=>array_merge(['movement_group'=>'purchase'],(array)$v))->concat(collect($x['sales_documents'])->map(fn($v)=>array_merge(['movement_group'=>'sale'],(array)$v)))->concat(collect($x['prescription_dispensing'])->map(fn($v)=>array_merge(['movement_group'=>'prescription'],(array)$v)))->values();return['rows'=>$rows,'meta'=>['current_page'=>1,'per_page'=>$rows->count(),'total'=>$rows->count(),'last_page'=>1],'summary'=>collect($x)->except(['source_purchases','sales_documents','prescription_dispensing','adjustments','transfers','stock_count_changes'])->all()];
    }
}
