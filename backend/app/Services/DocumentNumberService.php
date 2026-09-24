<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class DocumentNumberService { public function next(string $type,string $prefix):string{return DB::transaction(function()use($type,$prefix){$year=(int)now()->format('Y');$row=DB::table('document_sequences')->where(['document_type'=>$type,'year'=>$year])->lockForUpdate()->first();if(!$row){DB::table('document_sequences')->insert(['document_type'=>$type,'year'=>$year,'last_number'=>1]);$n=1;}else{$n=$row->last_number+1;DB::table('document_sequences')->where('id',$row->id)->update(['last_number'=>$n]);}return sprintf('%s-%d-%06d',$prefix,$year,$n);});}}
