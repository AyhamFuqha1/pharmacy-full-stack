export interface ApiSuccess<T> {
  success: true;
  message: string;
  data: T;
}

export interface ApiError {
  success: false;
  message: string;
  errors: Record<string, string[]>;
}

export interface PaginationMeta {
  current_page: number;
  per_page: number;
  last_page: number | null;
  total: number | null;
}

export interface PaginatedResponse<T> extends ApiSuccess<T[]> {
  meta: PaginationMeta;
}
