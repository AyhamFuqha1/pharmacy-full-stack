import type { PaginatedResponse } from './api';

export type VoucherPaymentMethod = 'cash' | 'cheque';
export interface ReceiptVoucherDto { id: number; document_number?: string | null; customer_id: number; customer_name?: string; user_id: number; amount: string | number; date: string; payment_method: VoucherPaymentMethod; status: 'posted' | 'cancelled' | string; cancelled_at?: string | null; }
export interface PaymentVoucherDto { id: number; provider_id: number; provider_name?: string; user_id: number; amount: string | number; date: string; payment_method: VoucherPaymentMethod; status: 'posted' | 'cancelled' | string; cancelled_at?: string | null; }
export interface VoucherPayload { amount: number; date: string; payment_method: VoucherPaymentMethod; cheque_number?: string; bank?: string; due_date?: string; allocations?:Array<{invoice_id:number;amount:number}>; }
export interface ReceiptVoucherPayload extends VoucherPayload { customer_id: number; }
export interface PaymentVoucherPayload extends VoucherPayload { provider_id: number; }
export type PaginatedReceiptVouchersResponse = PaginatedResponse<ReceiptVoucherDto>;
export type PaginatedPaymentVouchersResponse = PaginatedResponse<PaymentVoucherDto>;

export interface VoucherFilters { page?:number; per_page?:number; id?:number; customer_id?:number; provider_id?:number; customer_search?:string; provider_search?:string; payment_method?:VoucherPaymentMethod; amount_min?:number; amount_max?:number; from?:string; to?:string; status?:'posted'|'cancelled'; }
export interface ChequeDto { id: number; cheque_number: string; check_number: string; bank: string; bank_name: string; amount: string | number; due_date: string; status: 'pending' | 'cleared' | 'bounced' | string; direction: 'incoming' | 'outgoing'; reference_type: string; reference_id: number | null; customer_id?:number|null; provider_id?:number|null; customer?:{id:number;name:string}|null; provider?:{id:number;name:string}|null; receipt_voucher_id?:number|null; payment_voucher_id?:number|null; cleared_at?:string|null; bounced_at?:string|null; created_at: string; }
export interface ChequeFilters { page?:number; per_page?:number; cheque_number?:string; bank?:string; direction?:'incoming'|'outgoing'; status?:string; customer_id?:number; provider_id?:number; due_from?:string; due_to?:string; amount_min?:number; amount_max?:number; }
export interface ChequePayload { check_number: string; bank_name: string; amount: number; due_date: string; direction: 'in' | 'out'; reference_type: string; reference_id: number | null; }
export type PaginatedChequesResponse = PaginatedResponse<ChequeDto>;

export interface SalesReturnItemPayload { invoice_id: number; product_id: number; batch_id: number; quantity: number; price: number; customer_credit_unit_amount?: number; return_to_inventory?: boolean; reason?: string | null; }
export interface PurchaseReturnItemPayload { invoice_item_id: number; batch_id: number; quantity?: number; transaction_quantity?: number; supplier_credit_unit_amount?: number; reasone?: string | null; }
export interface ReturnResult { invoice_id: number; total_return: string | number; items_count?: number; status?: boolean; message?: string; }
