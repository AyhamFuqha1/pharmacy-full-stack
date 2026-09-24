import type { PaginatedResponse } from './api';

export interface CustomerDto {
  id: number;
  name: string;
  phone_num: string | null;
  city: string | null;
  dept?: string | number;
  delete_at?: string | null;
  deleted_at?: string | null;
  created_at: string;
  credit_limit?: string|number|null;
  credit_warning_threshold?: string|number|null;
  block_on_limit?: boolean;
  current_receivable?: number;
  available_credit?: number|null;
  warning?: boolean;
  exceeded?: boolean;
}

export interface ProviderDto {
  id: number;
  name: string;
  phone_num: string | null;
  city: string | null;
  dept?: string | number;
  deleted_at?: string | null;
  created_at: string;
}

export interface PartyPayload {
  name: string;
  phone_num: string;
  city: string | null;
  credit_limit?: number | null;
  credit_warning_threshold?: number | null;
  block_on_limit?: boolean;
}
export type ArchiveStatus = 'active' | 'archived' | 'all';
export interface PartyListParams { page?: number; per_page?: number; search?: string; archive_status?: ArchiveStatus; }
export type PaginatedCustomersResponse = PaginatedResponse<CustomerDto>;
export type PaginatedProvidersResponse = PaginatedResponse<ProviderDto>;

export interface StatementLineDto {
  id: number;
  journal_entry_id: number;
  account_id: number;
  side: 'debit' | 'credit';
  amount: string | number;
  description: string | null;
  entry_number: string;
  entry_date: string;
  entry_description: string | null;
  source_type: string;
  source_id: number;
  source_label?: string;
  voucher_number?: string | null;
  reference_number?: string | null;
  created_by?: number | null;
  created_by_name?: string | null;
  debit: string | number;
  credit: string | number;
  running_balance: string | number;
  code: string;
  account_name: string;
  account_type: string;
}

export interface PartyStatementDto {
  party_id: number;
  party_type: 'customer' | 'supplier';
  lines: StatementLineDto[];
  opening_balance: string | number;
  total_debit: string | number;
  total_credit: string | number;
  balance: string | number;
  period_change: string | number;
  closing_balance: string | number;
  balance_semantics: 'true_account_balance';
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
}

export interface PartyStatementFilters {
  from?: string;
  to?: string;
  transaction_type?: string;
  reference?: string;
  search?: string;
  user_id?: number;
  page?: number;
  per_page?: number;
}
