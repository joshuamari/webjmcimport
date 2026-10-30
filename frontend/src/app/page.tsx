'use client';

import { FormEvent, useEffect, useState } from 'react';
import {
  getJmcUrl,
  getLoginUrl,
  getMe,
  importExcel,
  type MeUser,
} from '@/lib/api';
import styles from './page.module.css';

export default function HomePage() {
  const [status, setStatus] = useState('Checking session…');
  const [user, setUser] = useState<MeUser | null>(null);
  const [denied, setDenied] = useState<string | null>(null);
  const [group, setGroup] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [log, setLog] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;

    getMe().then((result) => {
      if (cancelled) return;

      if (result.status === 401) {
        setStatus('Not signed in');
        window.location.href = getLoginUrl();
        return;
      }

      const payload = result.body as { message?: string; data?: MeUser } | null;
      const me = payload?.data;

      if (result.status === 403 || (me && me.canImport === false)) {
        setStatus('Access denied');
        setDenied(payload?.message || 'No access in Web JMC Import');
        return;
      }

      if (!result.ok || !me) {
        setStatus('Session check failed');
        setDenied(payload?.message || 'Unable to resolve user session.');
        return;
      }

      setUser(me);
      setGroup(me.groups[0] || '');
      setStatus(`${me.username} (${me.employeeNumber})`);
    }).catch((err: Error) => {
      if (cancelled) return;
      setStatus('Session check failed');
      setDenied(err.message || 'Unable to reach API.');
    });

    return () => {
      cancelled = true;
    };
  }, []);

  async function onImport(e: FormEvent) {
    e.preventDefault();
    if (!group) {
      setLog('Select a group first.');
      return;
    }
    if (!file) {
      setLog('Select an Excel .xlsx file first.');
      return;
    }

    setBusy(true);
    setLog('IMPORTING JMC EXCEL...');

    try {
      const result = await importExcel(group, file);
      const body = result.body as {
        message?: string;
        data?: { completedProjects?: string[]; logs?: string[] };
      } | null;
      const logs = body?.data?.logs || [body?.message || `HTTP ${result.status}`];
      setLog(logs.join('\n'));

      if (result.ok && body?.data?.completedProjects?.length) {
        const jmcUrl = getJmcUrl();
        if (jmcUrl) {
          window.open(jmcUrl, '_blank');
        }
      }
    } catch (err) {
      setLog(err instanceof Error ? err.message : 'Import failed.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className={styles.page}>
      <header className={styles.header}>
        <h1>Web JMC Import</h1>
        <p className={styles.muted}>{status}</p>
      </header>

      {denied ? (
        <section className={styles.panel}>
          <p className={styles.danger}>{denied}</p>
        </section>
      ) : null}

      {user && user.canImport ? (
        <section className={styles.panel}>
          <form onSubmit={onImport}>
            <label htmlFor="groupSelect">Group</label>
            <select
              id="groupSelect"
              value={group}
              onChange={(e) => setGroup(e.target.value)}
            >
              {user.groups.map((g) => (
                <option key={g} value={g}>
                  {g}
                </option>
              ))}
            </select>

            <label htmlFor="excelFile">Drawing list Excel (.xlsx)</label>
            <input
              id="excelFile"
              type="file"
              accept=".xlsx"
              onChange={(e) => setFile(e.target.files?.[0] || null)}
            />

            <button type="submit" disabled={busy}>
              {busy ? 'Importing…' : 'Import'}
            </button>
          </form>

          <h2>Log</h2>
          <pre className={styles.log}>{log}</pre>
        </section>
      ) : null}
    </main>
  );
}
