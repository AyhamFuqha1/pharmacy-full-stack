<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SmartImportWizardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 1, 'force_change_password' => false, 'branch_id' => DB::table('branches')->value('id')]);
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_english_aliases_and_reordered_columns_are_auto_mapped(): void
    {
        $data = $this->preview('products', "ean,product,unknown\nALIAS-1,Alias Product,ignored\n");
        $this->assertSame('barcode', $data['job']['mapping']['column_1']);
        $this->assertSame('name', $data['job']['mapping']['column_2']);
        $this->assertNull($data['job']['mapping']['column_3']);
        $this->assertSame('Alias Product', $data['preview'][0]['name']);
    }

    public function test_arabic_headers_are_auto_mapped(): void
    {
        $data = $this->preview('products', "الباركود,اسم المنتج\nAR-1,منتج عربي\n");
        $this->assertSame(['barcode', 'name'], array_values($data['job']['mapping']));
        $this->assertSame(0, $data['job']['failed_rows']);
    }

    public function test_csv_bom_semicolon_tabs_quotes_and_windows_lines_are_supported(): void
    {
        $semicolon = $this->preview('products', "\xEF\xBB\xBFname;barcode\r\n\"Product; One\";SC-1\r\n");
        $this->assertSame(';', $semicolon['job']['delimiter']);
        $this->assertSame('Product; One', $semicolon['preview'][0]['name']);
        $tab = $this->preview('products', "name\tbarcode\nTab Product\tTAB-1\n");
        $this->assertSame("\t", $tab['job']['delimiter']);
    }

    public function test_missing_required_mapping_blocks_preview_and_manual_mapping_recovers(): void
    {
        $data = $this->preview('products', "title,code\nManual Product,MAN-1\n");
        $this->assertSame('mapping_required', $data['job']['status']);
        $this->assertEqualsCanonicalizing(['name', 'barcode'], $data['job']['missing_fields']);
        $mapped = $this->postJson("/api/imports/{$data['job']['id']}/mapping", ['mapping' => ['column_1' => 'name', 'column_2' => 'barcode']])->assertOk()->json('data');
        $this->assertSame('previewed', $mapped['job']['status']);
        $this->assertSame(0, $mapped['job']['failed_rows']);
    }

    public function test_duplicate_destinations_are_rejected_and_duplicate_headers_remain_distinct(): void
    {
        $data = $this->preview('products', "name,name,barcode\nFirst,Second,DUP-H-1\n");
        $this->assertCount(3, $data['job']['columns']);
        $this->assertContains('name', $data['job']['duplicate_headers']);
        $this->postJson("/api/imports/{$data['job']['id']}/mapping", ['mapping' => ['column_1' => 'name', 'column_2' => 'name', 'column_3' => 'barcode']])->assertUnprocessable();
    }

    public function test_missing_cells_invalid_numbers_boolean_units_and_dates_are_reported(): void
    {
        $product = Product::factory()->create();
        $csv = "product_id,batch_number,quantity,unit_cost,sale_price,expiry_date,unit_id\n{$product->id},B1,-1,nope,5,03/04/2026,999999\n";
        $data = $this->preview('opening_stock', $csv);
        $errors = $data['errors'][0]['errors'];
        $this->assertSame('must_be_positive_number', $errors['quantity']);
        $this->assertSame('must_be_non_negative_number', $errors['unit_cost']);
        $this->assertSame('not_found', $errors['unit_id']);
        $this->assertSame('ambiguous_date', $errors['expiry_date']);

        $validDate = $this->preview('opening_stock', "product_id,batch_number,quantity,unit_cost,sale_price,expiry_date\n{$product->id},B2,1,2,3,14/09/2026\n");
        $this->assertSame('2026-09-14', $validDate['preview'][0]['expiry_date']);

        $boolean = $this->preview('products', "name,barcode,is_active\nBad Bool,BOOL-1,maybe\n");
        $this->assertSame('invalid_boolean', $boolean['errors'][0]['errors']['is_active']);
    }

    public function test_product_duplicates_in_file_and_database_block_confirmation(): void
    {
        Product::factory()->create(['barcode' => 'EXISTS-1']);
        $data = $this->preview('products', "name,barcode\nExisting,EXISTS-1\nFirst,INFILE-1\nSecond,INFILE-1\n");
        $this->assertSame(2, $data['job']['failed_rows']);
        $this->assertSame('duplicate_in_database', $data['errors'][0]['errors']['barcode']);
        $this->assertSame('duplicate_in_file', $data['errors'][1]['errors']['barcode']);
        $this->postJson("/api/imports/{$data['job']['id']}/confirm")->assertConflict();
    }

    public function test_customer_and_supplier_duplicates_are_explicit_and_never_merged(): void
    {
        DB::table('customers')->insert(['name' => 'Known', 'phone_num' => '0590000000', 'city' => 'Gaza']);
        $customer = $this->preview('customers', "customer_name,mobile,city\nKnown,0590000000,Gaza\n");
        $this->assertSame('duplicate_in_database', $customer['errors'][0]['errors']['duplicate']);
        $supplier = $this->preview('suppliers', "اسم المورد,هاتف,المدينة\nمورد,0591111111,الخليل\nمورد آخر,0591111111,الخليل\n");
        $this->assertSame('duplicate_in_file', $supplier['errors'][0]['errors']['duplicate']);
    }

    public function test_opening_stock_resolves_product_by_barcode_and_unique_name(): void
    {
        $one = Product::factory()->create(['name' => 'Unique Name', 'barcode' => 'OPEN-BAR']);
        $byBarcode = $this->preview('opening_stock', "barcode,batch,qty,cost,price\nOPEN-BAR,BAR-B,2,1,3\n");
        $this->assertSame($one->id, $byBarcode['preview'][0]['product_id']);
        $byName = $this->preview('opening_stock', "product,batch,qty,cost,price\nUnique Name,NAME-B,2,1,3\n");
        $this->assertSame($one->id, $byName['preview'][0]['product_id']);
    }

    public function test_ambiguous_product_names_and_missing_entities_are_blocked(): void
    {
        Product::factory()->create(['name' => 'Repeated']); Product::factory()->create(['name' => 'Repeated']);
        $ambiguous = $this->preview('opening_stock', "product,batch,qty,cost,price\nRepeated,B1,2,1,3\n");
        $this->assertSame('ambiguous', $ambiguous['errors'][0]['errors']['product_name']);
        $missing = $this->preview('opening_stock', "barcode,batch,qty,cost,price\nNO-SUCH,B2,2,1,3\n");
        $this->assertSame('not_found', $missing['errors'][0]['errors']['barcode']);
    }

    public function test_preview_writes_no_domain_records_and_confirmation_writes_only_valid_rows(): void
    {
        $before = DB::table('products')->count();
        $data = $this->preview('products', "name,barcode\nConfirmed Product,CONF-1\n");
        $this->assertSame($before, DB::table('products')->count());
        $this->postJson("/api/imports/{$data['job']['id']}/confirm")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseHas('products', ['barcode' => 'CONF-1']);
    }

    public function test_confirmation_is_atomic_when_a_late_insert_fails(): void
    {
        $data = $this->preview('products', "name,barcode\nFirst Atomic,ATOMIC-1\nSecond Atomic,ATOMIC-2\n");
        $job = DB::table('import_jobs')->find($data['job']['id']);
        $payload = json_decode($job->rows, true); $payload['normalized_rows'][1]['barcode'] = 'ATOMIC-1';
        DB::table('import_jobs')->where('id', $job->id)->update(['rows' => json_encode($payload)]);
        $this->postJson("/api/imports/{$job->id}/confirm")->assertServerError();
        $this->assertDatabaseMissing('products', ['barcode' => 'ATOMIC-1']);
    }

    public function test_import_permission_and_job_ownership_are_enforced(): void
    {
        $data = $this->preview('products', "name,barcode\nOwned,OWN-1\n");
        $otherAdmin = User::factory()->create(['role' => 1, 'force_change_password' => false]);
        $this->actingAs($otherAdmin, 'sanctum');
        $this->getJson("/api/imports/{$data['job']['id']}")->assertNotFound();
        $this->get("/api/imports/{$data['job']['id']}/source")->assertNotFound();
        $this->postJson("/api/imports/{$data['job']['id']}/confirm")->assertNotFound();
        $emptyRole = DB::table('roles')->insertGetId(['name' => 'No import '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $plain = User::factory()->create(['role' => $emptyRole, 'force_change_password' => false]);
        $this->actingAs($plain, 'sanctum')->postJson('/api/imports/preview', ['domain' => 'products'])->assertForbidden();
    }

    public function test_xlsx_first_worksheet_and_shared_strings_are_read(): void
    {
        $xlsx = $this->xlsx([['name', 'barcode'], ['XLSX Product', 'XLSX-1']]);
        $data = $this->postJson('/api/imports/preview', ['domain' => 'products', 'file' => UploadedFile::fake()->createWithContent('products.xlsx', $xlsx)])->assertCreated()->json('data');
        $this->assertSame('xlsx', $data['job']['format']);
        $this->assertSame('Sheet One', $data['job']['worksheet']);
        $this->assertSame('XLSX Product', $data['preview'][0]['name']);
    }

    public function test_wrong_extensions_headerless_and_single_column_files_are_rejected(): void
    {
        $this->postJson('/api/imports/preview', ['domain' => 'products', 'file' => UploadedFile::fake()->createWithContent('legacy.xls', 'not excel')])->assertUnprocessable();
        $this->postJson('/api/imports/preview', ['domain' => 'products', 'file' => UploadedFile::fake()->createWithContent('single.csv', "name\nOnly One\n")])->assertUnprocessable();
        $this->postJson('/api/imports/preview', ['domain' => 'products', 'file' => UploadedFile::fake()->createWithContent('empty.csv', "name,barcode\n")])->assertUnprocessable();
    }

    private function preview(string $domain, string $csv): array
    {
        return $this->postJson('/api/imports/preview', ['domain' => $domain, 'file' => UploadedFile::fake()->createWithContent('import.csv', $csv)])->assertCreated()->json('data');
    }

    private function xlsx(array $rows): string
    {
        $sheetRows = '';
        foreach ($rows as $r => $cells) {
            $xmlCells = '';
            foreach ($cells as $c => $value) {
                $column = chr(65 + $c); $escaped = htmlspecialchars($value, ENT_XML1);
                $xmlCells .= "<c r=\"{$column}".($r + 1)."\" t=\"inlineStr\"><is><t>{$escaped}</t></is></c>";
            }
            $sheetRows .= '<row r="'.($r + 1).'">'.$xmlCells.'</row>';
        }
        $entries = [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet One" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>',
        ];
        return $this->zip($entries);
    }

    private function zip(array $entries): string
    {
        $local = ''; $central = ''; $offset = 0;
        foreach ($entries as $name => $contents) {
            $compressed = gzdeflate($contents); $crc = crc32($contents); $nameLength = strlen($name);
            $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, 0, 0, $crc, strlen($compressed), strlen($contents), $nameLength, 0);
            $local .= $header.$name.$compressed;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, 0, 0, $crc, strlen($compressed), strlen($contents), $nameLength, 0, 0, 0, 0, 0, $offset).$name;
            $offset = strlen($local);
        }
        return $local.$central.pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), strlen($local), 0);
    }
}
