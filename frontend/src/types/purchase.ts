import type { PaginatedResponse } from "./api";

export type PurchaseStatus = "draft" | "posted" | "cancelled" | string;

export interface PurchaseLineDto {
  id: number;
  purchase_invoice_id: number;
  product_id: number;
  batch_id: number;
  batch_number: string;
  amount: number;
  expire_date: string | null;
  purchase_price: string | number;
  selling_price: string | number;
  transaction_unit_id?: number | null;
  transaction_unit_name?: string | null;
  transaction_quantity?: string | number | null;
  transaction_unit_cost?: string | number | null;
  conversion_factor?: string | number | null;
  base_quantity?: string | number | null;
  discount: string | number;
  created_at?: string;
  updated_at?: string;
  original_quantity?: number;
  returned_quantity?: number;
  available_return_quantity?: number;
  original_transaction_quantity?: number | null;
  returned_transaction_quantity?: number | null;
  available_return_transaction_quantity?: number | null;
}

export interface PurchaseInvoiceDto {
  id: number;
  document_number: string | null;
  provider_id: number;
  user_id: number;
  updated_by?: number | null;
  amount: string | number;
  descount: string | number;
  invoice_discount: string | number;
  total_before_discount: string | number;
  total_after_discount: string | number;
  date: string;
  due_date: string | null;
  status: PurchaseStatus;
  created_at?: string;
  updated_at?: string;
  items?: PurchaseLineDto[];
}

export interface PurchaseLinePayload {
  id?: number;
  product_id: number;
  batch_number: string;
  expire_date: string | null;
  amount: number;
  unit_id?: number | null;
  purchase_price: number;
  item_discount: number;
}

export interface PurchasePayload {
  provider_id: number;
  date: string;
  due_date: string | null;
  invoice_discount: number;
  items: PurchaseLinePayload[];
}

export type PaginatedPurchasesResponse = PaginatedResponse<PurchaseInvoiceDto>;

export interface PurchaseListParams {
  page?: number;
  per_page?: number;
  document_number?: string;
  provider_id?: number;
  product_id?: number;
  from?: string;
  to?: string;
  status?: string;
}
