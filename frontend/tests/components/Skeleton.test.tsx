import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { Skeleton, CompaniesTableSkeleton } from '@/components/ui/Skeleton';

describe('Skeleton', () => {
  it('renders a div with default classes', () => {
    const { container } = render(<Skeleton />);
    const el = container.firstChild as HTMLElement;
    expect(el.className).toContain('animate-pulse');
    expect(el.className).toContain('rounded');
    expect(el.className).toContain('bg-slate-200');
  });

  it('appends custom className', () => {
    const { container } = render(<Skeleton className="h-32 w-32" />);
    const el = container.firstChild as HTMLElement;
    expect(el.className).toContain('h-32');
    expect(el.className).toContain('w-32');
  });
});

describe('CompaniesTableSkeleton', () => {
  it('renders 10 rows by default', () => {
    const { container } = render(<CompaniesTableSkeleton />);
    // 1 header + 10 rows
    expect(container.querySelectorAll('div.animate-pulse').length).toBe(11);
  });

  it('renders custom rows count', () => {
    const { container } = render(<CompaniesTableSkeleton rows={5} />);
    expect(container.querySelectorAll('div.animate-pulse').length).toBe(6);
  });

  it('exposes aria-busy', () => {
    const { container } = render(<CompaniesTableSkeleton />);
    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull();
  });
});
