import type { PaginatedResponse } from "./api";
export interface StockMovementDto {
  id: number;
  created_at: string;
  movement_type: string;
  quantity: number;
  quantity_in: number;
  quantity_out: number;
  source_type: string;
  source_id: number;
  source_label: string;
  user_id: number | null;
  user_name: string | null;
  product_id: number;
  product_name: string;
  batch_id: number;
  batch_number: string;
  cost_basis: string | number;
}
export type StockLedgerResponse = PaginatedResponse<StockMovementDto>;
export interface ProductReportDto {
  product_id: number;
  purchased_quantity: number;
  purchase_return_quantity: number;
  sold_quantity: number;
  sales_return_quantity: number;
  net_sold_quantity: number;
  cancelled_sale_quantity: number;
  prescription_dispensed_quantity: number;
  prescription_commercial_disposition: "non_commercial";
  current_stock: number;
  sales_revenue: number;
  cogs: number;
  gross_profit: number;
  batches: Array<{
    id: number;
    batch_number: string;
    expiry_date: string | null;
    quantity: number;
    purchase_price: string | number;
    sale_price: string | number;
  }>;
}
