import { apiClient } from './client';
import type { BalanceSheetDto, DashboardDto, IncomeStatementDto, InventoryValuationDto, LedgerReportDto, NotificationDto, ProductProfitabilityDto, ReportFilters, TrialBalanceDto } from '../types/reports';

function query(filters: ReportFilters): string { const value = new URLSearchParams(Object.entries(filters).filter(([, item]) => item).map(([key, item]) => [key, String(item)])).toString(); return value ? `?${value}` : ''; }
export const dashboardApi = { detail: (signal?: AbortSignal) => apiClient.get<DashboardDto>('dashboard', signal), notifications: (signal?: AbortSignal) => apiClient.get<NotificationDto[]>('notifications', signal) };
export const reportsApi = {
  generalLedger: (filters: ReportFilters, signal?: AbortSignal) => apiClient.get<LedgerReportDto>(`accounting/reports/general-ledger${query(filters)}`, signal),
  trialBalance: (filters: ReportFilters, signal?: AbortSignal) => apiClient.get<TrialBalanceDto>(`accounting/reports/trial-balance${query(filters)}`, signal),
  incomeStatement: (filters: ReportFilters, signal?: AbortSignal) => apiClient.get<IncomeStatementDto>(`accounting/reports/income-statement${query(filters)}`, signal),
  productProfitability: (filters: ReportFilters, signal?: AbortSignal) => apiClient.get<ProductProfitabilityDto>(`reports/product-profitability${query(filters)}`, signal),
  balanceSheet: (filters: Pick<ReportFilters, 'to'>, signal?: AbortSignal) => apiClient.get<BalanceSheetDto>(`accounting/reports/balance-sheet${query(filters)}`, signal),
  inventoryValuation: (filters: Pick<ReportFilters, 'to'>, signal?: AbortSignal) => apiClient.get<InventoryValuationDto>(`accounting/reports/inventory-valuation${query(filters)}`, signal),
};
