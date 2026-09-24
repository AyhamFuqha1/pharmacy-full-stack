import type { ApiSuccess } from '../types/api';
import type {
  ChangePasswordPayload,
  LoginPayload,
  LoginResponseData,
  User,
} from '../types/auth';
import { apiClient } from './client';

export const authApi = {
  login: (payload: LoginPayload) =>
    apiClient.post<ApiSuccess<LoginResponseData>>('login', payload),
  currentUser: (signal?: AbortSignal) =>
    apiClient.get<ApiSuccess<User>>('user', signal),
  logout: () => apiClient.post<ApiSuccess<null>>('logout'),
  changePassword: (payload: ChangePasswordPayload) =>
    apiClient.post<ApiSuccess<null>>('change-password', payload),
};
