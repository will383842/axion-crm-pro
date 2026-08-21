import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * GARDE DE L'ENRÔLEMENT 2FA — audit 360, D22-001 (S0).
 *
 * Le serveur EXIGE l'enrôlement avant tout usage : tant que
 * `first_login_completed_at` est nul, toute route métier répond 403. Le seul
 * endroit qui pose ce champ est `POST /auth/2fa/confirm`.
 *
 * Or **aucun écran n'appelait `/auth/2fa/setup` ni `/auth/2fa/confirm`** — zéro
 * occurrence dans tout `frontend/src`. Le propriétaire du CRM ne pouvait donc pas
 * franchir sa première connexion : il n'y avait pas de bouton pour cela.
 *
 * Cette garde vérifie que les deux appels partent réellement, et que les codes de
 * secours sont montrés. Sans l'écran, elle ne trouve même pas le bouton.
 */

const mockGet = vi.fn();
const mockPost = vi.fn();
const mockNavigate = vi.fn();

vi.mock('@/lib/api', () => ({
  api: {
    get: (...a: unknown[]): unknown => mockGet(...a),
    post: (...a: unknown[]): unknown => mockPost(...a),
  },
}));

vi.mock('@tanstack/react-router', () => ({
  useNavigate: () => mockNavigate,
}));

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));

describe('Enrôlement de la double authentification', () => {
  beforeEach(() => {
    mockGet.mockReset();
    mockPost.mockReset();
    mockNavigate.mockReset();
  });

  it("propose d'activer la 2FA quand le compte ne l'a pas encore, et va jusqu'aux codes de secours", async () => {
    // Le serveur dit : ce compte n'a pas de 2FA.
    mockGet.mockResolvedValue({ data: { user: { totp_enabled_at: null } } });
    mockPost.mockImplementation((url: string) => {
      if (url === '/auth/2fa/setup') {
        return Promise.resolve({ data: { secret: 'JBSWY3DPEHPK3PXP', qr_url: 'otpauth://totp/Axion' } });
      }
      if (url === '/auth/2fa/confirm') {
        return Promise.resolve({ data: { recovery_codes: ['AAAA111111', 'BBBB222222'] } });
      }
      return Promise.reject(new Error('appel inattendu : ' + url));
    });

    const { TwoFactorPage } = await import('@/features/auth/TwoFactorPage');
    render(<TwoFactorPage />);

    // 1. Il existe un moyen de commencer. C'est précisément ce qui manquait.
    const commencer = await screen.findByRole('button', { name: /commencer/i });
    await userEvent.click(commencer);

    // 2. La clé est montrée, donc l'utilisateur peut l'entrer dans son application.
    await waitFor(() => expect(mockPost).toHaveBeenCalledWith('/auth/2fa/setup'));
    expect(await screen.findByText('JBSWY3DPEHPK3PXP')).toBeTruthy();

    // 3. La confirmation part avec le code saisi.
    await userEvent.type(screen.getByLabelText(/code à 6 chiffres/i), '123456');
    await userEvent.click(screen.getByRole('button', { name: /activer/i }));
    await waitFor(() =>
      expect(mockPost).toHaveBeenCalledWith('/auth/2fa/confirm', { code: '123456' }),
    );

    // 4. Les codes de secours s'affichent — une seule fois, et on les voit.
    expect(await screen.findByText('AAAA111111')).toBeTruthy();
    expect(screen.getByText('BBBB222222')).toBeTruthy();
  });

  it('demande simplement le code quand la 2FA est déjà active', async () => {
    mockGet.mockResolvedValue({ data: { user: { totp_enabled_at: '2026-08-19T10:00:00Z' } } });
    mockPost.mockResolvedValue({ data: { verified: true } });

    const { TwoFactorPage } = await import('@/features/auth/TwoFactorPage');
    render(<TwoFactorPage />);

    // Pas d'enrôlement proposé à un compte déjà enrôlé.
    await waitFor(() => expect(screen.queryByRole('button', { name: /commencer/i })).toBeNull());

    await userEvent.type(await screen.findByLabelText(/code à 6 chiffres/i), '654321');
    await userEvent.click(screen.getByRole('button', { name: /auth\.twoFactor\.submit/i }));

    await waitFor(() =>
      expect(mockPost).toHaveBeenCalledWith('/auth/2fa/verify', { code: '654321' }),
    );
    expect(mockNavigate).toHaveBeenCalledWith({ to: '/' });
  });
});
