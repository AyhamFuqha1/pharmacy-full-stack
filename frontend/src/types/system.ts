import type { LaravelPaginator } from './admin';
export interface AuditLogDto { id: number; user_id: number | null; table_name: string; model_name: string | null; record_id: number; action: string; action_type: string | null; old_data?: unknown; new_data?: unknown; old_values: Record<string, unknown> | null; new_values: Record<string, unknown> | null; created_at: string; timestamp: string; entity_type: string; entity_label: string; actor: { id:number; name:string; email:string } | null; }
export interface AuditSummaryRow { action_type?: string | null; table_name?: string | null; total: number; }
export interface AdvancedAuditDto { filters: Record<string, unknown>; audit_logs: LaravelPaginator<AuditLogDto>; summary: { by_action: AuditSummaryRow[]; by_table: AuditSummaryRow[] }; controlled_medicine_dispensing: { prescription_count: number; allowed_quantity: number; dispensed_quantity: number }; }
export interface AuditFilters { page?: number; per_page?: number; from?: string; to?: string; user_id?: number; table_name?: string; action_type?: string; branch_id?: number; }
export interface ReadinessCheckDto { name: string; status: 'pass' | 'warn' | 'fail'; message: string; }
export interface DeploymentReadinessDto { status: 'pass' | 'warn' | 'fail'; summary: { total_checks: number; passed: number; warnings: number; failures: number }; checks: ReadinessCheckDto[]; generated_at: string; }
export interface HealthDto { status: 'ok' | 'degraded' | string; database: 'ok' | 'unavailable' | string; }
