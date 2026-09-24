import { apiClient } from './client';
import type { ApiSuccess } from '../types/api';
import type { PaginatedReturnsResponse, ReturnHistoryDto, ReturnHistoryFilters } from '../types/returns';

const query = (page: number, perPage: number, filters: ReturnHistoryFilters) => `?${new URLSearchParams(Object.entries({ page, per_page: perPage, ...filters }).filter(([, value]) => value !== undefined && value !== '').map(([key, value]) => [key, String(value)])).toString()}`;
export const returnHistoryApi = {
  salesList: (page: number, perPage: number, filters: ReturnHistoryFilters = {}, signal?: AbortSignal) => apiClient.get<PaginatedReturnsResponse>(`sales-returns${query(page, perPage, filters)}`, signal),
  salesDetail: (id: number, signal?: AbortSignal) => apiClient.get<ApiSuccess<ReturnHistoryDto>>(`sales-returns/${id}`, signal),
  purchaseList: (page: number, perPage: number, filters: ReturnHistoryFilters = {}, signal?: AbortSignal) => apiClient.get<PaginatedReturnsResponse>(`purchase-returns${query(page, perPage, filters)}`, signal),
  purchaseDetail: (id: number, signal?: AbortSignal) => apiClient.get<ApiSuccess<ReturnHistoryDto>>(`purchase-returns/${id}`, signal),
};
