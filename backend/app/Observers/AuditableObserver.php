<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditableObserver
{
    public function created(Model $model): void { $this->write($model, 'create', null, $model->getAttributes()); }
    public function updated(Model $model): void { $this->write($model, 'update', $model->getOriginal(), $model->getChanges()); }
    public function deleted(Model $model): void { $this->write($model, 'delete', $model->getOriginal(), null); }

    private function write(Model $model, string $action, ?array $old, ?array $new): void
    {
        if (!auth()->check()) return;
        AuditLog::query()->create([
            'user_id' => auth()->id(), 'table_name' => $model->getTable(), 'model_name' => $model::class,
            'record_id' => $model->getKey(), 'action' => $action, 'action_type' => $action,
            'old_data' => $old, 'new_data' => $new, 'old_values' => $old, 'new_values' => $new,
            'created_at' => now(),
        ]);
    }
}