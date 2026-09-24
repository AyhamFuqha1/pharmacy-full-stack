import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { authApi } from '../api/auth';
import { ApiClientError, AUTH_UNAUTHORIZED_EVENT } from '../api/client';
import { getToken, removeToken, setToken } from '../api/tokenStorage';
import type { LoginPayload, User } from '../types/auth';
import { AuthContext, type AuthContextValue } from './AuthContext';

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  const refreshUser = useCallback(async (): Promise<User | null> => {
    if (!getToken()) {
      setUser(null);
      return null;
    }

    try {
      const response = await authApi.currentUser();
      setUser(response.data);
      return response.data;
    } catch (error) {
      if (error instanceof ApiClientError && error.status === 401) {
        removeToken();
        setUser(null);
        return null;
      }

      throw error;
    }
  }, []);

  useEffect(() => {
    let active = true;

    async function restoreSession() {
      if (!getToken()) {
        if (active) setIsLoading(false);
        return;
      }

      try {
        await refreshUser();
      } catch {
        // A transient API or browser-network failure must not leave every route
        // blocked behind the session restoration screen. Keep the token so a
        // later login/refresh can recover an otherwise valid session.
        if (active) setUser(null);
      } finally {
        if (active) setIsLoading(false);
      }
    }

    const handleUnauthorized = () => {
      setUser(null);
      setIsLoading(false);
    };

    window.addEventListener(AUTH_UNAUTHORIZED_EVENT, handleUnauthorized);
    void restoreSession();

    return () => {
      active = false;
      window.removeEventListener(AUTH_UNAUTHORIZED_EVENT, handleUnauthorized);
    };
  }, [refreshUser]);

  const login = useCallback(async (payload: LoginPayload): Promise<User> => {
    let response;
    try {
      response = await authApi.login(payload);
    } catch (error) {
      // Older deployments may still answer an already-authenticated login with
      // 409. If this browser has a valid token, recover from the authoritative user.
      if (error instanceof ApiClientError && error.status === 409 && getToken()) {
        const existingUser = await refreshUser();
        if (existingUser) return existingUser;
      }
      throw error;
    }
    setToken(response.data.token);

    const currentUser = await refreshUser();
    if (!currentUser) {
      throw new Error('The authenticated user could not be loaded.');
    }

    return currentUser;
  }, [refreshUser]);

  const logout = useCallback(async (): Promise<void> => {
    try {
      if (getToken()) await authApi.logout();
    } finally {
      removeToken();
      setUser(null);
    }
  }, []);

  const hasPermission = useCallback(
    (permission: string) => user?.permissions.includes(permission) ?? false,
    [user],
  );

  const value = useMemo<AuthContextValue>(() => ({
    user,
    isAuthenticated: user !== null,
    isLoading,
    login,
    logout,
    refreshUser,
    hasPermission,
  }), [hasPermission, isLoading, login, logout, refreshUser, user]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
