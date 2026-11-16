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
    'Tout est parti en réel',
    'Le message est bien parti en réel',
    'parti en réel',
    'Messages envoyés pour de vrai.',
    'Pas de souci : tout est parti en réel',
    'Aucune erreur, message parti en réel',
    'Sans erreur, les messages sont partis en réel',
    'Rien à signaler, messages envoyés pour de vrai',
    'Ne fermez pas la page, envoi réel en cours',
    'Campagne sans frein : envoi réel lancé',
    'N’attendez plus : messages envoyés en réel',
  ])('attrape « %s »', (texte) => {
    expect(PARTI_EN_REEL.test(texte)).toBe(true);
  });

  it.each([
    'Cela n’active pas l’envoi réel',
    'Aucun message ne part sans votre accord',
    'Campagne programmée',
    'Un frein est actif',
    'Envois arrêtés',
    'Le message n’est pas parti en réel',
    'Aucun message n’est parti en réel',
    'Rien n’est parti pour de vrai',
    'Jamais envoyé en réel',
    'Les messages ne sont pas envoyés pour de vrai',
  ])('laisse passer « %s »', (texte) => {
    expect(PARTI_EN_REEL.test(texte)).toBe(false);
  });
});
