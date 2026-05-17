import type { ReactNode } from 'react';
import { PageHeader } from './PageHeader';

export interface PageShellProps {
  title: string;
  subtitle?: string;
  actions?: ReactNode;
  children?: ReactNode;
}

/**
 * PageShell — kept for backwards compatibility with existing 18 pages.
 * Delegates rendering to the modern PageHeader (design system 2026).
 * Prefer importing PageHeader directly for new pages.
 */
export function PageShell({ title, subtitle, actions, children }: PageShellProps) {
  return (
    <div className="px-6 py-6">
      <PageHeader title={title} subtitle={subtitle} actions={actions} gradient={false} />
      <section>{children}</section>
    </div>
  );
}
