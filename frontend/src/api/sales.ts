import type { ApiSuccess } from '../types/api';
import type { PaginatedSalesResponse, SalesInvoiceDto, SalesListParams, SalesPayload } from '../types/sales';
import { apiClient } from './client';

export const salesApi = {
  list: (params: SalesListParams, signal?: AbortSignal) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => { if (value !== undefined && value !== '') query.set(key, String(value)); });
    return apiClient.get<PaginatedSalesResponse>(`sales-invoices?${query}`, signal);
  },
  detail: (id: number, signal?: AbortSignal) => apiClient.get<ApiSuccess<SalesInvoiceDto>>(`sales-invoices/${id}`, signal),
  create: (payload: SalesPayload) => apiClient.post<ApiSuccess<SalesInvoiceDto>>('sales-invoices', payload),
  priceHistory: (customerId: number, productId: number, unitId?: number, signal?: AbortSignal) => apiClient.get<ApiSuccess<Record<string, any>>>(`customers/${customerId}/products/${productId}/price-history${unitId ? `?unit_id=${unitId}` : ''}`, signal),
  cancel: (id: number) => apiClient.delete<ApiSuccess<{ id: number }>>(`sales-invoices/${id}/cancel`),
};
