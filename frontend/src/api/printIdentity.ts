import { apiClient } from "./client";
import type { ApiSuccess } from "../types/api";

export type PrintIdentityConfiguration = {
  fields: Record<string, { value: string; visible: boolean; order: number }>;
  logo: { url: string | null; visible: boolean; order: number };
  custom_fields: Array<{ label: string; value: string; visible: boolean; order: number }>;
};

export const printIdentityApi = {
  get: () => apiClient.get<ApiSuccess<PrintIdentityConfiguration>>("print-identity"),
  update: (body: FormData) => apiClient.post<ApiSuccess<PrintIdentityConfiguration>>("print-identity", body),
  logo: (url: string) => apiClient.downloadGet(url.replace(/^\/api\//, "")),
};
