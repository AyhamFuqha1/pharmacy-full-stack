import type { PaginatedResponse } from './api';

export interface ProductDto {
  id: number;
  name: string;
  barcode: string;
  img: string | null;
  nots: string | null;
  is_controlled: boolean;
  control_schedule: string | null;
  reorder_level: string | number;
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
}

export type PaginatedProductsResponse = PaginatedResponse<ProductDto>;

export interface ProductListParams {
  page?: number;
  per_page?: number;
  search?: string;
  sort?: 'name' | 'created_at';
  direction?: 'asc' | 'desc';
  archive_status?: 'active' | 'archived' | 'all';
  is_controlled?: boolean;
}

export interface ProductFormPayload {
  name: string;
  barcode: string;
  nots: string;
  is_controlled: boolean;
  control_schedule: string;
  reorder_level: number;
  img?: File | null;
}

export interface BarcodeBatchDto {
  id: number;
  batch_number: string;
  expiry_date: string | null;
  quantity: number;
  sale_price: string | number;
}

export interface BarcodeLookupDto {
  product: ProductDto;
  available_batches: BarcodeBatchDto[];
  available_sellable_stock: number;
  default_price: number;
  base_unit: { id:number; name:string; symbol:string; base_quantity:number; sale_price:string|number; is_base:boolean } | null;
  sale_units: Array<{id:number;name:string;symbol:string;base_quantity:number;sale_price:string|number|null;is_base:boolean}>;
  pricing_source: 'product_sale_unit'|'legacy_fefo_batch_fallback';
}
