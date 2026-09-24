import type { ApiSuccess, PaginationMeta, PaginatedResponse } from "./api";

export type ReportValue = string | number | boolean | null;
export type ReportRow = Record<string, ReportValue>;

export interface ReportFilterDefinition {
  key: string;
  type: string;
}
export interface ReportDefinition {
  key: string;
  label: string;
  filters: ReportFilterDefinition[];
  allowed_filters: Record<string, string>;
  required_filters?: string[];
  sortable_columns: string[];
  allowed_columns: string[];
  default_columns?: string[];
  default_direction?: "asc" | "desc";
  summary_fields: string[];
  required_permission: string;
  printable: boolean;
  exportable: boolean;
  virtual: boolean;
}
export interface ReportConfiguration {
  report_key: string;
  filters: Record<string, ReportValue>;
  columns: string[];
  sort: string;
  direction: "asc" | "desc";
  per_page?: number;
}
export interface ReportQueryData {
  rows: ReportRow[];
  meta: PaginationMeta;
  columns: string[];
  summary?: Record<string, ReportValue>;
  report: { key: string; label: string };
}
export interface PrintReportData {
  print_too_large: boolean;
  total_rows: number;
  max_rows: number;
  print_identity?: Record<string, unknown>;
  branch?: string | number | null;
  report_title?: string;
  generated_at?: string;
  generated_by?: string;
  from?: string | null;
  to?: string | null;
  filters?: Record<string, ReportValue>;
  column_definitions?: Array<{ key: string; label: string }>;
  rows: ReportRow[];
  summaries?: Record<string, ReportValue>;
  opening_balance?: ReportValue;
  closing_balance?: ReportValue;
  currency?: string;
  suggested_orientation?: "portrait" | "landscape";
}
export interface SavedReport {
  id: number;
  name: string;
  report_key: string;
  filters: string | Record<string, ReportValue>;
  sort: string | null;
  direction: "asc" | "desc" | null;
  visible_columns: string | string[];
  owner_id: number;
}
export type ReportCatalogResponse = ApiSuccess<ReportDefinition[]>;
export type ReportQueryResponse = ApiSuccess<ReportQueryData>;
export type PrintReportResponse = ApiSuccess<PrintReportData>;
export type SavedReportsResponse = PaginatedResponse<SavedReport>;
