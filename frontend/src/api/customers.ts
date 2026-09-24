import type { ApiSuccess } from '../types/api';
import type { CustomerDto, PaginatedCustomersResponse, PartyListParams, PartyPayload } from '../types/party';
import { apiClient } from './client';

function query(params: PartyListParams): string {
  const value = new URLSearchParams(Object.entries(params).filter(([, item]) => item !== undefined && item !== '').map(([key, item]) => [key, String(item)])).toString();
  return value ? `?${value}` : '';
}

export const customersApi = {
  list: (params: PartyListParams, signal?: AbortSignal) => apiClient.get<PaginatedCustomersResponse>(`customers${query(params)}`, signal),
  detail: (id: number, signal?: AbortSignal, archiveStatus?: 'archived' | 'all') => apiClient.get<ApiSuccess<CustomerDto>>(`customers/${id}${archiveStatus ? `?archive_status=${archiveStatus}` : ''}`, signal),
  create: (payload: PartyPayload) => apiClient.post<ApiSuccess<null>>('customers', payload),
  update: (id: number, payload: PartyPayload) => apiClient.put<ApiSuccess<CustomerDto>>(`customers/${id}`, payload),
  delete: (id: number) => apiClient.delete<ApiSuccess<null>>(`customers/${id}`),
  restore: (id: number) => apiClient.post<ApiSuccess<CustomerDto>>(`customers/${id}/restore`),
};
