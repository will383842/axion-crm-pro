AGENT 35 — AUTHENTIFICATION — inventaire des preuves
Reference : main e8924b8 (relue par git rev-parse au debut et a la fin)

f35-001-cause-exacte.txt          A-001 : la trace frame par frame, le code du framework
                                  aux deux lignes en cause, routes/web.php integral, et le
                                  cout en journal (8 475 octets par 500).
sonde-courte-1.txt                A-001 MESUREE : temoin d'instrumentation (en-tetes
                                  reellement recus + expectsJson) puis matrice
                                  2 routes x 5 profils de client. 500/500/500/401/401.
f35-002-colonnes-2fa.txt          Les colonnes two_factor_* n'existent dans aucune
                                  migration ; l'erreur SQL 42703 relevee en base ; le
                                  temoin negatif sur totp_secret ; GET /users casse aussi.
f35-003-2fa-decorative.txt        2fa_passed_at ecrit une fois, relu nulle part ; temoin
                                  negatif sur first_login_completed_at, ecrit ET relu.
f35-005-jeton-reset-sans-expiration.txt
                                  Carbon 3.13.0 : now()->diffInMinutes(passe) est NEGATIF,
                                  donc `> 60` toujours faux. Temoin : operandes inverses.
f35-006-revocation-partielle.txt  Ce que la reinitialisation revoque (sessions web, via
                                  AuthenticateSession) et ce qu'elle ne revoque pas
                                  (jetons d'API).
f35-008-mdp-proprietaire-en-clair.txt
                                  OwnerUserSeeder ecrit le mot de passe en clair, sans le
                                  chmod annonce ; contre-epreuve : la route storage.local
                                  exige une signature, donc pas d'exposition reseau.
f35-script-mdp-argv.txt           definir-mot-de-passe-crm.sh : le mot de passe EST dans
                                  argv de docker exec. Geste joue + temoin negatif sur 15
                                  autres docker exec sans secret. Fins de ligne : LF pur.
sonde-run1.txt                    Premiere sonde, PARTIELLE (interrompue).
sonde-run1-INVALIDE-headers-non-envoyes.txt
                                  ⚠️ CONSERVEE COMME CONTRE-EXEMPLE. TestCase::call()
                                  n'envoie pas les en-tetes : les 5 colonnes rendaient 500
                                  et la conclusion aurait ete fausse. C'est le piege n.19
                                  du dossier, rencontre sur ma propre mesure.
sonde/                            Sources de la sonde complete (11 blocs).
sonde2/                           Sources de la sonde courte n.2 (blocs restant a rejouer).

CE QUI RESTE A REJOUER, ET COMMENT : voir le §5 du rapport
_AUDIT/2026-08-18_AUDIT-360/11_GRILLES/agent-35_authentification.md

--- ETAT DE L'ATELIER A LA FIN DE MA SESSION ---
Conteneur `a35-api` (dedie, cree par moi) : LAISSE EN MARCHE. La sonde n.2
(`phpunit-slim2.xml`) y tournait encore, bloquee sur l'amorcage de Laravel
(montage 9p sature par les autres agents ; opcache desactive dans l'image).
Si elle a fini depuis :
    docker exec a35-api cat /tmp/a35/slim2.txt
    docker exec a35-api cat /tmp/a35/slim2out.txt
Bases creees par moi : axion_crm_a35, axion_crm_test_a35 (jetables).
Menage : docker rm -f a35-api ; DROP DATABASE axion_crm_a35, axion_crm_test_a35.

Mesure de la lenteur, pour qui reprendra :
  - opcache.enable = Off ET opcache.enable_cli = Off dans l'image axion-crm-pro-api ;
  - premiere requete HTTP du conteneur dedie : 329 s ;
  - 15 requetes de sonde : 19 min, dont l'essentiel en ecriture de journal
    (8 475 octets par 500 « Route [login] not defined ») ;
  - `wchan` du processus en permanence a `p9_client_rpc` = attente du montage.
Remedes : `-d opcache.enable_cli=1 -d opcache.enable=1 -d opcache.file_cache=...`
et LOG_CHANNEL=null epingle dans $_SERVER par le fichier d'amorcage.
