import type { PartyStatementDto, PartyStatementFilters } from '../types/party';
import { apiClient } from './client';

function query(filters: PartyStatementFilters): string {
  const params = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') params.set(key, String(value));
  });
  const encoded = params.toString();
  return encoded ? `?${encoded}` : '';
}

export const statementsApi = {
  customer: (id: number, filters: PartyStatementFilters = {}, signal?: AbortSignal) => apiClient.get<PartyStatementDto>(`accounting/reports/customers/${id}/statement${query(filters)}`, signal),
  supplier: (id: number, filters: PartyStatementFilters = {}, signal?: AbortSignal) => apiClient.get<PartyStatementDto>(`accounting/reports/suppliers/${id}/statement${query(filters)}`, signal),
};
