import { createContext } from 'react';
import type { LoginPayload, User } from '../types/auth';

export interface AuthContextValue {
  user: User | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  login: (payload: LoginPayload) => Promise<User>;
  logout: () => Promise<void>;
  refreshUser: () => Promise<User | null>;
  hasPermission: (permission: string) => boolean;
}

export const AuthContext = createContext<AuthContextValue | undefined>(undefined);
