export interface BranchDto { id: number; name: string; code: string; address: string | null; phone: string | null; is_active: boolean; created_at: string; updated_at: string; }
export interface BranchPayload { name: string; code: string; address: string | null; phone: string | null; is_active: boolean; }
export interface LaravelPaginator<T> { data: T[]; current_page: number; last_page: number; per_page: number; total: number; }
export interface AccountingPeriodDto { id: number; name: string; period_year: number; period_month: number; start_date: string; end_date: string; status: 'open' | 'locked' | 'closed'; created_at: string; updated_at: string; }
export interface AccountingPeriodPayload { name: string; start_date: string; end_date: string; }
export interface AdminRoleDto { id: number; name: string; }
export interface AdminUserDto { id: number; name: string; email: string; role: AdminRoleDto | null; requires_password_change: boolean; created_at: string; }
export interface CreateUserPayload { name: string; email: string; role: number; branch_id: number | null; }
export interface UpdateUserPayload { name?: string; email?: string; role?: number; }
