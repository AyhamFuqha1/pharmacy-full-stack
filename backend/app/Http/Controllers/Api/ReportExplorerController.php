<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller; use App\Services\ReportExplorerService; use Illuminate\Http\Request; use Illuminate\Support\Facades\DB;
class ReportExplorerController extends Controller {
 public function __construct(private ReportExplorerService $reports){}
 public function catalog(Request $r){return $this->success($this->reports->catalog($r->user()),200,'Report catalog retrieved.');}
 public function query(Request $r){$v=$this->validated($r);return $this->success($this->reports->query($v,$r->user()),200,'Report generated.');}
 public function export(Request $r){return $this->reports->export($this->validated($r),$r->user());}
 public function printData(Request$r){return $this->success($this->reports->printData($this->validated($r),$r->user()));}
 public function savedIndex(Request $r){return $this->collection(DB::table('saved_reports')->where(fn($q)=>$q->where('owner_id',$r->user()->id)->orWhere('is_shared',true))->orderByDesc('id')->paginate(25),'Saved reports retrieved.');}
 public function savedStore(Request $r){$v=$r->validate(['name'=>'required|string|max:100','report_key'=>'required|string','filters'=>'nullable|array','sort'=>'nullable|string','direction'=>'nullable|in:asc,desc','visible_columns'=>'nullable|array','is_shared'=>'nullable|boolean']);$this->reports->definition($v['report_key']);abort_unless($this->reports->isSelectable($v['report_key']),422,'This report is available in its dedicated module and cannot be saved here.');$id=DB::table('saved_reports')->insertGetId(array_merge($v,['owner_id'=>$r->user()->id,'filters'=>json_encode($v['filters']??[]),'visible_columns'=>json_encode($v['visible_columns']??[]),'created_at'=>now(),'updated_at'=>now()]));return $this->success(DB::table('saved_reports')->find($id),201,'Saved report created.');}
 public function savedExecute(Request $r,int $id){$saved=DB::table('saved_reports')->where('id',$id)->where(fn($q)=>$q->where('owner_id',$r->user()->id)->orWhere('is_shared',true))->firstOrFail();return $this->success($this->reports->query(['report_key'=>$saved->report_key,'filters'=>json_decode($saved->filters,true)?:[],'sort'=>$saved->sort,'direction'=>$saved->direction,'columns'=>json_decode($saved->visible_columns,true)?:null,'per_page'=>$r->integer('per_page',25)],$r->user()));}
 public function savedDestroy(Request $r,int $id){$deleted=DB::table('saved_reports')->where(['id'=>$id,'owner_id'=>$r->user()->id])->delete();abort_unless($deleted,404);return $this->success(null,200,'Saved report deleted.');}
 private function validated(Request $r){return $r->validate(['report_key'=>'required|string','filters'=>'nullable|array','columns'=>'nullable|array','columns.*'=>'string','sort'=>'nullable|string','direction'=>'nullable|in:asc,desc','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:1|max:100']);}
}
