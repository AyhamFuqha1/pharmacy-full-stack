<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SmartImportService
{
    public const MAX_ROWS = 5000;

    public function __construct(private readonly XlsxImportReader $xlsx) {}

    /** @return array<string,mixed> */
    public function parse(string $path, string $originalName, string $domain, ?string $worksheet = null): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $format = match ($extension) {
            'csv' => 'csv', 'xlsx' => 'xlsx', default => throw ValidationException::withMessages(['file' => ['Only CSV and XLSX files are supported.']]),
        };
        if ($format === 'xlsx') {
            $parsed = $this->xlsx->read($path, $worksheet);
            $matrix = $parsed['rows'];
            $worksheets = $parsed['worksheets'];
            $selectedWorksheet = $parsed['selected'];
            $delimiter = null;
        } else {
            [$matrix, $delimiter] = $this->readCsv($path);
            $worksheets = [];
            $selectedWorksheet = null;
        }
        while ($matrix !== [] && $this->emptyRow($matrix[0])) array_shift($matrix);
        if (count($matrix) < 2) throw ValidationException::withMessages(['file' => ['The file must contain a header row and at least one data row.']]);
        $rawHeaders = array_map(fn ($value) => trim((string) $value), array_shift($matrix));
        if (count(array_filter($rawHeaders, fn ($value) => $value !== '')) < 2) {
            throw ValidationException::withMessages(['file' => ['The header row must contain at least two columns. Check the delimiter and file format.']]);
        }
        if (count($matrix) > self::MAX_ROWS) throw ValidationException::withMessages(['file' => ['The file exceeds the 5,000-row import limit.']]);

        $columns = [];
        $normalizedCounts = [];
        foreach ($rawHeaders as $index => $header) {
            $normalized = $this->normalizeHeader($header);
            $normalizedCounts[$normalized] = ($normalizedCounts[$normalized] ?? 0) + 1;
            $columns[] = ['id' => 'column_'.($index + 1), 'index' => $index, 'header' => $header ?: '(blank)', 'normalized_header' => $normalized, 'samples' => []];
        }
        $sourceRows = [];
        foreach ($matrix as $offset => $values) {
            if ($this->emptyRow($values)) continue;
            $row = [];
            foreach ($columns as &$column) {
                $value = $values[$column['index']] ?? '';
                $row[$column['id']] = is_string($value) ? trim($value) : $value;
                if ($value !== '' && count($column['samples']) < 3) $column['samples'][] = $value;
            }
            unset($column);
            $sourceRows[] = ['row' => $offset + 2, 'values' => $row, 'column_mismatch' => count($values) !== count($columns)];
        }
        if ($sourceRows === []) throw ValidationException::withMessages(['file' => ['The file contains no data rows.']]);

        $duplicates = [];
        foreach ($columns as $column) if ($column['normalized_header'] !== '' && ($normalizedCounts[$column['normalized_header']] ?? 0) > 1) $duplicates[] = $column['header'];
        $mapping = $this->suggestMapping($domain, $columns);
        $payload = [
            'version' => 2, 'format' => $format, 'delimiter' => $delimiter, 'worksheets' => $worksheets,
            'worksheet' => $selectedWorksheet, 'columns' => $columns, 'source_rows' => $sourceRows,
            'mapping' => $mapping, 'duplicate_headers' => array_values(array_unique($duplicates)),
        ];
        return $this->mapAndValidate($domain, $payload, $mapping);
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $mapping */
    public function mapAndValidate(string $domain, array $payload, array $mapping): array
    {
        $allowedColumns = collect($payload['columns'])->pluck('id')->all();
        $fields = $this->fields($domain);
        $allowedFields = array_keys($fields);
        $cleanMapping = [];
        foreach ($allowedColumns as $column) {
            $destination = $mapping[$column] ?? null;
            if ($destination === '' || $destination === 'ignore') $destination = null;
            if ($destination !== null && ! in_array($destination, $allowedFields, true)) {
                throw ValidationException::withMessages(['mapping' => ["Unknown destination field: $destination"]]);
            }
            $cleanMapping[$column] = $destination;
        }
        $mapped = array_values(array_filter($cleanMapping));
        if (count($mapped) !== count(array_unique($mapped))) {
            throw ValidationException::withMessages(['mapping' => ['Two source columns cannot map to the same destination field.']]);
        }
        $missing = $this->missingRequired($domain, $mapped);
        $payload['mapping'] = $cleanMapping;
        $payload['normalized_rows'] = [];
        $errors = [];
        $warnings = [];
        if ($missing !== []) {
            return $payload + ['status' => 'mapping_required', 'missing_fields' => $missing, 'errors' => [], 'warnings' => [], 'total_rows' => count($payload['source_rows']), 'valid_rows' => 0, 'failed_rows' => count($payload['source_rows']), 'preview' => []];
        }

        $seenProducts = [];
        $seenParties = [];
        foreach ($payload['source_rows'] as $source) {
            $row = [];
            foreach ($cleanMapping as $column => $destination) if ($destination !== null) $row[$destination] = $source['values'][$column] ?? '';
            [$normalized, $rowErrors, $rowWarnings] = $this->validateRow($domain, $row, $payload['format'], $seenProducts, $seenParties);
            if ($source['column_mismatch']) $rowErrors['columns'] = 'column_count_mismatch';
            if ($rowErrors !== []) $errors[] = ['row' => $source['row'], 'errors' => $rowErrors, 'values' => $normalized];
            foreach ($rowWarnings as $warning) $warnings[] = ['row' => $source['row'], 'message' => $warning];
            $payload['normalized_rows'][] = $normalized;
        }
        $payload['status'] = 'previewed';
        $payload['missing_fields'] = [];
        $payload['errors'] = $errors;
        $payload['warnings'] = $warnings;
        $payload['total_rows'] = count($payload['source_rows']);
        $payload['failed_rows'] = count($errors);
        $payload['valid_rows'] = $payload['total_rows'] - $payload['failed_rows'];
        $payload['preview'] = array_slice($payload['normalized_rows'], 0, 100);
        return $payload;
    }

    /** @return array<string,array<string,mixed>> */
    public function fields(string $domain): array
    {
        return match ($domain) {
            'products' => [
                'name' => ['label' => 'Product name', 'required' => true], 'barcode' => ['label' => 'Barcode', 'required' => true],
                'notes' => ['label' => 'Notes'], 'is_active' => ['label' => 'Active'], 'base_unit_id' => ['label' => 'Base unit ID'],
                'minimum_stock' => ['label' => 'Minimum stock'], 'reorder_level' => ['label' => 'Reorder level'], 'reorder_quantity' => ['label' => 'Reorder quantity'],
            ],
            'customers', 'suppliers' => ['name' => ['label' => 'Name', 'required' => true], 'phone' => ['label' => 'Phone'], 'city' => ['label' => 'City']],
            'opening_stock' => [
                'product_id' => ['label' => 'Product ID'], 'barcode' => ['label' => 'Product barcode'], 'product_name' => ['label' => 'Product name'],
                'batch_number' => ['label' => 'Batch number', 'required' => true], 'expiry_date' => ['label' => 'Expiry date'],
                'quantity' => ['label' => 'Quantity', 'required' => true], 'unit_id' => ['label' => 'Unit ID'],
                'unit_cost' => ['label' => 'Unit cost', 'required' => true], 'sale_price' => ['label' => 'Sale price', 'required' => true],
            ],
            default => throw new RuntimeException('Unsupported import domain.'),
        };
    }

    /** @return array<string,?string> */
    private function suggestMapping(string $domain, array $columns): array
    {
        $aliases = $this->aliases($domain);
        $mapping = [];
        $used = [];
        foreach ($columns as $column) {
            $destination = null;
            foreach ($aliases as $field => $values) {
                if (! isset($used[$field]) && in_array($column['normalized_header'], array_map([$this, 'normalizeHeader'], $values), true)) {
                    $destination = $field; $used[$field] = true; break;
                }
            }
            $mapping[$column['id']] = $destination;
        }
        return $mapping;
    }

    private function aliases(string $domain): array
    {
        $common = [
            'name' => ['name', 'product_name', 'product', 'اسم المنتج', 'المنتج'],
            'barcode' => ['barcode', 'ean', 'ean13', 'باركود', 'الباركود'],
            'quantity' => ['quantity', 'qty', 'stock_qty', 'كمية', 'الكمية'],
            'sale_price' => ['sale_price', 'selling_price', 'price', 'unit_price', 'سعر البيع', 'سعر الحبة'],
            'unit_cost' => ['unit_cost', 'cost', 'purchase_price', 'cost_price', 'سعر الشراء', 'التكلفة'],
            'batch_number' => ['batch_number', 'batch', 'lot', 'lot_number', 'رقم التشغيلة', 'التشغيلة'],
            'expiry_date' => ['expiry_date', 'expiry', 'expiration_date', 'تاريخ الانتهاء', 'الصلاحية'],
        ];
        return match ($domain) {
            'products' => [
                'name' => $common['name'], 'barcode' => $common['barcode'], 'notes' => ['notes', 'note', 'ملاحظات'],
                'is_active' => ['is_active', 'active', 'نشط'], 'base_unit_id' => ['base_unit_id', 'unit_id', 'معرف الوحدة'],
                'minimum_stock' => ['minimum_stock', 'min_stock', 'الحد الأدنى للمخزون'], 'reorder_level' => ['reorder_level', 'حد إعادة الطلب'], 'reorder_quantity' => ['reorder_quantity', 'كمية إعادة الطلب'],
            ],
            'customers', 'suppliers' => ['name' => ['name', $domain === 'customers' ? 'customer_name' : 'supplier_name', $domain === 'customers' ? 'اسم العميل' : 'اسم المورد'], 'phone' => ['phone', 'phone_num', 'mobile', 'هاتف', 'الجوال'], 'city' => ['city', 'المدينة']],
            'opening_stock' => [
                'product_id' => ['product_id', 'معرف المنتج'], 'barcode' => $common['barcode'], 'product_name' => $common['name'],
                'batch_number' => $common['batch_number'], 'expiry_date' => $common['expiry_date'], 'quantity' => $common['quantity'],
                'unit_id' => ['unit_id', 'معرف الوحدة'], 'unit_cost' => $common['unit_cost'], 'sale_price' => $common['sale_price'],
            ],
        };
    }

    private function missingRequired(string $domain, array $mapped): array
    {
        $required = collect($this->fields($domain))->filter(fn ($field) => $field['required'] ?? false)->keys()->all();
        $missing = array_values(array_diff($required, $mapped));
        if ($domain === 'opening_stock' && count(array_intersect(['product_id', 'barcode', 'product_name'], $mapped)) === 0) $missing[] = 'product_reference';
        return $missing;
    }

    private function validateRow(string $domain, array $row, string $format, array &$seenProducts, array &$seenParties): array
    {
        $errors = []; $warnings = [];
        foreach ($row as $key => $value) if (is_string($value)) $row[$key] = trim($value);
        foreach ($this->missingRequired($domain, array_keys(array_filter($row, fn ($value) => $value !== '' && $value !== null))) as $field) $errors[$field] = 'required';
        foreach (['name', 'product_name', 'barcode', 'batch_number', 'phone', 'city'] as $field) {
            if (isset($row[$field]) && mb_strlen((string) $row[$field]) > 255) $errors[$field] = 'max_255';
        }
        if ($domain === 'products') {
            $barcode = (string) ($row['barcode'] ?? '');
            if ($barcode !== '') {
                if (isset($seenProducts[$barcode])) $errors['barcode'] = 'duplicate_in_file';
                elseif (DB::table('products')->where('barcode', $barcode)->exists()) $errors['barcode'] = 'duplicate_in_database';
                $seenProducts[$barcode] = true;
            }
            foreach (['minimum_stock', 'reorder_level', 'reorder_quantity'] as $field) $this->nonNegative($row, $field, $errors);
            $this->entityId($row, 'base_unit_id', 'units', $errors);
            if (array_key_exists('is_active', $row) && $row['is_active'] !== '') $row['is_active'] = $this->boolean($row['is_active'], $errors, 'is_active');
        } elseif (in_array($domain, ['customers', 'suppliers'], true)) {
            $table = $domain === 'customers' ? 'customers' : 'providers';
            $phone = trim((string) ($row['phone'] ?? ''));
            $identity = $phone !== '' ? 'phone:'.$phone : 'name:'.mb_strtolower((string) ($row['name'] ?? '')).'|'.mb_strtolower((string) ($row['city'] ?? ''));
            if (isset($seenParties[$identity])) $errors['duplicate'] = 'duplicate_in_file';
            if (($phone !== '' && DB::table($table)->where('phone_num', $phone)->exists()) || ($phone === '' && DB::table($table)->whereRaw('LOWER(name) = ?', [mb_strtolower((string) ($row['name'] ?? ''))])->where('city', $row['city'] ?? null)->exists())) $errors['duplicate'] = 'duplicate_in_database';
            $seenParties[$identity] = true;
        } else {
            foreach (['quantity', 'unit_cost', 'sale_price'] as $field) $this->nonNegative($row, $field, $errors, $field === 'quantity');
            $this->entityId($row, 'unit_id', 'units', $errors);
            $product = $this->resolveProduct($row, $errors);
            if ($product) $row['product_id'] = $product;
            unset($row['barcode'], $row['product_name']);
            if (($row['expiry_date'] ?? '') !== '') $row['expiry_date'] = $this->date($row['expiry_date'], $format, $errors);
        }
        return [$row, $errors, $warnings];
    }

    private function resolveProduct(array $row, array &$errors): ?int
    {
        if (($row['product_id'] ?? '') !== '') {
            if (! ctype_digit((string) $row['product_id']) || ! DB::table('products')->where('id', $row['product_id'])->exists()) { $errors['product_id'] = 'not_found'; return null; }
            return (int) $row['product_id'];
        }
        if (($row['barcode'] ?? '') !== '') {
            $ids = DB::table('products')->where('barcode', $row['barcode'])->pluck('id');
            if ($ids->count() !== 1) { $errors['barcode'] = $ids->isEmpty() ? 'not_found' : 'ambiguous'; return null; }
            return (int) $ids->first();
        }
        if (($row['product_name'] ?? '') !== '') {
            $ids = DB::table('products')->whereRaw('LOWER(name) = ?', [mb_strtolower($row['product_name'])])->pluck('id');
            if ($ids->count() !== 1) { $errors['product_name'] = $ids->isEmpty() ? 'not_found' : 'ambiguous'; return null; }
            return (int) $ids->first();
        }
        $errors['product_reference'] = 'required'; return null;
    }

    private function nonNegative(array &$row, string $field, array &$errors, bool $positive = false): void
    {
        if (! array_key_exists($field, $row) || $row[$field] === '') return;
        if (! is_numeric($row[$field]) || (float) $row[$field] < ($positive ? 0.0000001 : 0)) $errors[$field] = $positive ? 'must_be_positive_number' : 'must_be_non_negative_number';
        else $row[$field] = (float) $row[$field];
    }

    private function entityId(array &$row, string $field, string $table, array &$errors): void
    {
        if (($row[$field] ?? '') === '') { unset($row[$field]); return; }
        if (! ctype_digit((string) $row[$field]) || ! DB::table($table)->where('id', $row[$field])->exists()) $errors[$field] = 'not_found';
        else $row[$field] = (int) $row[$field];
    }

    private function boolean(mixed $value, array &$errors, string $field): ?bool
    {
        $normalized = mb_strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'نعم', 'نشط'], true)) return true;
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'لا', 'غير نشط'], true)) return false;
        $errors[$field] = 'invalid_boolean'; return null;
    }

    private function date(mixed $value, string $format, array &$errors): mixed
    {
        if ($format === 'xlsx' && is_numeric($value)) return Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        $value = trim((string) $value);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $parts) && (int) $parts[1] <= 12 && (int) $parts[2] <= 12) {
            $errors['expiry_date'] = 'ambiguous_date'; return $value;
        }
        foreach (['!Y-m-d', '!d/m/Y'] as $pattern) {
            $date = \DateTimeImmutable::createFromFormat($pattern, $value);
            $last = \DateTimeImmutable::getLastErrors();
            if ($date && ($last === false || ($last['warning_count'] === 0 && $last['error_count'] === 0)) && $date->format(substr($pattern, 1)) === $value) return $date->format('Y-m-d');
        }
        $errors['expiry_date'] = 'invalid_or_ambiguous_date'; return $value;
    }

    private function normalizeHeader(mixed $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', trim((string) $value));
        return mb_strtolower(trim(preg_replace('/[\s\-]+/u', '_', $value), '_'));
    }

    /** @return array{0:array<int,array<int,string>>,1:string} */
    private function readCsv(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false || ! mb_check_encoding($contents, 'UTF-8')) throw ValidationException::withMessages(['file' => ['CSV files must use UTF-8 encoding.']]);
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $lines = preg_split('/\r\n|\n|\r/', $contents);
        $sample = array_values(array_filter(array_slice($lines, 0, 10), fn ($line) => trim($line) !== ''));
        $scores = [];
        foreach ([',', ';', "\t"] as $delimiter) {
            $counts = array_map(fn ($line) => count(str_getcsv($line, $delimiter, '"', '\\')), array_slice($sample, 0, 5));
            $scores[$delimiter] = count(array_unique($counts)) === 1 ? ($counts[0] ?? 1) : 1;
        }
        arsort($scores); $delimiter = array_key_first($scores);
        if (($scores[$delimiter] ?? 1) < 2) throw ValidationException::withMessages(['file' => ['Could not detect a comma, semicolon, or tab delimiter.']]);
        $handle = fopen('php://temp', 'r+'); fwrite($handle, $contents); rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) $rows[] = $row;
        fclose($handle);
        return [$rows, $delimiter];
    }

    private function emptyRow(array $row): bool
    {
        return count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0;
    }
}
