import { apiClient } from './client';
import type { ApiSuccess } from '../types/api';
import type { PrintReportResponse, ReportCatalogResponse, ReportConfiguration, ReportQueryResponse, SavedReport, SavedReportsResponse } from '../types/universalReports';

export const universalReportsApi = {
  catalog: (signal?: AbortSignal) => apiClient.get<ReportCatalogResponse>('reports/catalog', signal),
  query: (configuration: ReportConfiguration & { page?: number }, signal?: AbortSignal) => apiClient.post<ReportQueryResponse>(`reports/query${configuration.page ? `?page=${configuration.page}` : ''}`, configuration, signal),
  printData: (configuration: ReportConfiguration) => apiClient.post<PrintReportResponse>('reports/print-data', configuration),
  exportCsv: (configuration: ReportConfiguration) => apiClient.download('reports/export/csv', configuration),
  saved: (signal?: AbortSignal) => apiClient.get<SavedReportsResponse>('reports/saved', signal),
  save: (payload: { name: string; report_key: string; filters: Record<string, unknown>; sort: string; direction: string; visible_columns: string[] }) => apiClient.post<ApiSuccess<SavedReport>>('reports/saved', payload),
  executeSaved: (id: number) => apiClient.post<ReportQueryResponse>(`reports/saved/${id}/execute`),
  deleteSaved: (id: number) => apiClient.delete<ApiSuccess<null>>(`reports/saved/${id}`),
};
