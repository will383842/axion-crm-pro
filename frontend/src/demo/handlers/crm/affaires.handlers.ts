/**
 * Démo — Affaires. L'étape d'une affaire prolonge `lifecycle_stage` de la fiche.
 * Rien ne se supprime : une affaire perdue change d'étape et garde sa raison.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import { ETAPES, RAISONS_DE_PERTE, type ReponseAffaires } from '@/features/crm/affaires/etapes';
import { zoneAccueil } from '../../donnees/crm/affaires';
import { jourIso } from '../../horloge';
import { api, delai, erreur, repondreEtatForce } from '../../outils';

export const handlers: HttpHandler[] = [
  http.get(api('/crm/affaires'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { aujourdhui: jourIso(0), data: [] } satisfies ReponseAffaires);
    if (force) return force;
    return HttpResponse.json({ aujourdhui: jourIso(0), data: [...zoneAccueil().affaires.tous()] } satisfies ReponseAffaires);
  }),

  http.patch(api('/crm/affaires/:id'), async ({ request, params }) => {
    await delai();
    const saisie = (await request.json().catch(() => ({}))) as { etape?: unknown; raison_perte?: unknown };
    const etape = ETAPES.find((e) => e.valeur === saisie.etape)?.valeur;
    if (etape === undefined) return erreur(422, 'etape_inconnue', 'Cette étape n’existe pas.');
    const raison = typeof saisie.raison_perte === 'string' ? saisie.raison_perte : '';
    if (etape === 'perdu' && !RAISONS_DE_PERTE.includes(raison)) {
      return erreur(422, 'raison_manquante', 'Choisissez la raison de la perte.');
    }
    const { affaires } = zoneAccueil();
    const cible = affaires.trouver(String(params['id']));
    if (!cible) return erreur(404, 'affaire_introuvable', 'Cette affaire n’existe plus.');
    return HttpResponse.json(
      affaires.modifier(cible.id, {
        etape,
        raison_perte: etape === 'perdu' ? raison : null,
        chance: etape === 'gagne' ? 100 : etape === 'perdu' ? 0 : cible.chance === 100 || cible.chance === 0 ? 50 : cible.chance,
      }),
    );
  }),
];
