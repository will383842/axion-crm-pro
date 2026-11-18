# Lot FV-BAT — feu vert : batterie et attestation

Plan : commentaire « PLAN DU RESTE À FAIRE (18/11) » de #680 (relectures de #690, #702, #718). Aucune migration, aucun drapeau.

## Ce que fait le lot
- La batterie (`auto.batterie_fraiche`) ne lit que les domaines (`envoi.dns.domaines` et table `envoi_domaines`) et les IP (`envoi.listes_noires.ips`, cet espace seulement) encore configurés. Rien de configuré : aucun filtre (comportement de `main`).
- Dernière ligne de liste choisie par `controle_le DESC, id DESC`.
- Attestation : même `le` pour « atteste » et « retire » ⇒ « retire » gagne (échec fermé), les 22 points restent `attend_serveur`.
- `FeuVertListeTest` (R07) : ajout de `expect(FV_AUTO_SANS_SOURCE)->toBe([])`.

## Tests (rouges d'abord, sous le propriétaire ET sous `pgsql_app`)
`FeuVertBatterieRelectureTest.php` (nouveau, 14 cas). Voisins FeuVert (190) verts.

## Mutations (chacune rouge, restaurée)
Filtre des configurés retiré ⇒ 4 rouges ; tri sans `controle_le` ⇒ 2 rouges ; départage « retire » retiré ⇒ 2 rouges.
