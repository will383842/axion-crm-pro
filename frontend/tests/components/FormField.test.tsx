import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FormField } from '@/components/ui/FormField';

describe('FormField', () => {
  it('renders label + input', () => {
    render(<FormField label="Email" name="email" />);
    expect(screen.getByLabelText('Email')).toBeInTheDocument();
  });

  it('shows error message + sets aria-invalid', () => {
    render(<FormField label="Email" name="email" error="Required field" />);
    expect(screen.getByRole('alert')).toHaveTextContent('Required field');
    expect(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true');
  });

  it('shows helpText when no error', () => {
    render(<FormField label="Email" name="email" helpText="We never share your email" />);
    expect(screen.getByText('We never share your email')).toBeInTheDocument();
  });

  it('hides helpText when error is present', () => {
    render(<FormField label="Email" name="email" error="Invalid" helpText="Hidden help" />);
    expect(screen.queryByText('Hidden help')).not.toBeInTheDocument();
  });

  it('renders required asterisk when required', () => {
    const { container } = render(<FormField label="Email" name="email" required />);
    expect(container.querySelector('[aria-hidden="true"]')?.textContent).toBe('*');
  });

  it('renders prefix and suffix nodes', () => {
    render(<FormField label="URL" name="url" prefix="https://" suffix=".com" />);
    expect(screen.getByText('https://')).toBeInTheDocument();
    expect(screen.getByText('.com')).toBeInTheDocument();
  });

  it('user can type in the input', async () => {
    const user = userEvent.setup();
    render(<FormField label="Name" name="name" />);
    const input = screen.getByLabelText('Name') as HTMLInputElement;
    await user.type(input, 'Alice');
    expect(input.value).toBe('Alice');
  });
});
