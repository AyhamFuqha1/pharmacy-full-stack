import type { ApiSuccess } from '../types/api';
import type {
  BarcodeLookupDto,
  PaginatedProductsResponse,
  ProductDto,
  ProductFormPayload,
  ProductListParams,
} from '../types/product';
import { apiClient } from './client';

function productFormData(payload: ProductFormPayload, methodOverride?: 'PUT'): FormData {
  const data = new FormData();
  if (methodOverride) data.append('_method', methodOverride);
  data.append('name', payload.name);
  data.append('barcode', payload.barcode);
  data.append('nots', payload.nots);
  data.append('is_controlled', payload.is_controlled ? '1' : '0');
  data.append('reorder_level', String(payload.reorder_level));
  if (payload.is_controlled) data.append('control_schedule', payload.control_schedule);
  if (payload.img) data.append('img', payload.img);
  return data;
}

function queryString(params: ProductListParams): string {
  const query = new URLSearchParams();
  if (params.page) query.set('page', String(params.page));
  if (params.per_page) query.set('per_page', String(params.per_page));
  if (params.search?.trim()) query.set('search', params.search.trim());
  if (params.sort) query.set('sort', params.sort);
  if (params.direction) query.set('direction', params.direction);
  if (params.archive_status) query.set('archive_status', params.archive_status);
  if (params.is_controlled !== undefined) query.set('is_controlled', params.is_controlled ? '1' : '0');
  const value = query.toString();
  return value ? `?${value}` : '';
}

export const productsApi = {
  list: (params: ProductListParams, signal?: AbortSignal) =>
    apiClient.get<PaginatedProductsResponse>(`product${queryString(params)}`, signal),
  detail: (id: number, signal?: AbortSignal, archiveStatus?: 'active' | 'archived' | 'all') =>
    apiClient.get<ApiSuccess<ProductDto>>(`product/${id}${archiveStatus ? `?archive_status=${archiveStatus}` : ''}`, signal),
  create: (payload: ProductFormPayload) =>
    apiClient.post<ApiSuccess<ProductDto>>('product', productFormData(payload)),
  update: (id: number, payload: ProductFormPayload) => payload.img
    // PHP only populates uploaded files for POST multipart requests. Laravel's
    // method override still dispatches this request through the PUT API route.
    ? apiClient.post<ApiSuccess<ProductDto>>(`product/${id}`, productFormData(payload, 'PUT'))
    : apiClient.put<ApiSuccess<ProductDto>>(`product/${id}`, {
        name: payload.name,
        barcode: payload.barcode,
        nots: payload.nots,
        is_controlled: payload.is_controlled,
        control_schedule: payload.is_controlled ? payload.control_schedule : null,
        reorder_level: payload.reorder_level,
      }),
  delete: (id: number) => apiClient.delete<ApiSuccess<null>>(`product/${id}`),
  restore: (id: number) => apiClient.post<ApiSuccess<ProductDto>>(`product/${id}/restore`),
  lookupBarcode: (barcode: string, signal?: AbortSignal) =>
    apiClient.get<ApiSuccess<BarcodeLookupDto>>(`product/barcode/${encodeURIComponent(barcode)}`, signal),
};

export function productImageUrl(path: string | null): string | null {
  if (!path) return null;
  if (/^(https?:|data:|blob:)/i.test(path)) return path;

  const configured = (import.meta.env.VITE_API_BASE_URL as string | undefined) || '/api';
  const apiUrl = new URL(configured, window.location.origin);
  const appPath = apiUrl.pathname.replace(/\/api\/?$/, '');
  return `${apiUrl.origin}${appPath}/storage/${path.replace(/^\/+/, '')}`;
}
