import type { ApiSuccess } from '../types/api';
import type { PaginatedPurchasesResponse, PurchaseInvoiceDto, PurchaseListParams, PurchasePayload } from '../types/purchase';
import { apiClient } from './client';

export const purchasesApi = {
  list: (params: PurchaseListParams, signal?: AbortSignal) => {
    const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== undefined && value !== '').map(([key, value]) => [key, String(value)]));
    return apiClient.get<PaginatedPurchasesResponse>(`purchase-invoices?${query}`, signal);
  },
  detail: (id: number, signal?: AbortSignal) => apiClient.get<ApiSuccess<PurchaseInvoiceDto>>(`purchase-invoices/${id}`, signal),
  create: (payload: PurchasePayload) => apiClient.post<ApiSuccess<PurchaseInvoiceDto>>('purchase-invoices', payload),
  update: (id: number, payload: PurchasePayload) => apiClient.put<ApiSuccess<PurchaseInvoiceDto>>(`purchase-invoices/${id}`, payload),
  post: (id: number) => apiClient.post<ApiSuccess<PurchaseInvoiceDto>>(`purchase-invoices/${id}/post`),
};
