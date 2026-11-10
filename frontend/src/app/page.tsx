'use client';

import { DragEvent, Fragment, KeyboardEvent, ReactNode, useCallback, useEffect, useRef, useState } from 'react';
import Image from 'next/image';
import { AlertTriangle, ArrowLeft, ChevronDown, ChevronRight, Download, FileClock, Info, Search, Upload } from 'lucide-react';
import webJmrLogo from './web-jmr-logo.png';
import {
  confirmImport,
  downloadErrorReport,
  downloadTemplate,
  getHistory,
  getHistoryDetail,
  getJmcUrl,
  getLoginUrl,
  getMe,
  getPreview,
  messageFrom,
  previewExcel,
  type CommitResult,
  type HistoryDetail,
  type HistoryRow,
  type ImportIssue,
  type MeUser,
  type PreviewData,
  type PreviewRow,
  type PreviewSummary,
} from '@/lib/api';
import { LoadingScreen } from '@/components/ui/LoadingScreen';
import styles from './page.module.css';

type Step = 'upload' | 'review' | 'success' | 'failed' | 'unknown';
type Filter = 'all' | 'new' | 'exists' | 'error';
type Notice = { tone: 'neutral' | 'error'; text: string };

const IMPORT_TOKEN_KEY = 'jmcImportToken';

