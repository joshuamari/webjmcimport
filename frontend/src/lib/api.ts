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

export type ImportIssue = {
  worksheet: string;
  excelRow: number;
  column: string;
  columnLetter: string;
  problem: string;
  correction: string;
  summary: string;
};

export type PreviewRow = {
  worksheet: string;
  excelRow: number;
  project: string;
  itemOfWorks: string;
  jobRequest: string;
  expectedMh: string;
  result: 'new' | 'exists' | 'error';
  details: string;
  issues: ImportIssue[];
};

export type PreviewSummary = {
  dataRows: number;
  new: number;
  exists: number;
  errors: number;
  fileProblems: number;
  attention: number;
};

export type PreviewData = {
  token: string;
  filename: string;
  group: string;
  summary: PreviewSummary;
  highlightedIssues: ImportIssue[];
  issueTotal: number;
  rows: PreviewRow[];
  page: number;
  perPage: number;
  total: number;
  filter: 'all' | 'new' | 'exists' | 'error';
  q: string;
  operationStatus?: 'ready' | 'committing' | 'committed' | string;
  committed?: { added: number; skipped: number; group?: string; filename?: string } | null;
};

export type CommitResult = {
  added: number;
  skipped: number;
  group: string;
  filename: string;
  historyId: string;
};

export type HistoryRow = {
  id: string;
  at: string;
  filename: string;
  group: string;
  added: number;
  skipped: number;
  result: string;
  importedBy: string;
};

export type HistoryDetail = HistoryRow & {
  employeeNumber?: number;
  projects?: string[];
  note?: string;
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

export function messageFrom(body: { message?: string } | null, fallback: string) {
  return body?.message || fallback;
}

export function getMe() {
  return request<{ data: MeUser }>('/me');
}

export function previewExcel(
  group: string,
  file: File,
  onUploadProgress?: (loaded: number, total: number) => void,
  signal?: AbortSignal,
) {
  const form = new FormData();
  form.append('group', group);
  form.append('file', file);

  return new Promise<ApiResult<PreviewData>>((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    let settled = false;
    const fail = (error: Error) => {
      if (settled) return;
      settled = true;
      reject(error);
    };
    const finish = (result: ApiResult<PreviewData>) => {
      if (settled) return;
      settled = true;
      resolve(result);
    };
    if (signal?.aborted) {
      fail(new Error('aborted'));
      return;
    }
    signal?.addEventListener('abort', () => xhr.abort(), { once: true });
    xhr.open('POST', `${API_BASE}/jmc/preview`);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.timeout = 120000;
    xhr.upload.onprogress = (event) => {
      if (signal?.aborted || !event.lengthComputable || !onUploadProgress) return;
      onUploadProgress(event.loaded, event.total);
    };
    xhr.onabort = () => fail(new Error('aborted'));
    xhr.onerror = () => fail(new Error('network'));
    xhr.ontimeout = () => fail(new Error('timeout'));
    xhr.onload = () => {
      if (xhr.status === 0) {
        fail(new Error('network'));
        return;
      }
      let body: ApiResult<PreviewData>['body'] = null;
      if (xhr.responseText) {
        try {
          body = JSON.parse(xhr.responseText) as ApiResult<PreviewData>['body'];
        } catch {
          body = { message: xhr.responseText };
        }
      }
      finish({ ok: xhr.status >= 200 && xhr.status < 300, status: xhr.status, body });
    };
    xhr.send(form);
  });
}

export function getPreview(token: string, filter: string, q: string, page: number) {
  const params = new URLSearchParams({
    filter,
    q,
    page: String(page),
    perPage: '20',
  });
  return request<{ data: PreviewData }>(`/jmc/preview/${token}?${params.toString()}`);
}

export function confirmImport(token: string) {
  return request<{ data: CommitResult; message?: string }>(`/jmc/preview/${token}/confirm`, {
    method: 'POST',
  });
}

export function getHistory(group?: string) {
  const query = group ? `?group=${encodeURIComponent(group)}` : '';
  return request<{ data: HistoryRow[] }>(`/jmc/history${query}`);
}

export function getHistoryDetail(id: string) {
  return request<{ data: HistoryDetail }>(`/jmc/history/${id}`);
}

export async function downloadFile(path: string, fallbackName: string) {
  const response = await fetch(`${API_BASE}${path}`, { credentials: 'include' });
  if (!response.ok) {
    let message = 'The file could not be downloaded.';
    try {
      const body = await response.json();
      message = body?.message || message;
    } catch {
      message = response.statusText || message;
    }
    throw new Error(message);
  }

  const blob = await response.blob();
  const header = response.headers.get('Content-Disposition') || '';
  const match = /filename="([^"]+)"/.exec(header);
  const name = match?.[1] || fallbackName;
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = name;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

export function downloadTemplate() {
  return downloadFile('/jmc/template', 'JMC-Import-Template.xlsx');
}

export function downloadErrorReport(token: string) {
  return downloadFile(`/jmc/preview/${token}/errors`, 'JMC-Import-Errors.xlsx');
}

export function getLoginUrl() {
  return process.env.NEXT_PUBLIC_KDT_LOGIN_URL || 'http://localhost/KDTPortalLogin/';
}

export function getJmcUrl() {
  return process.env.NEXT_PUBLIC_WEBJMR_JMC_URL || '/webJMR/JMC';
}
