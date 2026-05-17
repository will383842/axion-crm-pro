import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DarkModeToggle } from '@/components/ui/DarkModeToggle';

describe('DarkModeToggle', () => {
  beforeEach(() => {
    localStorage.clear();
    document.documentElement.classList.remove('dark');
    // Polyfill matchMedia for jsdom
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      value: vi.fn().mockImplementation((query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
      })),
    });
  });

  it('renders 3 buttons : light / system / dark', () => {
    render(<DarkModeToggle />);
    expect(screen.getByRole('button', { name: /Theme light/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Theme system/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Theme dark/i })).toBeInTheDocument();
  });

  it('default theme is system', () => {
    render(<DarkModeToggle />);
    expect(screen.getByRole('button', { name: /Theme system/i })).toHaveAttribute('aria-pressed', 'true');
  });

  it('switches to dark when dark button clicked', async () => {
    const user = userEvent.setup();
    render(<DarkModeToggle />);
    await user.click(screen.getByRole('button', { name: /Theme dark/i }));
    expect(document.documentElement.classList.contains('dark')).toBe(true);
    expect(localStorage.getItem('axion-theme')).toBe('dark');
  });

  it('switches to light when light button clicked', async () => {
    const user = userEvent.setup();
    render(<DarkModeToggle />);
    await user.click(screen.getByRole('button', { name: /Theme light/i }));
    expect(document.documentElement.classList.contains('dark')).toBe(false);
    expect(localStorage.getItem('axion-theme')).toBe('light');
  });

  it('persists theme to localStorage', async () => {
    const user = userEvent.setup();
    render(<DarkModeToggle />);
    await user.click(screen.getByRole('button', { name: /Theme dark/i }));
    expect(localStorage.getItem('axion-theme')).toBe('dark');
  });
});
