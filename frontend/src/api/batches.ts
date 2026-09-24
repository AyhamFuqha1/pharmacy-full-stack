import type { ApiSuccess } from '../types/api';
import type { BatchDto, BatchListParams, BatchUpdatePayload, PaginatedBatchesResponse } from '../types/batch';
import { apiClient } from './client';

function queryString(params: BatchListParams): string {
  const query = new URLSearchParams();
  if (params.page) query.set('page', String(params.page));
  if (params.per_page) query.set('per_page', String(params.per_page));
  if (params.search?.trim()) query.set('search', params.search.trim());
  if (params.product_id) query.set('product_id', String(params.product_id));
  if (params.branch_id) query.set('branch_id', String(params.branch_id));
  if (params.sort) query.set('sort', params.sort);
  if (params.direction) query.set('direction', params.direction);
  if (params.archive_status) query.set('archive_status', params.archive_status);
  const value = query.toString();
  return value ? `?${value}` : '';
}

async function listAll(params: Omit<BatchListParams, 'page' | 'per_page'> = {}, signal?: AbortSignal): Promise<BatchDto[]> {
  const rows: BatchDto[] = [];
  let page = 1;
  while (true) {
    const response = await apiClient.get<PaginatedBatchesResponse>(`batch${queryString({ ...params, page, per_page: 100 })}`, signal);
    rows.push(...response.data);
    const lastPage = response.meta.last_page ?? page;
    if (page >= lastPage || response.data.length === 0) return rows;
    page += 1;
  }
}

export const batchesApi = {
  list: (params: BatchListParams, signal?: AbortSignal) =>
    apiClient.get<PaginatedBatchesResponse>(`batch${queryString(params)}`, signal),
  listAll,
  detail: (id: number, signal?: AbortSignal) => apiClient.get<ApiSuccess<BatchDto>>(`batch/${id}`, signal),
  update: (id: number, payload: BatchUpdatePayload) => apiClient.put<ApiSuccess<BatchDto>>(`batch/${id}`, payload),
  delete: (id: number) => apiClient.delete<ApiSuccess<null>>(`batch/${id}`),
  restore: (id: number) => apiClient.post<ApiSuccess<BatchDto>>(`batch/${id}/restore`),
};
