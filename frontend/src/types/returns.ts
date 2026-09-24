import type { PaginatedResponse } from "./api";

export interface ReturnHistoryDto {
  id: number;
  document_number?: string | null;
  source_invoice_id: number;
  source_document_number?: string | null;
  provider_id?: number | null;
  provider_name?: string | null;
  customer_id?: number | null;
  customer_name?: string | null;
  customer?: { id: number; name: string } | null;
  branch?: { id: number; name: string; code?: string | null } | null;
  return_date?: string;
  item_count?: number;
  total_quantity?: number;
  lines?: ReturnHistoryDto[];
  totals?: {
    gross_original_return_total: number;
    customer_credit_total: number;
    return_deduction_total: number;
  };
  created_at: string;
  updated_at: string;
  quantity: number;
  price?: string | number;
  return_price?: string | number;
  cost_price?: string | number;
  total_returned_value: string | number;
  original_returnable_unit_amount?: string | number | null;
  customer_credit_unit_amount?: string | number | null;
  gross_original_return_value?: string | number;
  customer_credit_value?: string | number;
  return_deduction?: string | number;
  return_to_inventory?: boolean | null;
  adjustment_reason?: string | null;
  original_inventory_value?: string | number;
  supplier_credit_value?: string | number;
  inventory_adjustment?: string | number;
  reason: string | null;
  user_id: number | null;
  user_name: string | null;
  product_id: number;
  product_name: string;
  product_barcode?: string | null;
  unit_name?: string | null;
  batch_id: number;
  batch_number: string;
  expiry_date: string | null;
  original_line_id: number | null;
}
export type PaginatedReturnsResponse = PaginatedResponse<ReturnHistoryDto>;
export interface ReturnHistoryFilters {
  return_number?: string;
  source_document_number?: string;
  customer_id?: number;
  provider_id?: number;
  product_id?: number;
  batch_search?: string;
  from?: string;
  to?: string;
  user_id?: number;
}
