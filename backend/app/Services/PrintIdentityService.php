<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrintIdentityService
{
    public const SETTING_KEY = 'print_identity';
    public const FIELD_KEYS = ['business_name', 'business_name_secondary', 'address', 'phone', 'secondary_phone', 'mobile', 'email', 'website', 'tax_number', 'registration_number', 'license_number'];

    public function configuration(): array
    {
        $stored = DB::table('application_settings')->where('key', self::SETTING_KEY)->value('value');
        $decoded = $stored ? json_decode($stored, true) : null;
        $fields = [];
        foreach (self::FIELD_KEYS as $index => $key) {
            $fields[$key] = [
                'value' => (string) ($decoded['fields'][$key]['value'] ?? ''),
                'visible' => (bool) ($decoded['fields'][$key]['visible'] ?? true),
                'order' => (int) ($decoded['fields'][$key]['order'] ?? ($index + 2)),
            ];
        }
        return [
            'fields' => $fields,
            'logo' => ['asset_id' => $decoded['logo']['asset_id'] ?? null, 'visible' => (bool) ($decoded['logo']['visible'] ?? true), 'order' => (int) ($decoded['logo']['order'] ?? 1)],
            'custom_fields' => collect($decoded['custom_fields'] ?? [])->map(fn ($field, $index) => [
                'label' => (string) ($field['label'] ?? ''), 'value' => (string) ($field['value'] ?? ''),
                'visible' => (bool) ($field['visible'] ?? true), 'order' => (int) ($field['order'] ?? (100 + $index)),
            ])->values()->all(),
        ];
    }

    public function update(array $data, ?UploadedFile $logo, int $userId): array
    {
        $old = $this->configuration();
        $next = ['fields' => [], 'logo' => $old['logo'], 'custom_fields' => array_values($data['custom_fields'] ?? [])];
        foreach (self::FIELD_KEYS as $key) {
            $next['fields'][$key] = [
                'value' => trim((string) ($data['fields'][$key]['value'] ?? '')),
                'visible' => (bool) ($data['fields'][$key]['visible'] ?? false),
                'order' => (int) ($data['fields'][$key]['order'] ?? 0),
            ];
        }
        $next['logo']['visible'] = (bool) ($data['logo_visible'] ?? false);
        $next['logo']['order'] = (int) ($data['logo_order'] ?? 1);
        if ($data['remove_logo'] ?? false) $next['logo']['asset_id'] = null;
        if ($logo) $next['logo']['asset_id'] = $this->storeLogo($logo, $userId);

        DB::transaction(function () use ($old, $next, $userId) {
            DB::table('application_settings')->updateOrInsert(['key' => self::SETTING_KEY], [
                'value' => json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'value_type' => 'json',
                'description' => 'Configurable business identity used by print documents.', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'user_id' => $userId, 'table_name' => 'application_settings', 'model_name' => self::class,
                'record_id' => 0, 'action' => 'update', 'action_type' => 'print_identity_update',
                'old_data' => json_encode($old, JSON_UNESCAPED_UNICODE), 'new_data' => json_encode($next, JSON_UNESCAPED_UNICODE),
                'old_values' => json_encode($old, JSON_UNESCAPED_UNICODE), 'new_values' => json_encode($next, JSON_UNESCAPED_UNICODE), 'created_at' => now(),
            ]);
        });
        return $this->editable();
    }

    public function editable(): array
    {
        $configuration = $this->configuration();
        $configuration['logo']['url'] = $configuration['logo']['asset_id'] ? '/api/print-identity/logo/'.$configuration['logo']['asset_id'] : null;
        unset($configuration['logo']['asset_id']);
        return $configuration;
    }

    public function currentSnapshot(): array
    {
        $configuration = $this->configuration();
        $rows = [];
        foreach ($configuration['fields'] as $key => $field) {
            if ($field['visible'] && $field['value'] !== '') $rows[] = ['key' => $key, 'value' => $field['value'], 'order' => $field['order']];
        }
        foreach ($configuration['custom_fields'] as $index => $field) {
            if ($field['visible'] && $field['label'] !== '' && $field['value'] !== '') $rows[] = ['key' => 'custom_'.$index, 'label' => $field['label'], 'value' => $field['value'], 'order' => $field['order']];
        }
        usort($rows, fn ($a, $b) => $a['order'] <=> $b['order']);
        return [
            'business_name' => collect($rows)->firstWhere('key', 'business_name')['value'] ?? null,
            'business_name_secondary' => collect($rows)->firstWhere('key', 'business_name_secondary')['value'] ?? null,
            'logo_asset_id' => $configuration['logo']['visible'] ? $configuration['logo']['asset_id'] : null,
            'fields' => $rows,
        ];
    }

    public function capture(string $documentType, int $documentId): void
    {
        DB::table('document_print_identity_snapshots')->insertOrIgnore([
            'document_type' => $documentType, 'document_id' => $documentId,
            'identity' => json_encode($this->currentSnapshot(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'captured_at' => now(),
        ]);
    }

    public function resolve(string $documentType, int $documentId): array
    {
        $stored = DB::table('document_print_identity_snapshots')->where(['document_type' => $documentType, 'document_id' => $documentId])->value('identity');
        $identity = $stored ? json_decode($stored, true) : $this->currentSnapshot();
        $identity['logo_url'] = ($identity['logo_asset_id'] ?? null) ? '/api/print-identity/logo/'.$identity['logo_asset_id'] : null;
        unset($identity['logo_asset_id']);
        $identity['source'] = $stored ? 'document_snapshot' : 'current_legacy_fallback';
        return $identity;
    }

    public function currentPrintable(): array
    {
        $identity = $this->currentSnapshot();
        $identity['logo_url'] = ($identity['logo_asset_id'] ?? null) ? '/api/print-identity/logo/'.$identity['logo_asset_id'] : null;
        unset($identity['logo_asset_id']);
        $identity['source'] = 'current_settings';
        return $identity;
    }

    public function asset(string $id): ?object
    {
        return DB::table('print_identity_assets')->where('id', $id)->first();
    }

    private function storeLogo(UploadedFile $logo, int $userId): string
    {
        $id = (string) Str::uuid();
        $extension = match ($logo->getMimeType()) {'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'};
        $key = $logo->storeAs('print-identity', "$id.$extension", 'local');
        DB::table('print_identity_assets')->insert([
            'id' => $id, 'disk' => 'local', 'storage_key' => $key, 'mime_type' => $logo->getMimeType(),
            'size' => $logo->getSize(), 'checksum' => hash_file('sha256', $logo->getRealPath()), 'uploaded_by' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }
}
