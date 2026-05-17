import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QualityBadge } from '@/components/ui/QualityBadge';

describe('QualityBadge', () => {
  it('renders complete badge for score ≥ 90', () => {
    render(<QualityBadge score={92} />);
    expect(screen.getByText(/Complète/)).toBeInTheDocument();
  });

  it('renders partielle badge for score 50-89', () => {
    render(<QualityBadge score={70} />);
    expect(screen.getByText(/Partielle/)).toBeInTheDocument();
  });

  it('renders basique badge for score < 50', () => {
    render(<QualityBadge score={20} />);
    expect(screen.getByText(/Basique/)).toBeInTheDocument();
  });

  it('renders basique when no score given', () => {
    render(<QualityBadge />);
    expect(screen.getByText(/Basique/)).toBeInTheDocument();
  });

  it('renders forced badge type when prop given', () => {
    render(<QualityBadge badge="complete" score={10} />);
    expect(screen.getByText(/Complète/)).toBeInTheDocument();
  });

  it('shows score in label when score given', () => {
    render(<QualityBadge score={92} />);
    expect(screen.getByText(/92/)).toBeInTheDocument();
  });
});