export default function HomePage() {
  const [user, setUser] = useState<MeUser | null>(null);
  const [denied, setDenied] = useState<string | null>(null);
  const [group, setGroup] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [dragOver, setDragOver] = useState(false);
  const [step, setStep] = useState<Step>('upload');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [guidelinesOpen, setGuidelinesOpen] = useState(false);
  const [requirementsOpen, setRequirementsOpen] = useState(false);
  const [checkPhase, setCheckPhase] = useState<'idle' | 'uploading' | 'checking'>('idle');
  const [importSlow, setImportSlow] = useState(false);
  const [statusChecking, setStatusChecking] = useState(false);
  const [unknownProcessing, setUnknownProcessing] = useState(false);
  const [nothingSaved, setNothingSaved] = useState(false);
  const [failureDetail, setFailureDetail] = useState<string | null>(null);
  const [historyLoad, setHistoryLoad] = useState<'loading' | 'ready' | 'error'>('loading');
  const [historyDetailError, setHistoryDetailError] = useState<string | null>(null);
  const [previewRefreshing, setPreviewRefreshing] = useState(false);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const [downloadNotice, setDownloadNotice] = useState<string | null>(null);
  const [templateNotice, setTemplateNotice] = useState<string | null>(null);
  const actionLock = useRef(false);
  const uploadFinished = useRef(false);
  const checkGeneration = useRef(0);
  const historyRequest = useRef(0);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const previewAbort = useRef<AbortController | null>(null);
  const ignoreLabelClick = useRef(false);
  const confirmDialogRef = useRef<HTMLDivElement>(null);
  const busyRef = useRef(false);
  busyRef.current = busy;
  const seenToken = useRef<string | null>(null);
  const focusUploadOnShow = useRef(false);
  const [preview, setPreview] = useState<PreviewData | null>(null);
  const [filter, setFilter] = useState<Filter>('all');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [historyReload, setHistoryReload] = useState(0);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [result, setResult] = useState<CommitResult | null>(null);
  const [history, setHistory] = useState<HistoryRow[]>([]);
  const [historyDetail, setHistoryDetail] = useState<HistoryDetail | null>(null);

  useEffect(() => {
    let cancelled = false;
    getMe().then((response) => {
      if (cancelled) return;
      if (response.status === 401) {
        window.location.href = getLoginUrl();
        return;
      }
      const payload = response.body as { message?: string; data?: MeUser } | null;
      const me = payload?.data;
      if (response.status === 403 || (me && me.canImport === false)) {
        setDenied(payload?.message || 'No access in Web JMC Import');
        return;
      }
      if (!response.ok || !me) {
        setDenied(payload?.message || 'Unable to resolve user session.');
        return;
      }
      setUser(me);
      setGroup(me.groups[0] || '');
    }).catch((err: Error) => {
      if (cancelled) return;
      setDenied(err.message || 'Unable to reach API.');
    });
    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    if (!user || !group) return;
    const requestId = ++historyRequest.current;
    setHistory([]);
    setHistoryDetail(null);
    setHistoryDetailError(null);
    setHistoryLoad('loading');
    getHistory(group).then((response) => {
      if (requestId !== historyRequest.current) return;
      if (response.status === 401) {
        window.location.href = getLoginUrl();
        return;
      }
      const payload = response.body as { data?: HistoryRow[] } | null;
      if (response.ok && Array.isArray(payload?.data)) {
        setHistory(payload.data);
        setHistoryLoad('ready');
        return;
      }
      setHistoryLoad('error');
    }).catch(() => {
      if (requestId !== historyRequest.current) return;
      setHistoryLoad('error');
    });
  }, [user, group, result, historyReload]);

  const finishSuccess = useCallback((data: CommitResult) => {
    setResult(data);
    setConfirmOpen(false);
    setStep('success');
    setNotice(null);
    setUnknownProcessing(false);
    clearImportToken();
  }, []);

  const recoverImport = useCallback(async (token: string) => {
    try {
      const response = await getPreview(token, 'all', '', 1);
      const payload = response.body as { data?: PreviewData } | null;
      const data = response.ok ? payload?.data : undefined;
      if (data?.operationStatus === 'committed') {
        if (data.committed && typeof data.committed.added === 'number') {
          finishSuccess({
            added: data.committed.added,
            skipped: data.committed.skipped,
            group: data.committed.group || data.group,
            filename: data.committed.filename || data.filename,
            historyId: '',
          });
        } else {
          setConfirmOpen(false);
          setUnknownProcessing(false);
          setStep('unknown');
          setNotice(null);
        }
        return;
      }
      if (data?.operationStatus === 'committing') {
        setConfirmOpen(false);
        setUnknownProcessing(true);
        setStep('unknown');
        setNotice(null);
        return;
      }
      if (data) {
        setPreview(data);
        setConfirmOpen(false);
        setNothingSaved(true);
        setFailureDetail("We couldn't finish importing your records.");
        setStep('failed');
        setNotice(null);
        clearImportToken();
        return;
      }
    } catch {
      // The preview could not be read, so the transaction result stays unknown.
    }
    setConfirmOpen(false);
    setUnknownProcessing(false);
    setStep('unknown');
    setNotice(null);
  }, [finishSuccess]);

  useEffect(() => {
    if (!user) return;
    let token = '';
    try {
      token = sessionStorage.getItem(IMPORT_TOKEN_KEY) || '';
    } catch {
      return;
    }
    if (token) recoverImport(token);
  }, [user, recoverImport]);

  useEffect(() => {
    if (step !== 'upload' || !focusUploadOnShow.current) return;
    focusUploadOnShow.current = false;
    revealUpload();
  }, [step]);

  useEffect(() => {
    if (step !== 'unknown' || !unknownProcessing) return;
    let token = '';
    try {
      token = sessionStorage.getItem(IMPORT_TOKEN_KEY) || '';
    } catch {
      return;
    }
    if (!token) return;
    const id = window.setInterval(() => {
      recoverImport(token);
    }, 4000);
    return () => window.clearInterval(id);
  }, [step, unknownProcessing, recoverImport]);

  useEffect(() => {
    if (!busy || !confirmOpen) {
      setImportSlow(false);
      return;
    }
    const id = window.setTimeout(() => setImportSlow(true), 8000);
    return () => window.clearTimeout(id);
  }, [busy, confirmOpen]);

  useEffect(() => {
    if (!confirmOpen || busy) return;
    const dialog = confirmDialogRef.current;
    if (!dialog) return;
    const trigger = document.activeElement instanceof HTMLElement && !dialog.contains(document.activeElement)
      ? document.activeElement
      : null;
    dialog.focus({ preventScroll: true });

    const onKeyDown = (event: globalThis.KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        setConfirmOpen(false);
        return;
      }
      if (event.key !== 'Tab') return;
      const focusable = [...dialog.querySelectorAll<HTMLElement>('button:not([disabled])')];
      if (focusable.length === 0) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      const active = document.activeElement;
      if (event.shiftKey && (active === first || active === dialog)) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
      }
    };
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      if (busyRef.current) return;
      if (trigger && document.contains(trigger)) trigger.focus({ preventScroll: true });
    };
  }, [confirmOpen, busy]);

  useEffect(() => {
    if (step !== 'review' || !preview?.token) return;
    const token = preview.token;
    const generation = checkGeneration.current;
    const sameToken = seenToken.current === token;
    seenToken.current = token;
    let cancelled = false;
    const stale = () => cancelled || generation !== checkGeneration.current;
    const handle = setTimeout(() => {
      if (stale()) return;
      if (sameToken) setPreviewRefreshing(true);
      getPreview(token, filter, search, page).then((response) => {
        if (stale()) return;
        const payload = response.body as { message?: string; data?: PreviewData } | null;
        if (response.status === 401) {
          window.location.href = getLoginUrl();
          return;
        }
        if (!response.ok || !payload?.data) {
          setPreviewError('The preview could not be updated. The results shown are from the last successful check.');
          return;
        }
        setPreviewError(null);
        setPreview(payload.data);
      }).catch(() => {
        if (!stale()) {
          setPreviewError('The preview could not be updated. The results shown are from the last successful check.');
        }
      }).finally(() => {
        if (!stale()) setPreviewRefreshing(false);
      });
    }, search ? 300 : 0);
    return () => {
      cancelled = true;
      clearTimeout(handle);
    };
  }, [step, preview?.token, filter, search, page, reloadKey]);

  function chooseFile(next: File | null) {
    if (busy || actionLock.current) return;
    if (!next) return;
    const lower = next.name.toLowerCase();
    if (!lower.endsWith('.xlsx')) {
      setNotice({ tone: 'error', text: 'This file format is not supported. Please upload an Excel (.xlsx) file.' });
      return;
    }
    if (next.size > 10 * 1024 * 1024) {
      setNotice({ tone: 'error', text: 'This file cannot be uploaded. It is larger than the 10 MB limit.' });
      return;
    }
    if (!group) {
      setNotice({ tone: 'error', text: 'Select a group before choosing a file.' });
      return;
    }
    setFile(next);
    void startCheck(next, group);
  }

  function onDrop(event: DragEvent<HTMLLabelElement>) {
    event.preventDefault();
    ignoreLabelClick.current = true;
    setDragOver(false);
    if (busy || actionLock.current) return;
    chooseFile(event.dataTransfer.files?.[0] || null);
  }

  function onDropKeyDown(event: KeyboardEvent<HTMLLabelElement>) {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    event.preventDefault();
    if (busy || actionLock.current) return;
    fileInputRef.current?.click();
  }

  async function startCheck(nextFile: File, nextGroup: string) {
    if (!nextGroup || !nextFile) return;
    const generation = ++checkGeneration.current;
    previewAbort.current?.abort();
    const controller = new AbortController();
    previewAbort.current = controller;
    actionLock.current = true;
    setBusy(true);
    setCheckPhase('uploading');
    uploadFinished.current = false;
    setPreview(null);
    setResult(null);
    setConfirmOpen(false);
    setPreviewError(null);
    setStep('upload');
    setNotice({ tone: 'neutral', text: 'Uploading your Excel file...' });
    try {
      const response = await previewExcel(nextGroup, nextFile, (loaded, total) => {
        if (generation !== checkGeneration.current || total <= 0) return;
        if (loaded < total) return;
        uploadFinished.current = true;
        setCheckPhase('checking');
        setNotice({ tone: 'neutral', text: 'Checking your file for new records and errors...' });
      }, controller.signal);
      if (generation !== checkGeneration.current) return;
      const payload = response.body as { message?: string; data?: PreviewData } | null;
      if (response.status === 401) {
        window.location.href = getLoginUrl();
        return;
      }
      if (!response.ok || !payload?.data) {
        const fallback = uploadFinished.current
          ? "We couldn't check this file. Please try again or select another file."
          : "We couldn't upload your file. Please try again.";
        setPreview(null);
        setStep('upload');
        setNotice({ tone: 'error', text: userFacing(messageFrom(payload, ''), fallback) });
        return;
      }
      const attention = payload.data.summary?.attention ?? 0;
      setFilter('all');
      setSearch('');
      setPage(1);
      setPreviewError(null);
      setPreview(payload.data);
      setNotice(attention > 0
        ? { tone: 'error', text: 'Some rows need correction. Review the highlighted issues.' }
        : null);
      setStep('review');
    } catch (err) {
      if (generation !== checkGeneration.current) return;
      if (err instanceof Error && err.message === 'aborted') return;
      const interrupted = err instanceof Error && (err.message === 'network' || err.message === 'timeout');
      setPreview(null);
      setStep('upload');
      if (interrupted && !uploadFinished.current) {
        setNotice({ tone: 'error', text: "We couldn't upload your file. Please try again." });
      } else {
        setNotice({ tone: 'error', text: "We couldn't check this file. Please try again or select another file." });
      }
    } finally {
      if (generation === checkGeneration.current) {
        actionLock.current = false;
        setBusy(false);
        setCheckPhase('idle');
      }
    }
  }

  async function onConfirm() {
    if (!preview || busy || actionLock.current) return;
    actionLock.current = true;
    setBusy(true);
    setImportSlow(false);
    const token = preview.token;
    try {
      sessionStorage.setItem(IMPORT_TOKEN_KEY, token);
    } catch {
      // The page can still confirm. A refresh during the request may not be recoverable.
    }
    try {
      const response = await confirmImport(token);
      const payload = response.body as { message?: string; data?: (Partial<CommitResult> & { stale?: boolean }) | null } | null;
      if ((response.ok || response.status === 409) && payload?.data && typeof payload.data.added === 'number') {
        finishSuccess({
          added: payload.data.added,
          skipped: payload.data.skipped || 0,
          group: payload.data.group || preview.group,
          filename: payload.data.filename || preview.filename,
          historyId: payload.data.historyId || '',
        });
        return;
      }
      const safe = userFacing(payload?.message, '');
      const answered = response.status === 422 || (response.status >= 500 && safe !== '');
      if (!answered) {
        await recoverImport(token);
        return;
      }
      setConfirmOpen(false);
      if (payload?.data?.stale) {
        setReloadKey((value) => value + 1);
        setStep('review');
        setNotice({ tone: 'error', text: 'Some records need correction before you can continue.' });
        clearImportToken();
        return;
      }
      setNothingSaved(true);
      setFailureDetail(safe || "We couldn't finish importing your records.");
      setStep('failed');
      setNotice(null);
      clearImportToken();
    } catch {
      await recoverImport(token);
    } finally {
      actionLock.current = false;
      setBusy(false);
    }
  }

  async function checkImportStatus() {
    const token = storedImportToken();
    if (!token || statusChecking) return;
    setStatusChecking(true);
    try {
      await recoverImport(token);
    } finally {
      setStatusChecking(false);
    }
  }

  async function openHistory(id: string) {
    setHistoryDetailError(null);
    const response = await getHistoryDetail(id);
    const payload = response.body as { message?: string; data?: HistoryDetail } | null;
    if (!response.ok || !payload?.data) {
      setHistoryDetail(null);
      setHistoryDetailError('That import record could not be opened. Please try again.');
      return;
    }
    setHistoryDetail(payload.data);
  }

  function clearUploadSelection() {
    checkGeneration.current += 1;
    previewAbort.current?.abort();
    previewAbort.current = null;
    actionLock.current = false;
    uploadFinished.current = false;
    if (fileInputRef.current) fileInputRef.current.value = '';
    setFile(null);
    setDragOver(false);
    setBusy(false);
    setCheckPhase('idle');
    setPreview(null);
    setPreviewError(null);
    setPreviewRefreshing(false);
    setConfirmOpen(false);
    setNotice(null);
    setDownloadNotice(null);
    setFilter('all');
    setSearch('');
    setPage(1);
  }

  function resetWorkflow() {
    clearUploadSelection();
    setStep('upload');
    setResult(null);
    setFailureDetail(null);
    setNothingSaved(false);
    setUnknownProcessing(false);
    setTemplateNotice(null);
    clearImportToken();
  }

  function replaceFile() {
    if (busy || actionLock.current) return;
    fileInputRef.current?.click();
  }

  function goToUpload() {
    if (step !== 'upload') {
      setPreview(null);
      setConfirmOpen(false);
      setResult(null);
      setFailureDetail(null);
      setNothingSaved(false);
      setUnknownProcessing(false);
      setPreviewError(null);
      setStep('upload');
      setNotice(null);
      focusUploadOnShow.current = true;
      return;
    }
    revealUpload();
  }

  function leaveReview() {
    if (confirmOpen) return;
    clearUploadSelection();
    setStep('upload');
  }

  function onGroupChange(next: string) {
    if (next === group) return;
    setGroup(next);
    setHistory([]);
    setHistoryDetail(null);
    setHistoryDetailError(null);
    setHistoryLoad('loading');
    setPreview(null);
    setResult(null);
    setConfirmOpen(false);
    setPreviewError(null);
    setFilter('all');
    setSearch('');
    setPage(1);
    setStep('upload');
    if (file && next) {
      void startCheck(file, next);
      return;
    }
    setNotice(null);
  }

  async function onDownloadTemplate() {
    setTemplateNotice('Preparing the Excel template...');
    try {
      await downloadTemplate();
      setTemplateNotice('The template download has started.');
    } catch {
      setTemplateNotice('The template could not be downloaded. Please try again.');
    }
  }

  async function onDownloadErrors() {
    if (!preview) return;
    setDownloadNotice('Preparing the error report...');
    try {
      await downloadErrorReport(preview.token);
      setDownloadNotice('The error report download has started.');
    } catch {
      setDownloadNotice('The error report could not be downloaded. Please try again.');
    }
  }

  const summary = preview?.summary;
  const canImport = !!summary && summary.attention === 0 && summary.new > 0 && !busy;
  const reviewIssues = preview ? collectReviewIssues(preview) : [];
  const progressCurrent: 1 | 2 | 3 =
    step === 'success' || step === 'failed' || step === 'unknown' || (confirmOpen && busy)
      ? 3
      : step === 'review'
        ? 2
        : 1;
  const affectedRows = summary ? summary.errors : 0;
  const attentionHeading = affectedRows === 1
    ? '1 row needs attention'
    : affectedRows > 0
      ? `${affectedRows} rows need attention`
      : 'This file needs attention';
  const uploadLoading = busy && checkPhase !== 'idle';
  const importLoading = busy && confirmOpen;
  const loadingOpen = uploadLoading || importLoading || statusChecking;
  const loadingTitle = importLoading
    ? 'Adding your records...'
    : statusChecking
      ? 'Checking import status...'
      : checkPhase === 'checking'
        ? 'Checking your file...'
        : 'Uploading your file...';
  const loadingDescription = importLoading
    ? (importSlow
      ? 'Your import is still being processed. Please do not submit the same file again.'
      : 'Your new JMC entries are being saved to Web JMR.')
    : statusChecking
      ? 'We\'re checking whether this import was saved.'
      : checkPhase === 'checking'
        ? "We're checking for errors and records that already exist in Web JMR."
        : 'Your Excel file is being uploaded.';

  return (
    <main className={styles.page}>
      {denied ? (
        <section className={styles.panel}>
          <p>{denied}</p>
        </section>
      ) : null}

      {user && user.canImport ? (
        <>
          <div className={styles.pageHead}>
            <div className={styles.titleWrap}>
              <Image
                src={webJmrLogo}
                alt="Web JMR logo"
                priority
                unoptimized
                draggable={false}
                className={styles.brandLogo}
              />
              <h1 className={styles.pageTitle}>JMC Import</h1>
            </div>
            <button type="button" className={styles.outline} onClick={() => { window.location.href = getJmcUrl(); }}>
              Go to JMC
            </button>
          </div>
          <p className={styles.lead}>Add new Job Manhour Category (JMC) records to Web JMR.</p>

          <Progress current={progressCurrent} />

          <input
            id="excelFile"
            ref={fileInputRef}
            className={styles.fileInput}
            type="file"
            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            aria-label="Choose Excel File"
            tabIndex={-1}
            onChange={(event) => {
              chooseFile(event.target.files?.[0] || null);
              event.target.value = '';
            }}
            disabled={busy}
          />

          {step === 'upload' ? (
            <section className={`${styles.panel} ${styles.uploadPanel}`} id="file-upload" aria-busy={busy}>
              <div className={styles.groupBlock}>
                <label className={styles.fieldLabel} htmlFor="groupSelect">Group</label>
                <select id="groupSelect" className={styles.field} value={group} onChange={(event) => onGroupChange(event.target.value)} disabled={busy || user.groups.length === 0}>
                  {user.groups.map((item) => (
                    <option key={item} value={item}>{item}</option>
                  ))}
                </select>
              </div>

              <div className={styles.uploadSection}>
                <h2 className={styles.sectionTitle}>Upload Excel File</h2>
                <p className={styles.uploadNote}>Upload your existing JMC list or an Excel file containing only new entries.</p>
                {group ? (
                  <label
                    htmlFor="excelFile"
                    className={dragOver ? `${styles.drop} ${styles.dropActive}` : busy ? `${styles.drop} ${styles.dropBusy}` : styles.drop}
                    tabIndex={busy ? -1 : 0}
                    aria-disabled={busy}
                    onClick={(event) => {
                      if (!ignoreLabelClick.current) return;
                      event.preventDefault();
                      ignoreLabelClick.current = false;
                    }}
                    onKeyDown={onDropKeyDown}
                    onDragOver={(event) => {
                      event.preventDefault();
                      if (!busy) setDragOver(true);
                    }}
                    onDragLeave={() => setDragOver(false)}
                    onDrop={onDrop}
                  >
                    <Upload size={22} strokeWidth={1.75} className={styles.dropIcon} aria-hidden="true" />
                    <span className={styles.dropTitle}>Choose Excel File</span>
                    <p className={file ? styles.chosen : undefined}>{file ? file.name : 'No file selected'}</p>
                    {!busy ? <p>or drag and drop an .xlsx file here</p> : null}
                  </label>
                ) : (
                  <p className={styles.fileSub}>Select a group to choose a file.</p>
                )}
                {notice && !(busy && notice.tone === 'neutral') ? (
                  <div className={styles.uploadStatus} id="upload-status" tabIndex={-1}>
                    <NoticeLine notice={notice} />
                    {notice.tone === 'error' && file && !busy ? (
                      <div className={styles.formActions}>
                        <button type="button" className={styles.secondary} onClick={() => { void startCheck(file, group); }}>
                          Try Again
                        </button>
                      </div>
                    ) : null}
                  </div>
                ) : null}
              </div>

              <div className={styles.templateOffer}>
                <div>
                  <h2 className={styles.sectionTitle}>Don&apos;t have an Excel file?</h2>
                  <p className={styles.uploadNote}>Download our template and fill in your new JMC entries.</p>
                </div>
                <button type="button" className={styles.secondary} onClick={onDownloadTemplate} disabled={busy}>
                  <Download size={16} strokeWidth={1.75} aria-hidden="true" />
                  Download Excel Template
                </button>
              </div>
              {templateNotice ? (
                <p className={templateNotice.includes('could not') ? styles.statusError : styles.statusLine} role={templateNotice.includes('could not') ? 'alert' : 'status'}>
                  {templateNotice}
                </p>
              ) : null}

              <div className={styles.guidelines}>
                <button
                  type="button"
                  className={styles.guidelinesToggle}
                  aria-expanded={guidelinesOpen}
                  onClick={() => setGuidelinesOpen((open) => !open)}
                >
                  <ChevronDown size={16} strokeWidth={1.75} aria-hidden="true" className={guidelinesOpen ? styles.chevronOpen : styles.chevron} />
                  Excel File Guidelines
                </button>
                {guidelinesOpen ? (
                  <>
                    <div className={styles.guidelinesBody}>
                      <div className={styles.guidelineGroup}>
                        <h3>File &amp; Worksheet</h3>
                        <ul>
                          <li>One worksheet per project.</li>
                          <li>Name each worksheet after the project.</li>
                          <li>Keep Order No. and Budget in Charge consistent within each worksheet.</li>
                          <li>Existing JMC records will be skipped.</li>
                        </ul>
                      </div>
                      <div className={styles.guidelineGroup}>
                        <h3>Data &amp; Format</h3>
                        <ul>
                          <li>Fill in the required Item of Works and Job Request Description fields.</li>
                          <li>Use supported Excel date formats.</li>
                          <li>Enter whole numbers for applicable numeric fields.</li>
                          <li>Save the file as .xlsx.</li>
                        </ul>
                      </div>
                    </div>
                    <button
                      type="button"
                      className={styles.moreToggle}
                      aria-expanded={requirementsOpen}
                      onClick={() => setRequirementsOpen((open) => !open)}
                    >
                      {requirementsOpen ? 'Hide full Excel requirements' : 'View all Excel requirements'}
                    </button>
                    {requirementsOpen ? (
                      <div className={styles.moreRules}>
                        <ul>
                          <li>Excel worksheet names can be up to 31 characters and cannot contain : \ / ? * [ ]. The name is the project, not the Order No. or Budget in Charge.</li>
                          <li>When both Order No. and Budget in Charge are filled in, use the same pair on every row. A row with either cell blank is not compared.</li>
                          <li>Use a separate worksheet for each project. Instructions and Example are not imported. Built-in names such as OTHERS, LEAVE, MANAGEMENT, and MATERIAL LIST are skipped.</li>
                          <li>Blank rows are ignored. The header row must keep the template columns. KHI Preparing Manhour is optional.</li>
                          <li>Only Item of Works and Job Request Description must be filled on each data row. Other cells may be blank.</li>
                          <li>Dates can be an Excel date, YYYY-MM-DD, or a date such as 01/15/2026.</li>
                          <li>No. of Sheet, Expected Manhour, and KHI Preparing Manhour must be whole numbers when filled in.</li>
                          <li>Paper Size can be up to 2 characters. Order No., Budget in Charge, Item of Works, Drawing / Document Name, Job Request Description, and KHI In charge can be up to 100 characters.</li>
                          <li>The worksheet name and any filled cell cannot contain \ | ! $ % = * ? @ &apos; &lt; &gt;.</li>
                          <li>The Order No. column is not limited to a 7-digit and 4-digit pattern.</li>
                          <li>A matching project, item of works, and job request already in Web JMR is skipped and is not changed. Repeating that pair in the file needs to be corrected.</li>
                          <li>The same job request under another project is a different record. A drawing name already used by a different job request is not accepted. A Budget in Charge of CM is saved as CEM.</li>
                        </ul>
                      </div>
                    ) : null}
                  </>
                ) : null}
              </div>
            </section>
          ) : null}

          {step === 'review' && preview && summary ? (
            <>
              <section className={styles.panel}>
                <div className={styles.fileMeta}>
                  <div className={styles.fileDetails}>
                    <p className={styles.fileName}>{preview.filename}</p>
                    <p className={styles.fileMetaLine}>
                      <span className={styles.groupPair}>
                        <span className={styles.groupLabel}>Group:</span>
                        <span className={styles.groupValue}>{group}</span>
                      </span>
                      <span className={styles.rowCount}>· {summary.dataRows} {summary.dataRows === 1 ? 'row' : 'rows'} in file</span>
                    </p>
                  </div>
                  <button type="button" className={styles.secondary} onClick={replaceFile} disabled={busy}>Replace File</button>
                </div>
                {notice?.tone === 'error' ? <NoticeLine notice={notice} /> : null}
              </section>

              <section className={`${styles.panel} ${styles.results}`}>
                <h2 className={styles.sectionTitle} id="import-summary" tabIndex={-1}>Import Summary</h2>
                <div className={styles.summary}>
                  <div className={styles.metric}>
                    <span className={styles.metricCount}>{summary.new}</span>
                    <div>
                      <p className={styles.metricLabel}>New Records</p>
                      <p className={styles.metricNote}>Will be added to Web JMR</p>
                    </div>
                  </div>
                  <div className={styles.metric}>
                    <span className={styles.metricCount}>{summary.exists}</span>
                    <div>
                      <p className={styles.metricLabel}>Already Exists</p>
                      <p className={styles.metricNote}>Will be skipped</p>
                    </div>
                  </div>
                  <div className={styles.metric}>
                    <span className={styles.metricCount}>{summary.errors > 0 ? summary.errors : summary.attention}</span>
                    <div>
                      <p className={styles.metricLabel}>Needs Attention</p>
                      <p className={styles.metricNote}>
                        {summary.errors > 0
                          ? 'Rows containing validation errors'
                          : summary.attention > 0
                            ? 'Correct the file before importing'
                            : 'No problems in this file'}
                      </p>
                    </div>
                  </div>
                </div>

                {summary.attention === 0 && summary.new === 0 && summary.dataRows > 0 ? (
                  <p className={styles.quiet}>No new records found. All entries in this file already exist in Web JMR.</p>
                ) : null}

                {reviewIssues.length > 0 ? (
                  <div className={styles.subsection}>
                    <div className={styles.issuePanel} role="region" aria-labelledby="validation-issues-heading">
                      <h2 className={styles.issueHeading} id="validation-issues-heading" tabIndex={-1}>
                        <AlertTriangle size={18} strokeWidth={1.75} aria-hidden="true" />
                        {attentionHeading}
                      </h2>
                      <p className={styles.support}>Review and correct these issues in your Excel file.</p>
                      <div className={styles.issueTableWrap}>
                        <IssueTable issues={reviewIssues} />
                      </div>
                      <div className={styles.issueActions}>
                        <p className={styles.issueInstruction}>
                          <Info size={16} strokeWidth={1.75} aria-hidden="true" />
                          <span>Correct the highlighted values in your Excel file, then upload it again.</span>
                        </p>
                        <button type="button" className={styles.textButton} onClick={onDownloadErrors} disabled={busy}>
                          <Download size={16} strokeWidth={1.75} aria-hidden="true" />
                          Download Error Report
                        </button>
                      </div>
                      {downloadNotice ? (
                        <p className={downloadNotice.includes('could not') ? styles.statusError : styles.statusLine} role={downloadNotice.includes('could not') ? 'alert' : 'status'}>
                          {downloadNotice}
                        </p>
                      ) : null}
                    </div>
                  </div>
                ) : null}

                <div className={styles.subsection}>
                <div className={styles.previewIntro}>
                  <h2>Import Preview</h2>
                  <p>{summary.dataRows === 1 ? '1 record found' : `${summary.dataRows} records found`}</p>
                </div>
                {previewRefreshing ? <p className={styles.statusLine} role="status">Updating the preview...</p> : null}
                {previewError ? <p className={styles.statusError} role="alert">{previewError}</p> : null}
                <div className={styles.tools}>
                  <div className={styles.filters}>
                    {([
                      ['all', `All Rows (${summary.dataRows})`],
                      ['new', `New Records (${summary.new})`],
                      ['exists', `Already Exists (${summary.exists})`],
                      ['error', `With Error (${summary.errors})`],
                    ] as const).map(([item, label]) => (
                      <button
                        key={item}
                        type="button"
                        className={filter === item ? styles.filterOn : undefined}
                        disabled={busy}
                        onClick={() => {
                          setFilter(item);
                          setPage(1);
                        }}
                      >
                        {label}
                      </button>
                    ))}
                  </div>
                  <div className={styles.searchWrap}>
                    <span className={styles.searchIcon} aria-hidden="true"><Search size={16} strokeWidth={1.75} /></span>
                    <input
                      className={styles.search}
                      type="search"
                      aria-label="Search project, item of works, or job request"
                      placeholder="Search project, item of works, or job request..."
                      disabled={busy}
                      value={search}
                      onChange={(event) => {
                        setSearch(event.target.value);
                        setPage(1);
                      }}
                    />
                  </div>
                </div>

                {!previewRefreshing && preview.rows.length === 0 ? (
                  <EmptyState {...previewEmptyMessage(filter, search, summary)} />
                ) : (
                <div className={styles.tableWrap}>
                  <table className={`${styles.grid} ${styles.previewTable}`}>
                    <thead>
                      <tr>
                        <th>Excel Row</th>
                        <th>Project</th>
                        <th>Item of Works</th>
                        <th>Job Request Description</th>
                        <th>Expected MH</th>
                        <th>Result</th>
                        <th>Details</th>
                      </tr>
                    </thead>
                    <tbody>
                      {preview.rows.map((row) => (
                        <PreviewTableRow
                          key={`${row.worksheet}-${row.excelRow}`}
                          row={row}
                          onViewIssues={() => viewRowIssues(row)}
                        />
                      ))}
                    </tbody>
                  </table>
                </div>
                )}
                {preview.total > preview.perPage ? (
                  <div className={styles.pager}>
                    <span>{preview.total} matching {preview.total === 1 ? 'row' : 'rows'}</span>
                    <div>
                      <button type="button" disabled={preview.page <= 1} onClick={() => setPage(preview.page - 1)}>Previous</button>
                      <button type="button" disabled={preview.page * preview.perPage >= preview.total} onClick={() => setPage(preview.page + 1)}>Next</button>
                    </div>
                  </div>
                ) : null}
                </div>
                <div className={styles.footer}>
                  <button type="button" className={styles.secondary} onClick={leaveReview} disabled={confirmOpen}>
                    <ArrowLeft size={16} strokeWidth={1.75} aria-hidden="true" />
                    Back to Upload
                  </button>
                  <button type="button" className={styles.primary} disabled={!canImport || busy} onClick={() => setConfirmOpen(true)}>
                    Import {summary.new} New {summary.new === 1 ? 'Record' : 'Records'}
                  </button>
                </div>
              </section>
            </>
          ) : null}

          {step === 'success' && result ? (
            <section className={styles.panel} role="status">
              <h2 id="import-outcome" tabIndex={-1}>Import Completed</h2>
              <p>{result.added} new JMC {result.added === 1 ? 'record was' : 'records were'} successfully added to Web JMR.</p>
              <p>{result.skipped} existing {result.skipped === 1 ? 'record was' : 'records were'} skipped.</p>
              <div className={styles.formActions}>
                <button type="button" className={styles.secondary} onClick={() => resetWorkflow()}>Import Another File</button>
                <button type="button" className={styles.primary} onClick={() => scrollToHistory()}>View Import History</button>
              </div>
            </section>
          ) : null}

          {step === 'failed' ? (
            <section className={styles.panel} role="alert">
              <h2 id="import-outcome" tabIndex={-1}>Import Not Completed</h2>
              <p>We couldn&apos;t finish importing your records.</p>
              {nothingSaved ? <p>No JMC records were added.</p> : null}
              {failureDetail && failureDetail !== "We couldn't finish importing your records." ? <p>{failureDetail}</p> : null}
              {preview && preview.issueTotal > 0 ? (
                <ul className={styles.alertList}>
                  {preview.highlightedIssues.slice(0, 5).map((issue, index) => (
                    <li key={`${issue.worksheet}-${issue.excelRow}-${issue.column}-${index}`}>{issueLine(issue)}</li>
                  ))}
                </ul>
              ) : null}
              <div className={styles.formActions}>
                {preview && summary && summary.attention === 0 && summary.new > 0 ? (
                  <button type="button" className={styles.primary} onClick={() => { setStep('review'); setConfirmOpen(true); }} disabled={busy}>
                    Retry Import
                  </button>
                ) : null}
                <button type="button" className={styles.secondary} onClick={() => resetWorkflow()}>Back to Upload</button>
              </div>
            </section>
          ) : null}

          {step === 'unknown' ? (
            <section className={styles.panel} role="status">
              <h2 id="import-outcome" tabIndex={-1}>Import status not confirmed</h2>
              <p>We couldn&apos;t confirm whether your import was completed.</p>
              {unknownProcessing ? (
                <p>Please do not submit the same file again until the status is confirmed.</p>
              ) : null}
              <div className={styles.formActions}>
                {storedImportToken() ? (
                  <button type="button" className={styles.secondary} onClick={() => { void checkImportStatus(); }} disabled={statusChecking}>
                    Check Import Status
                  </button>
                ) : null}
                <button type="button" className={styles.primary} onClick={() => scrollToHistory()}>
                  View Import History
                </button>
              </div>
            </section>
          ) : null}

          {step !== 'review' ? (
            <section className={styles.history} id="import-history">
              <h2>Import History</h2>
              <p className={styles.fileSub}>Completed imports for {group}.</p>
              {historyLoad === 'loading' ? (
                <p className={styles.statusLine} role="status">Loading import history...</p>
              ) : null}
              {historyLoad === 'error' ? (
                <div role="alert">
                  <p className={styles.statusError}>Couldn&apos;t load import history.</p>
                  <button type="button" className={styles.secondary} onClick={() => setHistoryReload((value) => value + 1)}>Retry</button>
                </div>
              ) : null}
              {historyLoad === 'ready' && history.length === 0 ? (
                step === 'unknown' ? (
                  <EmptyState
                    icon={<FileClock size={28} strokeWidth={1.5} className={styles.historyIcon} aria-hidden="true" />}
                    title="No completed imports yet"
                    text={unknownProcessing
                      ? 'This import is not listed because its result has not been confirmed.'
                      : 'No import operation has been recorded for this group.'}
                  />
                ) : (
                  <EmptyState
                    icon={<FileClock size={28} strokeWidth={1.5} className={styles.historyIcon} aria-hidden="true" />}
                    title="No imports yet"
                    text="Upload your first JMC Excel file to get started."
                    action={{ label: 'Upload First File', onClick: goToUpload }}
                  />
                )
              ) : null}
              {historyLoad === 'ready' && history.length > 0 ? (
              <div className={styles.tableWrap}>
                <table className={styles.grid}>
                  <thead>
                    <tr>
                      <th>Date and Time</th>
                      <th>Filename</th>
                      <th>Group</th>
                      <th>Added Records</th>
                      <th>Skipped Records</th>
                      <th>Result</th>
                      <th>Imported By</th>
                      <th>Details</th>
                    </tr>
                  </thead>
                  <tbody>
                    {history.map((row) => (
                      <tr key={row.id}>
                        <td>{formatWhen(row.at)}</td>
                        <td>{row.filename}</td>
                        <td>{row.group}</td>
                        <td>{row.added}</td>
                        <td>{row.skipped}</td>
                        <td>{historyResultLabel(row)}</td>
                        <td>{row.importedBy}</td>
                        <td>
                          <button type="button" className={styles.textButton} onClick={() => openHistory(row.id)}>Details</button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              ) : null}
              {historyDetailError ? <p className={styles.statusError} role="alert">{historyDetailError}</p> : null}
              {historyDetail ? (
                <div className={styles.panel} style={{ marginTop: 12 }}>
                  <h2>Import details</h2>
                  <p>{formatWhen(historyDetail.at)} · {historyDetail.importedBy} · Group {historyDetail.group}</p>
                  <p>{historyDetail.added} records added. {historyDetail.skipped} records skipped.</p>
                  <p>{historyDetail.note || 'Existing JMC records were not changed.'}</p>
                  {historyDetail.projects && historyDetail.projects.length > 0 ? (
                    <p>Projects: {historyDetail.projects.join(', ')}</p>
                  ) : null}
                </div>
              ) : null}
            </section>
          ) : null}
        </>
      ) : null}

      {confirmOpen && summary && !busy ? (
        <div className={styles.backdrop}>
          <div
            className={styles.dialog}
            role="dialog"
            aria-modal="true"
            aria-labelledby="confirmTitle"
            aria-describedby="confirmDescription"
            ref={confirmDialogRef}
            tabIndex={-1}
          >
            <h2 id="confirmTitle" className={styles.dialogTitle}>Confirm Import</h2>
            <p id="confirmDescription" className={styles.dialogLead}>Please review the import details before continuing.</p>
            <section className={styles.importSummary} aria-labelledby="confirmSummaryTitle">
              <h3 id="confirmSummaryTitle">Import Summary</h3>
              <dl className={styles.summaryList}>
                <div className={styles.summaryRow}>
                  <dt>New records</dt>
                  <dd>{recordCount(summary.new)}</dd>
                </div>
                <div className={styles.summaryRow}>
                  <dt>Skipped records</dt>
                  <dd>{recordCount(summary.exists)}</dd>
                </div>
                <div className={styles.summaryRow}>
                  <dt>Group</dt>
                  <dd>{preview?.group || group}</dd>
                </div>
              </dl>
            </section>
            <p className={styles.dialogNote}>
              <Info size={16} strokeWidth={1.75} aria-hidden="true" />
              <span>Existing records in Web JMR will not be changed.</span>
            </p>
            <div className={styles.dialogActions}>
              <button type="button" className={styles.secondary} onClick={() => setConfirmOpen(false)} disabled={busy}>Cancel</button>
              <button type="button" className={styles.primary} onClick={onConfirm} disabled={busy}>
                Confirm Import
              </button>
            </div>
          </div>
        </div>
      ) : null}

      <LoadingScreen
        isOpen={loadingOpen}
        title={loadingTitle}
        description={loadingDescription}
      />
    </main>
  );
}

function Progress({ current }: { current: 1 | 2 | 3 }) {
  const steps = ['Upload File', 'Review & Confirm', 'Import'];
  return (
    <div className={styles.progress} aria-label="Import progress">
      {steps.map((label, index) => {
        const number = index + 1;
        const active = number === current;
        const done = number < current;
        const className = active
          ? `${styles.step} ${styles.stepActive}`
          : done
            ? `${styles.step} ${styles.stepDone}`
            : styles.step;
        return (
          <span key={label} className={className} aria-current={active ? 'step' : undefined}>
            {index > 0 ? <span className={styles.connector} /> : null}
            <span className={styles.circle}>{number}</span>
            <span>{label}</span>
          </span>
        );
      })}
    </div>
  );
}

function PreviewTableRow({ row, onViewIssues }: { row: PreviewRow; onViewIssues: () => void }) {
  const pill = row.result === 'new' ? styles.pillNew : row.result === 'error' ? styles.pillError : styles.pill;
  const label = row.result === 'new' ? 'New' : row.result === 'exists' ? 'Already Exists' : 'Error';
  return (
    <tr className={row.result === 'error' ? styles.errorRow : undefined}>
      <td>{row.excelRow}</td>
      <td>{row.project}</td>
      <td>{row.itemOfWorks}</td>
      <td className={styles.wrapCell}>{row.jobRequest}</td>
      <td>{row.expectedMh}</td>
      <td><span className={`${styles.pill} ${pill}`}>{label}</span></td>
      <td className={styles.wrapCell}>
        {row.result === 'new' ? '—' : row.result === 'exists' ? 'Already in Web JMR.' : row.issues.length > 0 ? (
          <button type="button" className={styles.textButton} onClick={onViewIssues}>
            View issues
            <ChevronRight size={16} strokeWidth={1.75} aria-hidden="true" />
          </button>
        ) : 'Needs attention'}
      </td>
    </tr>
  );
}

function IssueTable({ issues }: { issues: ImportIssue[] }) {
  const groups: ImportIssue[][] = [];
  issues.forEach((issue) => {
    const last = groups[groups.length - 1];
    if (last && last[0].worksheet === issue.worksheet && last[0].excelRow === issue.excelRow) {
      last.push(issue);
    } else {
      groups.push([issue]);
    }
  });

  return (
    <table className={styles.issueTable}>
      <colgroup>
        <col className={styles.issueColRow} />
        <col className={styles.issueColColumn} />
        <col />
      </colgroup>
      <thead>
        <tr>
          <th>Excel Row</th>
          <th>Column</th>
          <th>Issue</th>
        </tr>
      </thead>
      <tbody>
        {groups.map((group) => (
          <Fragment key={`${group[0].worksheet}-${group[0].excelRow}`}>
            {group.map((issue, index) => (
              <tr
                key={`${issue.column}-${issue.columnLetter}-${index}`}
                id={index === 0 ? issueAnchorId(issue.worksheet, issue.excelRow) : undefined}
                data-issue-row={issueAnchorId(issue.worksheet, issue.excelRow)}
                tabIndex={index === 0 ? -1 : undefined}
                className={index === 0 ? styles.issueGroupStart : undefined}
              >
                {index === 0 ? (
                  <td className={styles.issueRowCell} rowSpan={group.length}>
                    {issue.excelRow > 0 ? <span className={styles.rowBadge}>{issue.excelRow}</span> : '—'}
                  </td>
                ) : null}
                <td className={styles.issueColumnCell}>{displayColumn(issue.column, issue.columnLetter)}</td>
                <td className={styles.issueTextCell}>{issue.problem}</td>
              </tr>
            ))}
          </Fragment>
        ))}
      </tbody>
    </table>
  );
}

function previewEmptyMessage(filter: Filter, search: string, summary: PreviewSummary) {
  if (search.trim() !== '') {
    return {
      title: 'No matching records found',
      text: 'Try a different search term.',
    };
  }
  if (filter === 'new') {
    if (summary.errors > 0 && summary.exists === 0) {
      return {
        title: 'No new records found',
        text: 'The entries in this file need correction.',
      };
    }
    if (summary.exists > 0 && summary.errors === 0) {
      return {
        title: 'No new records found',
        text: 'All entries in this file already exist in Web JMR.',
      };
    }
    if (summary.errors > 0 && summary.exists > 0) {
      return {
        title: 'No new records found',
        text: 'All entries in this file already exist or need correction.',
      };
    }
    return {
      title: 'No new records found',
      text: 'There are no new records in this file.',
    };
  }
  if (filter === 'error') {
    return {
      title: 'No errors found',
      text: 'There are no records requiring correction.',
    };
  }
  if (filter === 'exists') {
    return {
      title: 'No existing records found',
      text: 'None of the uploaded entries are already in Web JMR.',
    };
  }
  if (summary.dataRows === 0) {
    return {
      title: 'No records found',
      text: 'No new records were found in this file.',
    };
  }
  return {
    title: 'No matching records found',
    text: 'Try a different search term or filter.',
  };
}

function EmptyState({ title, text, hint, action, icon }: {
  title: string;
  text: string;
  hint?: string;
  action?: { label: string; onClick: () => void };
  icon?: ReactNode;
}) {
  return (
    <div className={action ? `${styles.empty} ${styles.emptyWithAction}` : styles.empty}>
      <div className={styles.emptyCopy}>
        {icon}
        <p className={styles.emptyTitle}>{title}</p>
        <p className={styles.emptyText}>{text}</p>
        {hint ? <p className={styles.emptyText}>{hint}</p> : null}
      </div>
      {action ? (
        <button type="button" className={styles.primary} onClick={action.onClick}>
          {action.label === 'Upload First File' ? <Upload size={16} strokeWidth={1.75} aria-hidden="true" /> : null}
          {action.label}
        </button>
      ) : null}
    </div>
  );
}

function collectReviewIssues(preview: PreviewData): ImportIssue[] {
  const seen = new Set<string>();
  const issues: ImportIssue[] = [];
  const add = (issue: ImportIssue) => {
    const key = [issue.worksheet, String(issue.excelRow), issue.column, issue.columnLetter, issue.problem].join('\u0000');
    if (seen.has(key)) return;
    seen.add(key);
    issues.push(issue);
  };
  preview.highlightedIssues.forEach(add);
  preview.rows.forEach((row) => row.issues.forEach(add));
  const sheetOrder = new Map<string, number>();
  issues.forEach((issue) => {
    if (!sheetOrder.has(issue.worksheet)) sheetOrder.set(issue.worksheet, sheetOrder.size);
  });
  return issues.sort((a, b) => {
    const sheet = (sheetOrder.get(a.worksheet) ?? 0) - (sheetOrder.get(b.worksheet) ?? 0);
    if (sheet !== 0) return sheet;
    return a.excelRow - b.excelRow;
  });
}

function displayColumn(column: string, letter: string) {
  let name = column;
  if (column.startsWith('Order No.')) name = 'Order No.';
  else if (column.startsWith('Budget in Charge')) name = 'Budget in Charge';
  return letter ? `${name} (${letter})` : name;
}

function issueAnchorId(worksheet: string, excelRow: number) {
  return `jmc-issue-${worksheet.replace(/[^a-zA-Z0-9_-]+/g, '-')}-${excelRow}`;
}

function viewRowIssues(row: PreviewRow) {
  const id = issueAnchorId(row.worksheet, row.excelRow);
  const target = document.getElementById(id);
  if (!target) return;
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
  target.focus({ preventScroll: true });
  const group = document.querySelectorAll(`[data-issue-row="${CSS.escape(id)}"]`);
  group.forEach((node) => node.classList.add(styles.issueFlash));
  window.setTimeout(() => {
    group.forEach((node) => node.classList.remove(styles.issueFlash));
  }, 1600);
}

function issueLine(issue: { excelRow: number; worksheet: string; column: string; problem: string }) {
  if (issue.excelRow > 0) return `Excel row ${issue.excelRow}, ${issue.column}: ${issue.problem}`;
  return `${issue.column}: ${issue.problem}`;
}

function historyResultLabel(row: HistoryRow) {
  if (row.result === 'Completed' && row.skipped > 0) return 'Completed with skipped records';
  if (row.result === 'Completed') return 'Completed';
  return row.result;
}

function storedImportToken() {
  if (typeof window === 'undefined') return '';
  try {
    return sessionStorage.getItem(IMPORT_TOKEN_KEY) || '';
  } catch {
    return '';
  }
}

function recordCount(count: number) {
  return `${count} ${count === 1 ? 'record' : 'records'}`;
}

function userFacing(message: string | undefined, fallback: string) {
  if (!message) return fallback;
  if (/sqlstate|stack trace|exception|vendor[\\/]|syntax error|undefined /i.test(message)) return fallback;
  return message;
}

function clearImportToken() {
  try {
    sessionStorage.removeItem(IMPORT_TOKEN_KEY);
  } catch {
    // Storage can be unavailable. The on-page result still stands.
  }
}

function NoticeLine({ notice }: { notice: Notice }) {
  const error = notice.tone === 'error';
  return (
    <p className={error ? styles.statusError : styles.statusLine} role={error ? 'alert' : 'status'}>
      {notice.text}
    </p>
  );
}

function revealUpload() {
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.getElementById('file-upload')?.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
  document.getElementById('groupSelect')?.focus({ preventScroll: true });
}

function scrollToHistory() {
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.getElementById('import-history')?.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth' });
}

function formatWhen(value: string) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString();
}

