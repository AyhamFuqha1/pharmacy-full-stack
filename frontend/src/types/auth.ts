export interface Role {
  id: number;
  name: string;
}

export interface Permission {
  name: string;
}

export interface User {
  id: number;
  name: string;
  email: string;
  role: Role | null;
  permissions: string[];
  requires_password_change: boolean;
}

export interface LoginPayload {
  email: string;
  password: string;
}

export interface LoginResponseData {
  token: string;
  requires_password_change: boolean;
}

export interface ChangePasswordPayload {
  current_password: string;
  new_password: string;
  new_password_confirmation: string;
}
