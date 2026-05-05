import { create } from 'zustand';
import * as api from '../services/api';

export const useAuthStore = create((set, get) => ({
  user: null,
  workspace: null,
  plan: null,
  status: 'loading', // loading | authenticated | unauthenticated

  login: async (email, password) => {
    set({ status: 'loading' });
    try {
      const data = await api.auth.login(email, password);
      if (!data.access_token && !data.token) { set({ status: 'unauthenticated' }); return data; }
      api.setTokens(data.access_token || data.token, data.refresh_token || '');
      set({ user: data.user, status: 'authenticated' });
      return data;
    } catch (e) { set({ status: 'unauthenticated' }); throw e; }
  },

  register: async (name, email, password) => {
    const data = await api.auth.register(name, email, password);
    if (data.access_token) {
      api.setTokens(data.access_token, data.refresh_token || '');
      set({ user: data.user, status: 'authenticated' });
    }
    return data;
  },

  logout: async () => {
    await api.auth.logout();
    api.clearAuth();
    set({ user: null, workspace: null, plan: null, status: 'unauthenticated' });
  },

  bootstrap: async () => {
    set({ status: 'loading' });
    const token = api.getToken();
    if (!token) { set({ status: 'unauthenticated' }); return; }
    api.onUnauthorized(() => get().logout());
    try {
      const ws = await api.workspace.status();
      set({ user: ws.workspace, workspace: ws, plan: ws.plan, status: 'authenticated' });
    } catch (_) { set({ status: 'unauthenticated' }); api.clearAuth(); }
  },

  refreshWorkspace: async () => {
    try {
      const ws = await api.workspace.status();
      set({ workspace: ws, plan: ws.plan });
    } catch (_) {}
  },
}));
