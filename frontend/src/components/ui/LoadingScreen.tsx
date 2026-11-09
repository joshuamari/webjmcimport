'use client';

import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Image from 'next/image';
import loadingIcon from './loading-icon.png';
import styles from './LoadingScreen.module.css';

const EXIT_MS = 200;

type LoadingScreenProps = {
  isOpen: boolean;
  title: string;
  description: string;
};

if (typeof window !== 'undefined') {
  const preload = new window.Image();
  preload.src = loadingIcon.src;
}

export function LoadingScreen({ isOpen, title, description }: LoadingScreenProps) {
  const titleId = useId();
  const descriptionId = useId();
  const panelRef = useRef<HTMLDivElement>(null);
  const previousFocus = useRef<HTMLElement | null>(null);
  const [canPortal, setCanPortal] = useState(false);
  const [rendered, setRendered] = useState(false);
  const [visible, setVisible] = useState(false);
  const [copy, setCopy] = useState({ title, description });

  if (isOpen && !rendered) {
    setRendered(true);
  }

  if (!isOpen && visible) {
    setVisible(false);
  }

  if (isOpen && (copy.title !== title || copy.description !== description)) {
    setCopy({ title, description });
  }

  useEffect(() => {
    const frame = window.requestAnimationFrame(() => setCanPortal(true));
    return () => window.cancelAnimationFrame(frame);
  }, []);

  useEffect(() => {
    if (!canPortal || !rendered || !isOpen) return;
    const frame = window.requestAnimationFrame(() => setVisible(true));
    return () => window.cancelAnimationFrame(frame);
  }, [canPortal, rendered, isOpen]);

  useEffect(() => {
    if (!rendered || isOpen) return;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const timer = window.setTimeout(() => setRendered(false), reduceMotion ? 0 : EXIT_MS);
    return () => window.clearTimeout(timer);
  }, [isOpen, rendered]);

  const setPanel = useCallback((node: HTMLDivElement | null) => {
    panelRef.current = node;
    const main = document.querySelector('main');
    if (node) {
      const active = document.activeElement;
      previousFocus.current = active instanceof HTMLElement ? active : null;
      main?.setAttribute('inert', '');
      node.focus({ preventScroll: true });
      return;
    }
    main?.removeAttribute('inert');
    focusAfterLoading(previousFocus.current);
    previousFocus.current = null;
  }, []);

  useEffect(() => {
    if (!rendered) return;
    const trapTab = (event: KeyboardEvent) => {
      if (event.key !== 'Tab') return;
      event.preventDefault();
      panelRef.current?.focus({ preventScroll: true });
    };
    document.addEventListener('keydown', trapTab);
    return () => document.removeEventListener('keydown', trapTab);
  }, [rendered]);

  if (!canPortal || !rendered) return null;

  return createPortal(
    <div
      ref={setPanel}
      className={visible ? `${styles.overlay} ${styles.overlayVisible}` : styles.overlay}
      role="status"
      aria-live="polite"
      aria-atomic="true"
      aria-labelledby={titleId}
      aria-describedby={descriptionId}
      tabIndex={-1}
    >
      <div className={styles.content}>
        <Image
          src={loadingIcon}
          alt=""
          unoptimized
          priority
          draggable={false}
          className={styles.icon}
        />
        <p id={titleId} className={styles.title}>{copy.title}</p>
        <p id={descriptionId} className={styles.description}>{copy.description}</p>
      </div>
    </div>,
    document.body,
  );
}

function focusAfterLoading(previous: HTMLElement | null) {
  if (isUsableFocusTarget(previous)) {
    previous.focus({ preventScroll: true });
    return;
  }
  const next = document.getElementById('validation-issues-heading')
    || document.getElementById('import-outcome')
    || document.getElementById('import-summary')
    || document.getElementById('upload-status')
    || document.getElementById('groupSelect');
  next?.focus();
}

function isUsableFocusTarget(element: HTMLElement | null): element is HTMLElement {
  if (!element || !document.contains(element)) return false;
  if (element === document.body || element === document.documentElement) return false;
  if (element.hasAttribute('disabled') || element.getAttribute('aria-disabled') === 'true') return false;
  if (element instanceof HTMLInputElement && element.type === 'file') return false;
  if (element.tabIndex < 0) return false;
  if (typeof element.checkVisibility === 'function' && !element.checkVisibility()) return false;
  return true;
}
