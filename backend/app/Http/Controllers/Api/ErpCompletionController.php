<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InventoryExpansionService;
use App\Services\SmartImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ErpCompletionController extends Controller
{
    public function bankMovements(Request $r)
    {
        $v = $r->validate(['account_id' => 'required|exists:accounts,id', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'reconciled' => 'nullable|boolean', 'per_page' => 'nullable|integer|min:1|max:100']);
        $q = DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('vouchers as v', 'v.id', '=', 'e.voucher_id')->leftJoin('bank_reconciliations as br', 'br.journal_entry_line_id', '=', 'l.id')->where('l.account_id', $v['account_id'])->where('e.status', 'posted')->when($v['from'] ?? null, fn ($q, $x) => $q->whereDate('e.entry_date', '>=', $x))->when($v['to'] ?? null, fn ($q, $x) => $q->whereDate('e.entry_date', '<=', $x))->when(array_key_exists('reconciled', $v), fn ($q) => $q->whereRaw('COALESCE(br.reconciled,0)=?', [(int) $v['reconciled']]))->select('l.id as journal_entry_line_id', 'e.id as journal_entry_id', 'e.entry_number', 'e.entry_date', 'l.side', 'l.amount', 'l.description as source_label', 'v.source_type', 'v.source_id', 'br.reconciled', 'br.reconciled_at', 'br.external_reference', 'br.note')->orderBy('e.entry_date')->orderBy('l.id');

        return $this->collection($q->paginate($v['per_page'] ?? 25));
    }

    public function reconcile(Request $r, int $line)
    {
        $v = $r->validate(['external_reference' => 'nullable|string|max:255', 'note' => 'nullable|string|max:1000']);
        $movement = DB::table('journal_entry_lines')->find($line);
        abort_unless($movement, 404);
        DB::table('bank_reconciliations')->updateOrInsert(['journal_entry_line_id' => $line], ['account_id' => $movement->account_id, 'source_type' => 'journal_entry_line', 'source_id' => $line, 'reconciled' => true, 'reconciled_at' => now(), 'reconciled_by' => $r->user()->id, 'external_reference' => $v['external_reference'] ?? null, 'note' => $v['note'] ?? null, 'updated_at' => now(), 'created_at' => now()]);

        return $this->success(DB::table('bank_reconciliations')->where('journal_entry_line_id', $line)->first());
    }

    public function unreconcile(Request $r, int $line)
    {
        $v = $r->validate(['reason' => 'required|string|max:1000']);
        $changed = DB::table('bank_reconciliations')->where('journal_entry_line_id', $line)->where('reconciled', true)->update(['reconciled' => false, 'unreconciled_at' => now(), 'unreconciled_by' => $r->user()->id, 'note' => $v['reason'], 'updated_at' => now()]);
        abort_unless($changed, 409, 'Movement is not reconciled.');

        return $this->success(DB::table('bank_reconciliations')->where('journal_entry_line_id', $line)->first());
    }

    public function controlled(Request $r)
    {
        return $this->success(app(\App\Services\ErpAnalyticsService::class)->controlled($r));
    }

    public function attachments(Request $r, string $type, int $id)
    {
        $this->assertAttachable($type, $id);

        return $this->success(DB::table('attachments')->where(['attachable_type' => $type, 'attachable_id' => $id])->orderByDesc('id')->get(['id', 'original_name', 'mime_type', 'size', 'uploaded_by', 'created_at']));
    }

    public function attachmentUpload(Request $r, string $type, int $id)
    {
        $this->assertAttachable($type, $id);
        $v = $r->validate(['file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,webp,csv,txt']);
        $file = $v['file'];
        $key = $file->storeAs('attachments/'.$type, date('Y/m'), Str::uuid().'.'.$file->extension(), 'local');
        $attachment = DB::table('attachments')->insertGetId(['attachable_type' => $type, 'attachable_id' => $id, 'disk' => 'local', 'storage_key' => $key, 'original_name' => basename($file->getClientOriginalName()), 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'checksum' => hash_file('sha256', $file->getRealPath()), 'uploaded_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return $this->success(DB::table('attachments')->find($attachment), 201);
    }

    public function attachmentDownload(int $id)
    {
        $a = DB::table('attachments')->find($id);
        abort_unless($a && Storage::disk($a->disk)->exists($a->storage_key), 404);
        $this->assertAttachable($a->attachable_type, $a->attachable_id);

        return Storage::disk($a->disk)->download($a->storage_key, $a->original_name, ['Content-Type' => $a->mime_type]);
    }

    public function attachmentDelete(int $id)
    {
        $a = DB::table('attachments')->find($id);
        abort_unless($a, 404);
        $this->assertAttachable($a->attachable_type, $a->attachable_id);
        Storage::disk($a->disk)->delete($a->storage_key);
        DB::table('attachments')->where('id', $id)->delete();

        return response()->json(['success' => true, 'message' => 'Attachment deleted.']);
    }

    public function importPreview(Request $r, SmartImportService $imports)
    {
        $v = $r->validate([
            'domain' => 'required|in:products,customers,suppliers,opening_stock',
            'file' => 'required|file|max:5120|extensions:csv,xlsx|mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream',
            'worksheet' => 'nullable|string|max:255',
        ]);
        $file = $v['file'];
        $extension = strtolower($file->getClientOriginalExtension());
        $key = $file->storeAs('imports/'.date('Y/m'), Str::uuid().'.'.$extension, 'local');
        $id = DB::table('import_jobs')->insertGetId(['user_id' => $r->user()->id, 'domain' => $v['domain'], 'status' => 'uploaded', 'original_name' => basename($file->getClientOriginalName()), 'disk' => 'local', 'storage_reference' => $key, 'checksum' => hash_file('sha256', $file->getRealPath()), 'file_size' => $file->getSize(), 'uploaded_at' => now(), 'rows' => '[]', 'errors' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        try {
            $payload = $imports->parse(Storage::disk('local')->path($key), $file->getClientOriginalName(), $v['domain'], $v['worksheet'] ?? null);
            $this->saveImportPreview($id, $payload);

            return $this->success($this->importResponse($id), 201);
        } catch (\Throwable$e) {
            DB::table('import_jobs')->where('id', $id)->update(['status' => 'failed', 'failure_message' => 'Import parsing failed.', 'error_summary' => $e->getMessage(), 'updated_at' => now()]);
            throw $e;
        }
    }

    public function importMapping(Request $r, int $id, SmartImportService $imports)
    {
        $job = $this->ownedImport($r, $id);
        abort_if(in_array($job->status, ['confirmed', 'completed'], true), 409, 'A confirmed import cannot be remapped.');
        $validated = $r->validate(['mapping' => 'required|array']);
        $payload = json_decode($job->rows, true);
        abort_unless(($payload['version'] ?? null) === 2, 409, 'This legacy import cannot be remapped.');
        $payload = $imports->mapAndValidate($job->domain, $payload, $validated['mapping']);
        $this->saveImportPreview($id, $payload);

        return $this->success($this->importResponse($id));
    }

    public function importShow(Request $r, int $id)
    {
        $this->ownedImport($r, $id);
        $job = $this->safeImport($id);

        return $this->success($job);
    }

    public function importDownload(Request $r, int $id)
    {
        $job = $this->ownedImport($r, $id);
        abort_unless($job && $job->disk && Storage::disk($job->disk)->exists($job->storage_reference), 404);

        return Storage::disk($job->disk)->download($job->storage_reference, $job->original_name, ['Content-Type' => $job->original_name && str_ends_with(strtolower($job->original_name), '.xlsx') ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv']);
    }

    public function importConfirm(Request $r, int $id, InventoryExpansionService $inventory)
    {
        $job = DB::table('import_jobs')->where('id', $id)->lockForUpdate()->first();
        abort_unless($job && $job->user_id === $r->user()->id, 404);
        if ($job->status !== 'previewed' || $job->failed_rows > 0) {
            abort(409, 'Import preview contains errors or is not confirmable.');
        }$stored = json_decode($job->rows, true);
        $rows = ($stored['version'] ?? null) === 2 ? ($stored['normalized_rows'] ?? []) : $stored;
        DB::table('import_jobs')->where('id', $id)->update(['status' => 'confirmed', 'confirmed_at' => now(), 'updated_at' => now()]);
        try {
            DB::transaction(function () use ($rows, $job, $r, $inventory) {
                if ($job->domain === 'opening_stock') {
                    $date = $r->validate(['effective_date' => 'required|date', 'branch_id' => 'required|exists:branches,id']);
                    $inventory->createOpening(['effective_date' => $date['effective_date'], 'branch_id' => $date['branch_id'], 'scope' => 'selected', 'lines' => array_map(fn ($x) => ['type' => 'inventory', 'product_id' => $x['product_id'], 'batch_number' => $x['batch_number'], 'expiry_date' => ($x['expiry_date'] ?? null) ?: null, 'quantity' => $x['quantity'], 'unit_id' => $x['unit_id'] ?? null, 'unit_cost' => $x['unit_cost'], 'sale_price' => $x['sale_price']], $rows)], $r->user()->id);
                } else {
                    foreach ($rows as $row) {
                        $table = ['products' => 'products', 'customers' => 'customers', 'suppliers' => 'providers'][$job->domain];
                        $data = $this->importData($job->domain, $row);
                        $data['created_at'] = now();
                        if ($job->domain === 'products') {
                            $data['updated_at'] = now();
                        }DB::table($table)->insert($data);
                    }
                }DB::table('import_jobs')->where('id', $job->id)->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
            });
        } catch (\Throwable$e) {
            DB::table('import_jobs')->where('id', $id)->update(['status' => 'failed', 'failure_message' => 'Import confirmation failed.', 'error_summary' => $e->getMessage(), 'updated_at' => now()]);
            throw $e;
        }

        return $this->success($this->safeImport($id));
    }

    public function backupStatus()
    {
        return $this->success(app(\App\Services\BackupService::class)->status());
    }

    public function backups()
    {
        return $this->success(app(\App\Services\BackupService::class)->list());
    }

    public function backup(Request $r)
    {
        try {
            return $this->success(app(\App\Services\BackupService::class)->create($r->user()->id), 201);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->failure(__('backup.creation_failed'), 500);
        }
    }

    public function backupDownload(Request $r, int $id)
    {
        $service = app(\App\Services\BackupService::class);
        $run = $service->artifact($id);
        $service->recordDownload($id, $r->user()->id);

        return Storage::disk(config('backup.disk'))->download($run->storage_reference, 'backup-'.$run->id.'.tar.gz', ['Content-Type' => 'application/gzip']);
    }

    public function backupValidate(Request $r, int $id)
    {
        return $this->success(app(\App\Services\BackupService::class)->validate($id, $r->user()->id));
    }

    public function restore(Request $r, int $id)
    {
        $validated = $r->validate(['confirmation' => 'required|in:RESTORE']);

        return $this->success(app(\App\Services\BackupService::class)->prepareRestore($id, $validated['confirmation'], $r->user()->id));
    }

    public function customer360(Request $r, int $id)
    {
        return $this->success(app(\App\Services\ErpAnalyticsService::class)->customer360($r, $id));
    }

    public function customerActivity(Request $r, int $id)
    {
        abort_unless(DB::table('customers')->where('id', $id)->exists(), 404);

        return $this->success(app(\App\Services\ErpAnalyticsService::class)->partyActivity($r, 'customer', $id));
    }

    public function supplier360(Request $r, int $id)
    {
        return $this->success(app(\App\Services\ErpAnalyticsService::class)->supplier360($r, $id));
    }

    public function supplierActivity(Request $r, int $id)
    {
        abort_unless(DB::table('providers')->where('id', $id)->exists(), 404);

        return $this->success(app(\App\Services\ErpAnalyticsService::class)->partyActivity($r, 'supplier', $id));
    }

    public function product360(Request $r, int $id)
    {
        return $this->success(app(\App\Services\ErpAnalyticsService::class)->product360($r, $id));
    }

    public function productProfitability(Request $r, int $id)
    {
        $v = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'branch_id' => 'nullable|exists:branches,id']);

        return $this->success(app(\App\Services\ProfitabilityService::class)->product($id, $v['from'] ?? null, $v['to'] ?? null, isset($v['branch_id']) ? (int) $v['branch_id'] : null));
    }

    public function batch360(Request $r, int $id)
    {
        return $this->success(app(\App\Services\ErpAnalyticsService::class)->batch360($r, $id));
    }

    public function stockStatement(Request $r, int $product)
    {
        return $this->success(app(\App\Services\ProductStockStatementService::class)->query($r, $product));
    }

    public function recall(int $id, Request $r)
    {
        return $this->success(app(\App\Services\ErpAnalyticsService::class)->recall($r, $id));
    }

    public function alerts(Request $r)
    {
        $alerts = collect();
        $today = now()->startOfDay();
        foreach (DB::table('products as p')->leftJoin('product_batches as b', fn ($j) => $j->on('b.product_id', '=', 'p.id')->where('b.inventory_state', 'available')->where(fn ($q) => $q->whereNull('b.expiry_date')->orWhere('b.expiry_date', '>', $today)))->groupBy('p.id', 'p.name', 'p.reorder_level')->selectRaw('p.id,p.name,p.reorder_level,COALESCE(SUM(b.quantity),0) stock')->get() as $x) {
            if ($x->stock <= $x->reorder_level) {
                $alerts->push($this->alert($x->stock <= 0 ? 'out_of_stock' : 'low_stock', $x->stock <= 0 ? 'critical' : 'warning', 'product', $x->id, $x->name, $x->stock, $x->reorder_level));
            }
        }foreach (DB::table('product_batches')->whereNull('deleted_at')->get() as $x) {
            if ($x->expiry_date !== null && now()->greaterThan($x->expiry_date)) {
                $alerts->push($this->alert('expired_batch', 'critical', 'batch', $x->id, $x->batch_number, $x->quantity, $x->expiry_date));
            } elseif ($x->expiry_date !== null && now()->addDays(30)->greaterThanOrEqualTo($x->expiry_date)) {
                $alerts->push($this->alert('expiring_batch', 'warning', 'batch', $x->id, $x->batch_number, $x->quantity, $x->expiry_date));
            }if (in_array($x->inventory_state, ['quarantined', 'recalled'])) {
                $alerts->push($this->alert($x->inventory_state.'_batch', 'critical', 'batch', $x->id, $x->batch_number, $x->quantity, $x->inventory_state));
            }
        }foreach (DB::table('checks')->where('status', 'pending')->whereDate('due_date', '<=', now()->addDays(7))->get() as $x) {
            $alerts->push($this->alert(($x->direction === 'in' ? 'incoming' : 'outgoing').'_cheque_due', 'warning', 'cheque', $x->id, $x->check_number, $x->amount, $x->due_date));
        }foreach (DB::table('sales_invoices')->whereNull('deleted_at')->whereDate('due_date', '<', $today)->get() as $x) {
            $settled = (float) $x->paid_amount + (float) DB::table('settlement_allocations')->where(['invoice_type' => 'sale', 'invoice_id' => $x->id])->sum('amount') + (float) DB::table('invoice_returns')->where('invoice_id', $x->id)->selectRaw('COALESCE(SUM(quantity * COALESCE(customer_credit_unit_amount, price)), 0) as total')->value('total');
            $out = max(0, (float) $x->total_price - $settled);
            if ($out > 0) {
                $alerts->push($this->alert('overdue_ar', 'warning', 'sales_invoice', $x->id, $x->document_number ?? 'Legacy sales invoice (no document number)', $out, $x->due_date));
            }
        }foreach (DB::table('purchase_invoices')->where('status', 'posted')->whereDate('due_date', '<=', $today)->get() as $x) {
            $settled = (float) DB::table('settlement_allocations')->where(['invoice_type' => 'purchase', 'invoice_id' => $x->id])->sum('amount') + (float) DB::table('purch_returns')->where('invoice_id', $x->id)->sum('line_total_return');
            $out = max(0, (float) $x->amount - $settled);
            if ($out > 0) {
                $alerts->push($this->alert('ap_due', 'warning', 'purchase_invoice', $x->id, $x->document_number ?? 'Legacy purchase invoice (no document number)', $out, $x->due_date));
            }
        }foreach (DB::table('customers')->whereNotNull('credit_limit')->get() as $x) {
            $balance = max(0, (float) app(\App\Services\AccountingReportService::class)->customerStatement($x->id)['balance']);
            $warning = $x->credit_warning_threshold ?? $x->credit_limit * .8;
            if ($balance >= $x->credit_limit) {
                $alerts->push($this->alert('credit_limit_exceeded', 'critical', 'customer', $x->id, $x->name, $balance, $x->credit_limit));
            } elseif ($balance >= $warning) {
                $alerts->push($this->alert('credit_limit_warning', 'warning', 'customer', $x->id, $x->name, $balance, $warning));
            }
        }foreach (DB::table('stock_count_lines as l')->join('stock_counts as c', 'c.id', '=', 'l.stock_count_id')->where('c.status', '!=', 'posted')->where('l.difference', '!=', 0)->get(['c.id', 'c.document_number', 'l.difference']) as $x) {
            $alerts->push($this->alert('unresolved_stock_count_discrepancy', 'warning', 'stock_count', $x->id, $x->document_number, $x->difference, 0));
        }$threshold = (float) (DB::table('application_settings')->where('key', 'cashier_shift_discrepancy_threshold')->value('value') ?? 5);
        foreach (DB::table('cashier_shifts')->where('status', 'closed')->whereRaw('ABS(difference)>?', [$threshold])->get() as $x) {
            $alerts->push($this->alert('cashier_shift_discrepancy', 'warning', 'cashier_shift', $x->id, "Shift #$x->id", $x->difference, $threshold));
        }

        return $this->success($alerts->unique(fn ($x) => $x['source_identity'])->values());
    }

    public function printDocument(Request $r, string $type, int $id)
    {
        return $this->success(app(\App\Services\PrintDocumentService::class)->document($r, $type, $id));
    }

    public function printReportDocument(Request $r, string $type)
    {
        return $this->success(app(\App\Services\PrintDocumentService::class)->report($r, $type));
    }

    private function assertAttachable(string $type, int $id): void
    {
        $map = ['prescription' => 'prescriptions', 'purchase' => 'purchase_invoices', 'cheque' => 'checks', 'expense' => 'expenses', 'stock_count' => 'stock_counts', 'batch_recall' => 'batch_recalls'];
        abort_unless(isset($map[$type]) && DB::table($map[$type])->where('id', $id)->exists(), 404);
    }

    private function validateImportRow(string $d, array $r): array
    {
        $required = ['products' => ['name', 'barcode'], 'customers' => ['name'], 'suppliers' => ['name'], 'opening_stock' => ['product_id', 'batch_number', 'quantity', 'unit_cost', 'sale_price']][$d];
        $e = [];
        foreach ($required as $k) {
            if (! isset($r[$k]) || trim((string) $r[$k]) === '') {
                $e[$k] = 'required';
            }
        }if ($d === 'opening_stock' && isset($r['product_id']) && ! DB::table('products')->where('id', $r['product_id'])->exists()) {
            $e['product_id'] = 'not_found';
        }if ($d === 'opening_stock' && trim((string) ($r['expiry_date'] ?? '')) !== '' && ! date_create_from_format('!Y-m-d', (string) $r['expiry_date'])) {
            $e['expiry_date'] = 'invalid_date';
        }if ($d === 'products' && isset($r['barcode']) && DB::table('products')->where('barcode', $r['barcode'])->exists()) {
            $e['barcode'] = 'duplicate';
        }

        return $e;
    }

    private function importData(string $d, array $r): array
    {
        return match ($d) {
            'products' => array_filter(['name' => $r['name'], 'barcode' => $r['barcode'], 'nots' => $r['notes'] ?? null, 'is_active' => $r['is_active'] ?? 1, 'base_unit_id' => $r['base_unit_id'] ?? null, 'minimum_stock' => $r['minimum_stock'] ?? 0, 'reorder_level' => $r['reorder_level'] ?? 0, 'reorder_quantity' => $r['reorder_quantity'] ?? 0], fn ($value) => $value !== null),'customers' => ['name' => $r['name'], 'phone_num' => $r['phone'] ?? null, 'city' => $r['city'] ?? null, 'dept' => 0],'suppliers' => ['name' => $r['name'], 'phone_num' => $r['phone'] ?? null, 'city' => $r['city'] ?? null, 'dept' => 0]
        };
    }

    private function agingOne(Request $r, string $side, int $id): array
    {
        $copy = Request::create('/', 'GET', array_merge($r->query(), ['party_id' => $id, 'detail' => true]));
        $copy->setUserResolver(fn () => $r->user());

        return app(\App\Services\AgingService::class)->report($copy, $side);
    }

    private function sum(string $t, string $c, string $p, int $id, Request $r): float
    {
        return (float) DB::table($t)->where($p, $id)->when($r->from, fn ($q) => $q->whereDate('date', '>=', $r->from))->when($r->to, fn ($q) => $q->whereDate('date', '<=', $r->to))->sum($c);
    }

    private function linkedReturnSum(string $r, string $i, string $p, int $id, Request $req): float
    {
        return (float) DB::table($r.' as r')->join($i.' as i', 'i.id', '=', 'r.invoice_id')->where('i.'.$p, $id)->when($req->from, fn ($q) => $q->whereDate('r.created_at', '>=', $req->from))->when($req->to, fn ($q) => $q->whereDate('r.created_at', '<=', $req->to))->sum('r.line_total_return');
    }

    private function recent(string $t, string $p, int $id, Request $r)
    {
        return DB::table($t)->where($p, $id)->orderByDesc('id')->limit(25)->get();
    }

    private function stockPage(Request $r, int $product, ?int $batch)
    {
        return DB::table('stock')->join('product_batches', 'product_batches.id', '=', 'stock.batch_id')->where('product_batches.product_id', $product)->when($batch, fn ($q) => $q->where('stock.batch_id', $batch))->orderByDesc('stock.id')->paginate(min(100, max(1, $r->integer('per_page', 25))));
    }

    private function signedStockQuery(int $p, $b)
    {
        return DB::table('stock')->join('product_batches', 'product_batches.id', '=', 'stock.batch_id')->where('product_batches.product_id', $p)->when($b, fn ($q) => $q->where('stock.batch_id', $b))->select('stock.*');
    }

    private function signed($x): float
    {
        return (str_contains(strtoupper($x->type), 'OUT') || in_array($x->type, ['SALE', 'PURCHASE_RETURN'])) ? -(float) $x->quantity : (float) $x->quantity;
    }

    private function alert($type, $severity, $entity, $id, $message, $value, $threshold): array
    {
        return ['alert_type' => $type, 'severity' => $severity, 'entity' => $entity, 'entity_id' => $id, 'message' => $message, 'current_value' => $value, 'threshold_or_date' => $threshold, 'source_identity' => $type.':'.$entity.':'.$id, 'generated_at' => now()->toISOString(), 'semantics' => 'current_actionable'];
    }

    private function safeImport(int $id)
    {
        $j = DB::table('import_jobs')->find($id);
        if ($j) {
            $payload = json_decode($j->rows, true);
            if (($payload['version'] ?? null) === 2) {
                foreach (['format', 'delimiter', 'worksheets', 'worksheet', 'columns', 'mapping', 'duplicate_headers', 'missing_fields'] as $key) {
                    $j->{$key} = $payload[$key] ?? null;
                }
                $j->destination_fields = app(SmartImportService::class)->fields($j->domain);
                $j->warnings = $payload['warnings'] ?? [];
            }
            unset($j->disk,$j->storage_reference,$j->rows);
            $j->original_file_download = "/api/imports/$id/source";
        }

        return $j;
    }

    private function ownedImport(Request $request, int $id): object
    {
        $job = DB::table('import_jobs')->where('id', $id)->where('user_id', $request->user()->id)->first();
        abort_unless($job, 404);

        return $job;
    }

    private function saveImportPreview(int $id, array $payload): void
    {
        $errors = $payload['errors'] ?? [];
        DB::table('import_jobs')->where('id', $id)->update([
            'status' => $payload['status'], 'rows' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'errors' => json_encode($errors, JSON_UNESCAPED_UNICODE),
            'total_rows' => $payload['total_rows'], 'valid_rows' => $payload['valid_rows'], 'failed_rows' => $payload['failed_rows'],
            'parsed_at' => now(), 'previewed_at' => $payload['status'] === 'previewed' ? now() : null,
            'error_summary' => $errors ? count($errors).' row(s) require correction.' : (($payload['missing_fields'] ?? []) ? 'Required column mappings are missing.' : null), 'updated_at' => now(),
        ]);
    }

    private function importResponse(int $id): array
    {
        $job = DB::table('import_jobs')->find($id);
        $payload = json_decode($job->rows, true);

        return ['job' => $this->safeImport($id), 'preview' => $payload['preview'] ?? [], 'errors' => $payload['errors'] ?? [], 'warnings' => $payload['warnings'] ?? []];
    }
}
