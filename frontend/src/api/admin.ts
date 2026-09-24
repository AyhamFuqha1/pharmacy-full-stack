import type { AccountingPeriodDto, AccountingPeriodPayload, AdminRoleDto, AdminUserDto, BranchDto, BranchPayload, CreateUserPayload, LaravelPaginator, UpdateUserPayload } from '../types/admin';
import type { ApiSuccess, PaginatedResponse } from '../types/api';
import { apiClient } from './client';

export const branchesApi = {
  list: (page = 1, perPage = 15, signal?: AbortSignal) => apiClient.get<LaravelPaginator<BranchDto>>(`branches?page=${page}&per_page=${perPage}`, signal),
  detail: (id: number, signal?: AbortSignal) => apiClient.get<BranchDto>(`branches/${id}`, signal),
  create: (payload: BranchPayload) => apiClient.post<BranchDto>('branches', payload),
  update: (id: number, payload: Partial<BranchPayload>) => apiClient.put<BranchDto>(`branches/${id}`, payload),
};

export const accountingPeriodsApi = {
  list: (page = 1, perPage = 15, signal?: AbortSignal) => apiClient.get<PaginatedResponse<AccountingPeriodDto>>(`accounting-periods?page=${page}&per_page=${perPage}`, signal),
  detail: (id: number, signal?: AbortSignal) => apiClient.get<ApiSuccess<AccountingPeriodDto>>(`accounting-periods/${id}`, signal),
  create: (payload: AccountingPeriodPayload) => apiClient.post<ApiSuccess<AccountingPeriodDto>>('accounting-periods', payload),
  close: (id: number) => apiClient.post<ApiSuccess<AccountingPeriodDto>>(`accounting-periods/${id}/close`),
  reopen: (id: number, reason: string) => apiClient.post<ApiSuccess<AccountingPeriodDto>>(`accounting-periods/${id}/reopen`, { reason }),
};

export const usersApi = {
  list: (params: { page?: number; per_page?: number; search?: string }, signal?: AbortSignal) => { const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== undefined && value !== '').map(([key, value]) => [key, String(value)])).toString(); return apiClient.get<PaginatedResponse<AdminUserDto>>(`users?${query}`, signal); },
  detail: (id: number, signal?: AbortSignal) => apiClient.get<ApiSuccess<AdminUserDto>>(`users/${id}`, signal),
  create: (payload: CreateUserPayload) => apiClient.post<ApiSuccess<AdminUserDto>>('register', payload),
  update: (id: number, payload: UpdateUserPayload) => apiClient.put<ApiSuccess<AdminUserDto>>(`users/${id}`, payload),
  roles: (signal?: AbortSignal) => apiClient.get<ApiSuccess<AdminRoleDto[]>>('roles', signal),
};
