import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { SizeCategoryBadge } from '@/components/ui/SizeCategoryBadge';

describe('SizeCategoryBadge (extended classes)', () => {
  it('applies bg-sky-100 for tpe', () => {
    const { container } = render(<SizeCategoryBadge size="tpe" />);
    expect(container.firstChild).toHaveClass('bg-sky-100');
  });

  it('applies bg-orange-100 for artisan', () => {
    const { container } = render(<SizeCategoryBadge size="artisan" />);
    expect(container.firstChild).toHaveClass('bg-orange-100');
  });

  it('applies bg-indigo-100 for pme', () => {
    const { container } = render(<SizeCategoryBadge size="pme" />);
    expect(container.firstChild).toHaveClass('bg-indigo-100');
  });

  it('applies bg-violet-100 for eti', () => {
    const { container } = render(<SizeCategoryBadge size="eti" />);
    expect(container.firstChild).toHaveClass('bg-violet-100');
  });

  it('applies bg-fuchsia-100 for grande_entreprise', () => {
    const { container } = render(<SizeCategoryBadge size="grande_entreprise" />);
    expect(container.firstChild).toHaveClass('bg-fuchsia-100');
  });

  it('applies bg-slate-100 for unknown', () => {
    const { container } = render(<SizeCategoryBadge size="zzz" />);
    expect(container.firstChild).toHaveClass('bg-slate-100');
  });

  it('renders span tag', () => {
    const { container } = render(<SizeCategoryBadge size="tpe" />);
    expect(container.firstChild?.nodeName).toBe('SPAN');
  });

  it('renders inline-flex layout', () => {
    const { container } = render(<SizeCategoryBadge size="tpe" />);
    expect(container.firstChild).toHaveClass('inline-flex');
  });
});
