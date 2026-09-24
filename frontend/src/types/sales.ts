import type { PaginatedResponse } from './api';

export type SalesPaymentMethod = 'cash' | 'cheque' | 'visa';

export interface SalesLineDto {
  id: number;
  sales_invoice_id: number;
  batch_id: number;
  prescription_item_id: number | null;
  amount: number;
  price: string | number;
  discount: string | number;
  cost_price: string | number;
  line_total: string | number;
  invoice_discount_share: string | number;
  final_total: string | number;
  profit: string | number;
  created_at: string;
  updated_at: string;
  product?: { id: number; name: string; barcode: string };
  batch?: { id: number; batch_number: string; expiry_date: string | null };
  original_quantity: number;
  returned_quantity: number | null;
  net_quantity: number | null;
  available_return_quantity: number | null;
  return_linkage_ambiguous: boolean;
}

export interface LinkedSalesReturnDto {
  id: number;
  document_number: string | null;
  created_at: string;
  quantity: number;
  price: string | number;
  value: number;
  original_returnable_unit_amount: string | number;
  customer_credit_unit_amount: string | number;
  return_to_inventory: boolean;
  adjustment_reason: string | null;
  gross_original_value: number;
  customer_credit_value: number;
  deduction_value: number;
  item_count?: number;
}

export interface SalesInvoiceDto {
  id: number;
  document_number: string | null;
  branch_id: number | null;
  customer_id: number;
  patient_id: number | null;
  prescription_id: number | null;
  user_id: number;
  total_price: string | number;
  discount: string | number;
  profit: string | number;
  paid_amount: string | number;
  payment_method: SalesPaymentMethod;
  date: string;
  deleted_at: string | null;
  created_at?: string;
  updated_at?: string;
  items?: SalesLineDto[];
  customer?: { id: number; name: string };
  status?: string;
  cancelled?: boolean;
  return_status?: 'no_returns' | 'partially_returned' | 'fully_returned';
  linked_returns?: LinkedSalesReturnDto[];
  return_summary?: {
    original_total: number;
    return_total: number;
    gross_original_return_total: number;
    customer_credit_total: number;
    return_deduction_total: number;
    net_total: number;
    paid_settled: number;
    net_outstanding: number;
    excess_settlement: number;
  };
}

export interface SalesLinePayload {
  product_id?: number;
  barcode?: string;
  amount: number;
  price: number;
  discount: number;
  unit_id?: number;
  discount_type?: 'fixed'|'percentage';
  discount_value?: number;
  discount_reason?: string;
  override_reason?: string;
}

export interface SalesPayload {
  customer_id: number;
  date: string;
  discount: number;
  invoice_discount_type?: 'fixed'|'percentage';
  invoice_discount_value?: number;
  invoice_discount_reason?: string;
  paid_amount: number;
  payment_method: SalesPaymentMethod;
  cheque_number?: string;
  cheque_bank?: string;
  cheque_due_date?: string;
  credit_override_reason?: string;
  items: SalesLinePayload[];
}

export type PaginatedSalesResponse = PaginatedResponse<SalesInvoiceDto>;
export interface SalesListParams { page?: number; per_page?: number; document_number?: string; search?: string; product_id?: number; product_search?: string; from?: string; to?: string; status?: 'active' | 'cancelled' | 'all'; payment_method?: SalesPaymentMethod; }
