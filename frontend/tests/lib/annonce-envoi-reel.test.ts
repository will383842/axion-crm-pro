/** GARDE — le motif « message parti en réel » de la démo E2E-FIN attrape les variantes d'accord et d'ordre. */
import { describe, it, expect } from 'vitest';
import { PARTI_EN_REEL } from '../e2e/demo/annonce-envoi-reel';

describe('PARTI_EN_REEL', () => {
  it.each([
    'Le message est parti en réel',
    'Les messages sont partis en réel',
    'Messages envoyés en réel',
    'Le message a été envoyé en réel',
    'Les messages ont été envoyés pour de vrai',
    'Envoi réel activé',
    'Envois réels en cours',
    'envoi réel effectué',
    'Les courriels sont envoyés pour de vrai',
    'Message parti bien en réel',
  ])('attrape « %s »', (texte) => {
    expect(PARTI_EN_REEL.test(texte)).toBe(true);
  });

  it.each([
    'Cela n’active pas l’envoi réel',
    'Aucun message ne part sans votre accord',
    'Campagne programmée',
    'Un frein est actif',
    'Envois arrêtés',
  ])('laisse passer « %s »', (texte) => {
    expect(PARTI_EN_REEL.test(texte)).toBe(false);
  });
});
