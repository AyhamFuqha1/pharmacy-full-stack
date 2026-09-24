import type { LaravelPaginator } from './admin';
import type { PaginatedResponse } from './api';
export interface PatientDto { id: number; branch_id: number | null; full_name: string; phone_number: string | null; date_of_birth: string | null; gender: 'male' | 'female' | 'other' | null; allergies: string | null; notes: string | null; is_active: boolean; created_at: string; updated_at: string; }
export interface PatientPayload { branch_id: number | null; full_name: string; phone_number: string | null; date_of_birth: string | null; gender: PatientDto['gender']; allergies: string | null; notes: string | null; is_active: boolean; }
export interface PrescriptionDto { id: number; branch_id: number | null; patient_id?: number; patient?: { id: number; full_name: string } | null; prescriber_name: string; prescriber_license: string; prescribed_at: string; expires_at: string | null; status: 'active' | 'fulfilled' | 'expired' | 'void'; notes: string | null; items?: PrescriptionItemDto[]; created_at: string; updated_at: string; }
export interface PrescriptionItemDto { id: number; prescription_id?: number; product_id?: number; product?: { id: number; name: string; barcode: string } | null; allowed_quantity: number; dispensed_quantity: number; remaining_quantity?: number; created_at?: string; updated_at?: string; }
export interface PrescriptionPayload { branch_id: number | null; patient_id: number; prescriber_name: string; prescriber_license: string; prescribed_at: string; expires_at: string | null; notes: string | null; }
export interface DispenseAllocationDto { id: number; batch_id: number; batch_number: string; expiry_date: string | null; quantity: number; }
export interface DispenseResultDto { prescription_id: number; product_id: number; dispensed_quantity: number; remaining_quantity: number; status: PrescriptionDto['status']; allocations: DispenseAllocationDto[]; }
export type PatientsPaginator = LaravelPaginator<PatientDto>;
export type PrescriptionsPaginator = PaginatedResponse<PrescriptionDto>;
