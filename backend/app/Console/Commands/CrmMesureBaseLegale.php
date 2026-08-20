<?php

namespace App\Console\Commands;

use App\Crm\Ingest\MesureBaseLegale;
use Illuminate\Console\Command;

/**
 * 🔴 C21-006 (S1) — MESURE DE LA JOIGNABILITE ET DE LA BASE LEGALE DES FICHES
 * PERSONNE.
 *
 * ── LE CONSTAT, TEL QUE MESURE EN PRODUCTION LE 2026-08-18 ─────────────────
 *
 *     SELECT count(*) FROM contacts;                              1 319 567
 *     … dont sans email NI phone NI linkedin_url                    909 086   (68,89 %)
 *     … dont sans legal_basis                                     1 319 567   (100,00 %)
 *
 * Deux problemes distincts, souvent confondus :
 *
 *  1. **Joignabilite.** 68,89 % des fiches ne portent aucun moyen de contact.
 *     Ce sont, pour l'essentiel, des noms de dirigeants ramenes par
 *     l'annuaire des entreprises : un patronyme, un role, rien pour ecrire.
 *     Ce n'est pas une faute en soi — c'est une donnee a caractere personnel
 *     conservee sans usage possible, ce qui est exactement ce que la
 *     minimisation (RGPD art. 5.1.c) interdit de faire sans y penser.
 *
 *  2. **Base legale.** La colonne `contacts.legal_basis` est vide sur la
 *     totalite du stock. Le CHECK SQL `contacts_legal_basis_check` (migration
 *     `2026_08_14_000002`) autorise NULL : rien n'a jamais proteste.
 *
 * ── POURQUOI CETTE COMMANDE N'ECRIT RIEN ──────────────────────────────────
 *
 * Remplir `legal_basis` retroactivement est un acte JURIDIQUE, pas technique :
 * c'est decider, deux ans apres, sous quelle base une personne a ete
 * collectee. Un `UPDATE … SET legal_basis = 'legitimate_interest_b2b'` global
 * rendrait la colonne pleine et la tracabilite inventee — une conformite de
 * facade, pire que le vide parce qu'elle ne se voit plus. Cette commande
 * MESURE, RAPPORTE, et rend un code d'echec quand le chiffre depasse un seuil
 * qu'on lui donne. Le remplissage, s'il a lieu, sera une decision ecrite du
 * responsable de traitement, source par source.
 *
 * ── CE QUE LE SEUIL SERT A VOIR ───────────────────────────────────────────
 *
 * `--seuil-sans-base` / `--seuil-sans-contact` figent le chiffre : au-dela, la
 * commande sort en echec. Branchee sur une tache planifiee ou une chaine
 * d'integration, elle ne repare rien mais elle EMPECHE LE CHIFFRE D'EMPIRER
 * en silence — ce qui est le seul progres honnete tant que la decision
 * juridique n'est pas prise.
 *
 * ── LE PIEGE QU'ELLE EVITE (et qui a deja coute une commande) ──────────────
 *
 * `contacts` porte `FORCE ROW LEVEL SECURITY`. Une mesure lancee sans contexte
 * d'espace rend « 0 fiche » et donc « 0 % sans base legale » : le rapport le
 * plus rassurant possible, et le plus faux. `MesureBaseLegale` boucle donc par
 * espace, et cette commande ECHOUE si elle n'a mesure aucune fiche du tout
 * plutot que de rendre un 0 % triomphal.
 */
class CrmMesureBaseLegale extends Command
{
    protected $signature = 'crm:mesure-base-legale
        {--json : sortie machine (un seul objet JSON, rien d autre)}
        {--seuil-sans-base= : part maximale toleree, en %, de fiches sans base legale}
        {--seuil-sans-contact= : part maximale toleree, en %, de fiches sans aucun moyen de contact}
        {--autoriser-base-vide : ne pas echouer quand la mesure ne trouve AUCUNE fiche}';

    protected $description = 'Mesure la part de fiches personne sans moyen de contact et sans base legale, par source. N ecrit rien.';

    /**
     * Repere fige de l'audit 360 (production, 2026-08-18). Imprime a cote du
     * chiffre du jour : un rapport qui ne dit pas d'ou l'on part ne permet pas
     * de voir si l'on s'ameliore.
     */
    private const REPERE_TOTAL = 1319567;

    private const REPERE_SANS_CONTACT = 909086;

    private const REPERE_SANS_BASE = 1319567;

