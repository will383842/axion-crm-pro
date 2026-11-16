/** Message d'erreur affiché : le serveur ne parle qu'en 4xx, et en phrases simples. */
import { describe, expect, it } from 'vitest';
import { AxiosError } from 'axios';
import { messageErreur, MESSAGE_SERVEUR_GENERIQUE } from '@/features/envoi/infrastructure/composants/format';

const erreur = (status: number, message?: string) =>
  new AxiosError('x', 'ERR', undefined, undefined, { status, statusText: '', headers: {}, config: {} as never, data: message === undefined ? {} : { message } });

describe('messageErreur', () => {
  it('4xx : le message du serveur', () => {
    expect(messageErreur(erreur(422, 'Choisissez une date à venir.'))).toBe('Choisissez une date à venir.');
  });
  it('5xx : un texte générique, jamais le message du serveur', () => {
    expect(messageErreur(erreur(500, 'SQLSTATE[23000] violation'))).toBe(MESSAGE_SERVEUR_GENERIQUE);
    expect(MESSAGE_SERVEUR_GENERIQUE).toBe('Le serveur a rencontré un problème. Réessayez dans un instant.');
    expect(messageErreur(erreur(503))).toBe(MESSAGE_SERVEUR_GENERIQUE);
  });
  it('4xx sans message, ou erreur inconnue : le repli', () => {
    expect(messageErreur(erreur(409), 'repli')).toBe('repli');
    expect(messageErreur(new Error('boum'), 'repli')).toBe('repli');
  });
  it.each([
    ['En-tête If-Match manquant.', 'Cette campagne a été modifiée ailleurs. Rechargez la page.'],
    ['Clé d’idempotence déjà utilisée.', 'Cette action a déjà été envoyée. Rechargez la page.'],
    ['Clé d\'idempotence déjà utilisée.', 'Cette action a déjà été envoyée. Rechargez la page.'],
    ['Transition de campagne interdite : en_cours → brouillon.', 'Ce geste n’est pas possible dans l’état actuel de la campagne.'],
  ])('message technique « %s » → phrase simple', (technique, simple) => {
    expect(messageErreur(erreur(409, technique))).toBe(simple);
  });
});
