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
import { libelleAccord, retraitApres } from './libelleAccord';
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

/**
 * D15 — section « Accord donné » : origine (mot, réseau, guide, date) et accords (date, source, version du texte).
 * Lecture seule. Champs lus un par un : `commentaire_ref`, `post_ref` et `post_url` ne sont jamais lus.
 */
function AccordDonne({ bloc }: { bloc: ContenusAccords | undefined }) {
  const consentements = bloc?.consentements ?? [];
  const accords = consentements.filter((c) => c.nature === 'optin');
  const opposition = bloc?.opposition;
  const origine = bloc?.origine ?? null;
  return (
    <section data-testid="accord-donne" aria-label="Accord donné">
      <h4>Accord donné</h4>
      <dl>
        <div>
          <dt>Origine</dt>
          <dd>
            {origine === null ? (
              '—'
            ) : (
              <>
                Mot « {origine.mot ?? '—'} » · {libelleReseau(origine.reseau) ?? '—'} · {origine.guide === null ? '—' : <code>{origine.guide}</code>}
                {origine.guide == null && origine.date != null && (
                  <span data-testid="origine-date" style={{ marginLeft: 10 }}>
                    reçu le {date(origine.date)}
                  </span>
                )}
              </>
            )}
          </dd>
        </div>
        <div>
          <dt>Accord</dt>
          <dd>
            {accords.length === 0 ? (
              'Aucun accord enregistré'
            ) : (
              <ul>
                {accords.map((c, i) => {
                  const retrait = retraitApres(consentements, c);
                  return (
                    <li key={`${c.date ?? ''}-${c.version ?? ''}-${i}`}>
                      accord reçu le {date(c.date)} · source : {c.source ?? '—'} · version du texte : {c.version ?? '—'}
                      {retrait !== null && <span data-testid="accord-retire"> · retiré le {date(retrait.date)}</span>}
                    </li>
                  );
                })}
              </ul>
            )}
          </dd>
        </div>
        {opposition?.actif === true && (
          <div>
            <dt>Opposition</dt>
            <dd data-testid="accord-opposition">opposition enregistrée le {date(opposition.date)} : l’accord n’est plus valable</dd>
          </div>
        )}
      </dl>
    </section>
  );
}

export function ContenusAccordsCard({ bloc }: { bloc: ContenusAccords | undefined }) {
  const b = bloc;
  const vide = b === undefined || (b.origine === null && b.guides.length === 0 && b.consentements.length === 0 && !b.opposition.actif);

  // Accord pour de futures campagnes : le dernier choix, l'opposition l'emporte (CRM-OPP).
  const accord = b === undefined ? null : libelleAccord(b.consentements, b.opposition);

  // Consentements d'information (texte reçu à l'inscription) : lisibles en plus de l'accord.
  const informations = b === undefined ? [] : b.consentements.filter((c) => c.nature === 'information');

  const lienOrigine = lienSur(b?.origine?.lien);
  const liensGuides = (b?.guides ?? []).map((g) => lienSur(g.lien));
  const disponible = b?.lien_statut === 'disponible';
  // Mention « aucun lien » propre à chaque ligne qui a un identifiant mais pas de lien.
  const origineSansLien = disponible && b?.origine?.guide != null && lienOrigine === null;
  const publique = disponible && (lienOrigine !== null || liensGuides.some((l) => l !== null));

  return (
    <Card>
      <CardTitle>Contenus et accords</CardTitle>
      {b?.opposition.actif === true && (
        <div role="alert" data-testid="bandeau-opposition">
          <StatusPill tone="danger">Opposition : mise à l’écart de tout envoi</StatusPill>
          <div>Depuis le {date(b.opposition.date)}. Les données sont gardées.</div>
        </div>
      )}
      <AccordDonne bloc={b} />
      {vide || b === undefined ? (
        <p>Aucune information de contenu ou d’accord reçue pour cette personne.</p>
      ) : (
        <div>
          <p>Origine du contenu et guides reçus.</p>
          <dl>
            {b.origine?.guide != null && (
              <div>
                <dt>Guide d’origine</dt>
                <dd>
                  <NomGuide nom={b.origine.nom} guide={b.origine.guide} />
                  {lienOrigine !== null && <VoirLeGuide lien={lienOrigine} />}
                  <span style={{ marginLeft: 10 }}>envoyé le {date(b.origine.date)}</span>
                  {origineSansLien && <span style={{ marginLeft: 10 }}>Aucun lien disponible pour ce guide.</span>}
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
                          {lien === null && disponible && g.guide != null && <span style={{ marginLeft: 10 }}>Aucun lien disponible pour ce guide.</span>}
                        </li>
                      );
                    })}
                  </ul>
                )}
              </dd>
            </div>
            {informations.length > 0 && (
              <div>
                <dt>Information reçue</dt>
                <dd>
                  <ul>
                    {informations.map((c, i) => (
                      <li key={`${c.date ?? ''}-${c.version ?? ''}-${i}`}>
                        le {date(c.date)}
                        <span style={{ marginLeft: 10 }}>
                          source {c.source ?? '—'} · texte version {c.version ?? '—'}
                        </span>
                      </li>
                    ))}
                  </ul>
                </dd>
              </div>
            )}
            <div>
              <dt>Accord pour de futures campagnes</dt>
              <dd>
                {accord === null ? (
                  '—'
                ) : (
                  <>
                    <span data-testid="accord-libelle">{accord.texte}</span>
                    {accord.retenu !== null && (
                      <span style={{ marginLeft: 10 }}>
                        source {accord.retenu.source ?? '—'} · texte version {accord.retenu.version ?? '—'}
                      </span>
                    )}
                  </>
                )}
              </dd>
            </div>
          </dl>
          {b.lien_statut === 'eteint' && <p data-testid="lien-guide-etat">Le lien du guide n’est pas affiché pour le moment.</p>}
          {b.lien_statut === 'refuse' && <p data-testid="lien-guide-etat">Le lien du guide n’est pas disponible avec votre profil.</p>}
          {publique && (
            <p data-testid="lien-guide-etat">
              C’est la version publique du guide, la même pour tout le monde : l’ouvrir ne signale rien sur la personne.
            </p>
          )}
        </div>
      )}
    </Card>
  );
}
