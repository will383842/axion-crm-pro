import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { EmptyState } from '@/components/ui/EmptyState';

describe('EmptyState', () => {
  it('renders title', () => {
    render(<EmptyState title="No data" />);
    expect(screen.getByText('No data')).toBeInTheDocument();
  });

  it('renders description', () => {
    render(<EmptyState title="No data" description="Try again later" />);
    expect(screen.getByText('Try again later')).toBeInTheDocument();
  });

  it('renders action node', () => {
    render(<EmptyState title="No data" action={<button>Create</button>} />);
    expect(screen.getByRole('button', { name: 'Create' })).toBeInTheDocument();
  });

  it('renders icon when provided', () => {
    render(<EmptyState title="No data" icon={<span data-testid="icon">📭</span>} />);
    expect(screen.getByTestId('icon')).toBeInTheDocument();
  });

  it('renders without description or action', () => {
    render(<EmptyState title="Minimal" />);
    expect(screen.queryByText('Try again later')).not.toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });
});
