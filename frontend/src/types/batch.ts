import type { PaginatedResponse } from "./api";
import type { ProductDto } from "./product";

export interface BatchDto {
  id: number;
  product_id: number;
  batch_number: string;
  expiry_date: string | null;
  purchase_price: string | number;
  sale_price: string | number;
  quantity: number;
  deleted_at: string | null;
  created_at: string;
  updated_at: string;
  product?: ProductDto | null;
}

export type PaginatedBatchesResponse = PaginatedResponse<BatchDto>;

export interface BatchListParams {
  page?: number;
  per_page?: number;
  search?: string;
  product_id?: number;
  branch_id?: number;
  sort?: "expiry_date" | "quantity" | "created_at";
  direction?: "asc" | "desc";
  archive_status?: "active" | "archived" | "all";
}

// Quantity and purchase price are deliberately absent: stock and cost are
// maintained by the purchase/inventory workflows, not direct batch edits.
export interface BatchUpdatePayload {
  product_id?: number;
  batch_number?: string;
  expiry_date?: string | null;
  sale_price?: string | number;
}