    public function handle(MesureBaseLegale $mesure): int
    {
        $rapport = $mesure->surTousLesEspaces();

        $partSansContact = MesureBaseLegale::part($rapport['sans_contact'], $rapport['total']);
        $partSansBase = MesureBaseLegale::part($rapport['sans_base'], $rapport['total']);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'espaces' => $rapport['espaces'],
                'total' => $rapport['total'],
                'sans_contact' => $rapport['sans_contact'],
                'sans_base' => $rapport['sans_base'],
                'supprimees' => $rapport['supprimees'],
                'part_sans_contact' => $partSansContact,
                'part_sans_base' => $partSansBase,
                'repere_2026_08_18' => [
                    'total' => self::REPERE_TOTAL,
                    'sans_contact' => self::REPERE_SANS_CONTACT,
                    'sans_base' => self::REPERE_SANS_BASE,
                ],
                'par_source' => $rapport['par_source'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->rendreEnTexte($rapport, $partSansContact, $partSansBase);
        }

        // ── ANTI-VERT-A-VIDE ───────────────────────────────────────────────
        // Zero fiche mesuree n'est pas « zero probleme » : c'est, neuf fois
        // sur dix, la RLS qui a etouffe la requete, ou une base de travail
        // vide prise pour la production. On refuse de rendre SUCCESS dessus.
        if ($rapport['total'] === 0 && ! $this->option('autoriser-base-vide')) {
            $this->error(
                'MESURE VIDE : aucune fiche personne n a ete comptee sur '
                . $rapport['espaces'] . ' espace(s) de travail. Ce n est PAS un bon resultat. '
                . 'Causes probables, dans l ordre : (1) la RLS de la table contacts a etouffe la '
                . 'requete (role applicatif sans app.current_workspace_id) ; (2) la base visee n est '
                . 'pas celle qui porte les donnees ; (3) la table est reellement vide, auquel cas '
                . 'relancer avec --autoriser-base-vide pour le dire explicitement.',
            );

            return self::FAILURE;
        }

        return $this->verdictDesSeuils($partSansContact, $partSansBase);
    }

    /**
     * @param  array{espaces: int, total: int, sans_contact: int, sans_base: int, supprimees: int, par_source: list<array{source: string, total: int, sans_contact: int, sans_base: int}>}  $rapport
     */
    private function rendreEnTexte(array $rapport, ?float $partSansContact, ?float $partSansBase): void
    {
        $this->info('C21-006 — fiches personne : moyen de contact et base legale');
        $this->line('Espaces de travail mesures : ' . $rapport['espaces']);
        $this->line('Fiches vivantes            : ' . $rapport['total']
            . ' (repere audit 2026-08-18 : ' . self::REPERE_TOTAL . ')');
        $this->line('Fiches en suppression douce: ' . $rapport['supprimees']
            . ' — leurs donnees personnelles sont toujours en base');
        $this->line('Sans AUCUN moyen de contact: ' . $rapport['sans_contact']
            . ' (' . $this->formatPart($partSansContact) . ') '
            . '— repere : ' . self::REPERE_SANS_CONTACT . ' (68,89 %)');
        $this->line('Sans base legale           : ' . $rapport['sans_base']
            . ' (' . $this->formatPart($partSansBase) . ') '
            . '— repere : ' . self::REPERE_SANS_BASE . ' (100,00 %)');

        if ($rapport['par_source'] === []) {
            $this->line('Aucune source a detailler.');

            return;
        }

        $this->newLine();
        $this->table(
            ['source', 'fiches', 'sans contact', '%', 'sans base legale', '%'],
            array_map(function (array $ligne): array {
                return [
                    $ligne['source'],
                    $ligne['total'],
                    $ligne['sans_contact'],
                    $this->formatPart(MesureBaseLegale::part($ligne['sans_contact'], $ligne['total'])),
                    $ligne['sans_base'],
                    $this->formatPart(MesureBaseLegale::part($ligne['sans_base'], $ligne['total'])),
                ];
            }, $rapport['par_source']),
        );

        $this->newLine();
        $this->warn(
            'Cette commande N ECRIT RIEN. Poser une base legale sur le stock existant est '
            . 'une decision du responsable de traitement, source par source, pas un UPDATE.',
        );
    }

    /**
     * Comparaison aux seuils. Un seuil absent = pas de verdict sur cette
     * dimension : on ne fabrique pas d'echec par defaut, sinon la commande
     * deviendrait inutilisable et serait desactivee, ce qui rendrait le
     * chiffre a nouveau invisible.
     */
    private function verdictDesSeuils(?float $partSansContact, ?float $partSansBase): int
    {
        $verdict = self::SUCCESS;

        foreach ([
            ['seuil-sans-contact', $partSansContact, 'sans aucun moyen de contact'],
            ['seuil-sans-base', $partSansBase, 'sans base legale'],
        ] as [$option, $part, $libelle]) {
            $brut = $this->option($option);
            if ($brut === null || $brut === '' || $part === null) {
                continue;
            }

            $seuil = (float) $brut;
            if ($part > $seuil) {
                $this->error(
                    'SEUIL DEPASSE : ' . $part . ' % de fiches ' . $libelle
                    . ', pour un maximum tolere de ' . $seuil . ' %.',
                );
                $verdict = self::FAILURE;
            }
        }

        return $verdict;
    }

    /** `null` (denominateur nul) ne se rend PAS « 0 % » : il se rend « non mesurable ». */
    private function formatPart(?float $part): string
    {
        return $part === null ? 'non mesurable' : number_format($part, 2, ',', ' ') . ' %';
    }
}
