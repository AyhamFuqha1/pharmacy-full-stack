import type { AdvancedAuditDto, AuditFilters, DeploymentReadinessDto, HealthDto } from '../types/system';
import { apiClient } from './client';
function query(params: AuditFilters): string { const value = new URLSearchParams(Object.entries(params).filter(([, item]) => item !== undefined && item !== '').map(([key, item]) => [key, String(item)])).toString(); return value ? `?${value}` : ''; }
export const systemApi = {
  audit: (filters: AuditFilters, signal?: AbortSignal) => apiClient.get<AdvancedAuditDto>(`audit/reports/advanced${query(filters)}`, signal),
  readiness: (signal?: AbortSignal) => apiClient.get<DeploymentReadinessDto>('deployment/readiness', signal),
  health: (signal?: AbortSignal) => apiClient.get<HealthDto>('health', signal),
};
