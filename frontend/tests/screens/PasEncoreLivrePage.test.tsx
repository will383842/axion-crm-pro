/**
 * FINITIONS P2 — `/pas-encore-livre` ne parle plus du chantier (« lot L7 »,
 * « hors du périmètre engagé ») : il renvoie au tableau de bord avec le
 * message « Cette fonction n'existe pas encore. »
 */
import { describe, expect, it, vi } from 'vitest';
import { render } from '@testing-library/react';

const navigate = vi.fn();
const info = vi.fn<(message: string, options: { id: string }) => void>();

vi.mock('@tanstack/react-router', () => ({ useNavigate: () => navigate }));
vi.mock('sonner', () => ({ toast: { info: (message: string, options: { id: string }) => { info(message, options); } } }));

const { PasEncoreLivrePage, MESSAGE_FONCTION_ABSENTE } = await import('@/features/misc/PasEncoreLivrePage');

describe('PasEncoreLivrePage', () => {
  it('redirige vers « / » avec un message court, sans vocabulaire de chantier', () => {
    const { container } = render(<PasEncoreLivrePage />);

    expect(navigate).toHaveBeenCalledWith({ to: '/', replace: true });
    expect(info).toHaveBeenCalledWith('Cette fonction n’existe pas encore.', { id: 'fonction-absente' });
    expect(MESSAGE_FONCTION_ABSENTE).toBe('Cette fonction n’existe pas encore.');
    expect(container.textContent).toBe('');
    expect(container.textContent).not.toMatch(/lot|périmètre|livr/i);
  });
});
