export type MeUser = {
  username: string;
  employeeNumber: number;
  groups: string[];
  canImport: boolean;
};

export type ApiResult<T> = {
  ok: boolean;
  status: number;
  body: T | { message?: string; data?: T } | null;
};

const API_BASE = process.env.NEXT_PUBLIC_API_BASE || 'http://localhost/webJmcImport/api';

async function request<T>(path: string, options?: RequestInit): Promise<ApiResult<T>> {
  const response = await fetch(`${API_BASE}${path}`, {
    credentials: 'include',
    ...options,
    headers: {
      Accept: 'application/json',
      ...(options?.headers || {}),
    },
  });

  let body: ApiResult<T>['body'] = null;
  const text = await response.text();
  if (text) {
    try {
      body = JSON.parse(text) as ApiResult<T>['body'];
    } catch {
      body = { message: text };
    }
  }

  return { ok: response.ok, status: response.status, body };
}

export function getMe() {
  return request<{ data: MeUser }>('/me');
}

export function importExcel(group: string, file: File) {
  const form = new FormData();
  form.append('group', group);
  form.append('file', file);
  return request<{
    data: {
      completedProjects: string[];
      logs: string[];
    };
    message?: string;
  }>('/jmc/import', {
    method: 'POST',
    body: form,
  });
}

export function getLoginUrl() {
  return process.env.NEXT_PUBLIC_KDT_LOGIN_URL || 'http://localhost/KDTPortalLogin/';
}

export function getJmcUrl() {
  return process.env.NEXT_PUBLIC_WEBJMR_JMC_URL || 'http://localhost/webJMR/JMC';
}
