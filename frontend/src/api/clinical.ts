import type { DispenseResultDto, PatientDto, PatientPayload, PatientsPaginator, PrescriptionDto, PrescriptionItemDto, PrescriptionPayload, PrescriptionsPaginator } from '../types/clinical';
import type { ApiSuccess } from '../types/api';
import { apiClient } from './client';
function query(params: Record<string, string | number | undefined>): string { const value = new URLSearchParams(Object.entries(params).filter(([, item]) => item !== undefined && item !== '').map(([key, item]) => [key, String(item)])).toString(); return value ? `?${value}` : ''; }
export const patientsApi = {
  list: (params: { page?: number; per_page?: number; search?: string; branch_id?: number }, signal?: AbortSignal) => apiClient.get<PatientsPaginator>(`patients${query(params)}`, signal),
  detail: (id: number, signal?: AbortSignal) => apiClient.get<PatientDto>(`patients/${id}`, signal),
  create: (payload: PatientPayload) => apiClient.post<PatientDto>('patients', payload),
  update: (id: number, payload: Partial<PatientPayload>) => apiClient.put<PatientDto>(`patients/${id}`, payload),
};
export const prescriptionsApi = {
  list: (params: { page?: number; per_page?: number; patient_id?: number; branch_id?: number; status?: string }, signal?: AbortSignal) => apiClient.get<PrescriptionsPaginator>(`prescriptions${query(params)}`, signal),
  detail: (id: number, signal?: AbortSignal) => apiClient.get<ApiSuccess<PrescriptionDto>>(`prescriptions/${id}`, signal),
  create: (payload: PrescriptionPayload) => apiClient.post<PrescriptionDto>('prescriptions', payload),
  addItem: (id: number, payload: { product_id: number; allowed_quantity: number }) => apiClient.post<PrescriptionItemDto>(`prescriptions/${id}/items`, payload),
  dispense: (id: number, payload: { product_id: number; quantity: number }) => apiClient.post<ApiSuccess<DispenseResultDto>>(`prescriptions/${id}/dispense`, payload),
};
