import type { ApiSuccess } from '../types/api';
import type { PaginatedProvidersResponse, PartyListParams, PartyPayload, ProviderDto } from '../types/party';
import { apiClient } from './client';

function query(params: PartyListParams): string {
  const value = new URLSearchParams(Object.entries(params).filter(([, item]) => item !== undefined && item !== '').map(([key, item]) => [key, String(item)])).toString();
  return value ? `?${value}` : '';
}

export const providersApi = {
  list: (params: PartyListParams, signal?: AbortSignal) => apiClient.get<PaginatedProvidersResponse>(`providers${query(params)}`, signal),
  detail: (id: number, signal?: AbortSignal, archiveStatus?: 'archived' | 'all') => apiClient.get<ApiSuccess<ProviderDto>>(`providers/${id}${archiveStatus ? `?archive_status=${archiveStatus}` : ''}`, signal),
  create: (payload: PartyPayload) => apiClient.post<ApiSuccess<null>>('providers', payload),
  update: (id: number, payload: PartyPayload) => apiClient.put<ApiSuccess<ProviderDto>>(`providers/${id}`, payload),
  delete: (id: number) => apiClient.delete<ApiSuccess<null>>(`providers/${id}`),
  restore: (id: number) => apiClient.post<ApiSuccess<ProviderDto>>(`providers/${id}/restore`),
};
