/**
 * Bloc « Contenus et accords » de la fiche d'une personne (PV-6), en LECTURE.
 * D'où vient la personne, quels guides reçus, quel accord pour de futures
 * campagnes ; l'opposition est une mise à l'écart bien visible.
 * Tout est rendu en texte (échappé par React) : aucun HTML brut.
 *
 * Lien du guide : « Voir le guide » n'apparaît que si le CRM a calculé un lien
 * (table fermée côté serveur) ET qu'il passe la seconde garde ci-dessous.
 * Nouvel onglet, `noopener noreferrer nofollow`. Jamais le lien personnel de l'e-mail.
 */
import { Card, CardTitle, StatusPill } from '@/components/ui';
import { libelleReseau } from '@/features/origines/libelles';
import type { ContenusAccords, LienGuide as LienGuideDonnee } from './types';

function date(value: string | null | undefined): string {
  if (value === null || value === undefined) return '—';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('fr-FR');
}

/** Seconde garde (navigateur) : https seulement, sans identifiant, port, paramètre ni fragment. */
function lienSur(lien: LienGuideDonnee | null | undefined): LienGuideDonnee | null {
  if (lien === null || lien === undefined || typeof lien.url !== 'string' || typeof lien.libelle !== 'string') return null;
  if (!lien.url.startsWith('https://') || /[?#@\s\\]/.test(lien.url)) return null;
  try {
    const u = new URL(lien.url);
    if (u.protocol !== 'https:' || u.port !== '' || u.username !== '' || u.password !== '' || u.search !== '' || u.hash !== '') return null;
  } catch {
    return null;
  }
  return lien;
}

function VoirLeGuide({ lien, envoye }: { lien: LienGuideDonnee; envoye?: string }) {
  const suffixe = envoye === undefined ? '' : ` envoyé le ${envoye}`;
  return (
    <a
      href={lien.url}
      target="_blank"
      rel="noopener noreferrer nofollow"
      aria-label={`Voir le guide « ${lien.libelle} »${suffixe} (nouvel onglet)`}
      data-testid="voir-le-guide"
      style={{ marginLeft: 10 }}
    >
      Voir le guide
    </a>
  );
}

/** Nom connu du guide ; à défaut l'identifiant brut, en texte. */
function NomGuide({ nom, guide }: { nom: string | null | undefined; guide: string | null }) {
  if (nom !== null && nom !== undefined && nom !== '') return <>{nom}</>;
  return guide === null ? <>—</> : <code>{guide}</code>;
}

export function ContenusAccordsCard({ bloc }: { bloc: ContenusAccords | undefined }) {
  const b = bloc;
  const vide = b === undefined || (b.origine === null && b.guides.length === 0 && b.consentements.length === 0 && !b.opposition.actif);

  // Accord pour de futures campagnes : le dernier choix (accord ou retrait).
  const accords = b === undefined ? [] : b.consentements.filter((c) => c.nature === 'optin' || c.nature === 'retrait');
  const accord = accords.length === 0 ? null : accords.reduce((a, c) => ((c.date ?? '') >= (a.date ?? '') ? c : a));

  const lienOrigine = lienSur(b?.origine?.lien);
  const liensGuides = (b?.guides ?? []).map((g) => lienSur(g.lien));
  const sansLien = b?.lien_statut === 'disponible' && ((b.origine?.guide != null && lienOrigine === null) || liensGuides.some((l) => l === null));
  const tousLiens = b?.lien_statut === 'disponible' && !sansLien && (lienOrigine !== null || liensGuides.some((l) => l !== null));

  return (
    <Card>
      <CardTitle>Contenus et accords</CardTitle>
      {b?.opposition.actif === true && (
        <div role="alert" data-testid="bandeau-opposition">
          <StatusPill tone="danger">Opposition : mise à l’écart de tout envoi</StatusPill>
          <div>Depuis le {date(b.opposition.date)}. Les données sont gardées.</div>
        </div>
      )}
      {vide || b === undefined ? (
        <p>Aucune information de contenu ou d’accord reçue pour cette personne.</p>
      ) : (
        <div>
          <p>Origine du contenu et guides reçus.</p>
          <dl>
            <div>
              <dt>Origine</dt>
              <dd>
                {b.origine === null ? (
                  '—'
                ) : (
                  <>
                    Mot « {b.origine.mot ?? '—'} » · {libelleReseau(b.origine.reseau) ?? '—'} · {b.origine.guide === null ? '—' : <code>{b.origine.guide}</code>}
                  </>
                )}
              </dd>
            </div>
            {b.origine?.guide != null && (
              <div>
                <dt>Guide d’origine</dt>
                <dd>
                  <NomGuide nom={b.origine.nom} guide={b.origine.guide} />
                  {lienOrigine !== null && <VoirLeGuide lien={lienOrigine} />}
                  <span style={{ marginLeft: 10 }}>envoyé le {date(b.origine.date)}</span>
                </dd>
              </div>
            )}
            <div>
              <dt>Guides reçus</dt>
              <dd>
                {b.guides.length === 0 ? (
                  '—'
                ) : (
                  <ul>
                    {b.guides.map((g, i) => {
                      const lien = liensGuides[i] ?? null;
                      return (
                        <li key={`${g.guide ?? ''}-${g.date ?? ''}-${i}`}>
                          <NomGuide nom={g.nom} guide={g.guide} /> · envoyé le {date(g.date)}
                          {lien !== null && <VoirLeGuide lien={lien} envoye={date(g.date)} />}
                        </li>
                      );
                    })}
                  </ul>
                )}
              </dd>
            </div>
            <div>
              <dt>Accord pour de futures campagnes</dt>
              <dd>
                {accord === null ? (
                  '—'
                ) : (
                  <>
                    {accord.nature === 'retrait' ? 'retiré' : 'donné'} le {date(accord.date)}
                    <span style={{ marginLeft: 10 }}>
                      source {accord.source ?? '—'} · texte version {accord.version ?? '—'}
                    </span>
                  </>
                )}
              </dd>
            </div>
          </dl>
          {b.lien_statut === 'eteint' && <p data-testid="lien-guide-etat">Le lien du guide n’est pas affiché pour le moment.</p>}
          {b.lien_statut === 'refuse' && <p data-testid="lien-guide-etat">Le lien du guide n’est pas disponible avec votre profil.</p>}
          {sansLien && <p data-testid="lien-guide-etat">Aucun lien disponible pour ce guide.</p>}
          {tousLiens && (
            <p data-testid="lien-guide-etat">
              C’est la version publique du guide, la même pour tout le monde : l’ouvrir ne signale rien sur la personne.
            </p>
          )}
        </div>
      )}
    </Card>
  );
}
