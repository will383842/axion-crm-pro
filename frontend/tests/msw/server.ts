/**
 * Le serveur MSW unique de la suite unitaire.
 *
 * Démarré / rembobiné / arrêté par `tests/setup.ts`, donc AUCUN fichier de test
 * n'a à s'en occuper. On l'exporte pour les cas qui veulent empiler un handler
 * ponctuel (`server.use(...)`) sans passer par `renderScreen`.
 */
import { setupServer } from 'msw/node';
import { defaultHandlers } from './handlers';

export const server = setupServer(...defaultHandlers);
