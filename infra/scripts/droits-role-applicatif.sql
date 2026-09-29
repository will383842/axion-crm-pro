-- DROITS DU RÔLE APPLICATIF SUR UNE BASE RESTAURÉE — requête UNIQUE, lue par
-- `restore-postgres.sh` (étape 5) et `dr-drill.sh` (étape 5), et exécutée telle
-- quelle par `DroitsRoleApplicatifApresRestaurationTest`.
--
-- Variable psql : `role` (le rôle applicatif), passée par `-v role=…`.
--
-- Deux questions, deux genres de ligne (rien = tout va bien) :
--
--   illisible|<table>  une table publique que le rôle applicatif ne peut lire
--                      NI en entier NI par une seule colonne : l'application
--                      échouerait sur « permission denied ». `has_table_privilege`
--                      seul ne suffit pas : il vaut faux pour une table dont le
--                      rôle ne lit QUE certaines colonnes (`adresses_partagees`,
--                      `fusions_empreintes`) — d'où `has_any_column_privilege`.
--                      Les tables de CLÉS, volontairement fermées, n'y sont pas.
--
--   fuite|<objet>      une table de clés, ou une colonne d'empreintes, que le
--                      rôle applicatif PEUT lire alors qu'elle doit lui rester
--                      fermée (clés HMAC `contacts_retires_cle`, `doublons_cle` ;
--                      `adresses_partagees.email_empreinte`,
--                      `fusions_empreintes.empreinte`). Un droit accordé en
--                      masse après une restauration produit exactement cela.
--                      Aussi `fuite|fonction:<nom>` : une fonction d'empreinte
--                      (`doublons_empreinte`, `contacts_retires_empreinte`)
--                      EXÉCUTABLE par le rôle applicatif — il calculerait
--                      l'empreinte de n'importe quelle valeur devinée.
WITH fermees(nom) AS (
    VALUES ('contacts_retires_cle'), ('doublons_cle')
),
colonnes_fermees(tab, col) AS (
    VALUES ('adresses_partagees', 'email_empreinte'), ('fusions_empreintes', 'empreinte')
),
-- `to_regprocedure` : une base plus ancienne, sans l'une des fonctions, ne
-- fait pas échouer la vérification.
fonctions_fermees(nom, sig) AS (
    VALUES ('contacts_retires_empreinte', 'public.contacts_retires_empreinte(text, text)'),
           ('doublons_empreinte', 'public.doublons_empreinte(text)')
),
publiques AS (
    SELECT c.oid, c.relname
    FROM   pg_class c
    JOIN   pg_namespace n ON n.oid = c.relnamespace
    WHERE  n.nspname = 'public'
    AND    c.relkind IN ('r', 'p')
),
illisibles AS (
    SELECT p.relname AS nom
    FROM   publiques p
    WHERE  p.relname NOT IN (SELECT f.nom FROM fermees f)
    AND    NOT has_table_privilege(:'role', p.oid, 'SELECT')
    AND    NOT has_any_column_privilege(:'role', p.oid, 'SELECT')
),
fuites AS (
    SELECT p.relname AS nom
    FROM   publiques p
    JOIN   fermees f ON f.nom = p.relname
    WHERE  has_any_column_privilege(:'role', p.oid, 'SELECT')
    UNION ALL
    SELECT p.relname || '.' || cf.col
    FROM   publiques p
    JOIN   colonnes_fermees cf ON cf.tab = p.relname
    WHERE  has_column_privilege(:'role', p.oid, cf.col, 'SELECT')
    UNION ALL
    SELECT 'fonction:' || ff.nom
    FROM   fonctions_fermees ff
    WHERE  to_regprocedure(ff.sig) IS NOT NULL
    AND    has_function_privilege(:'role', to_regprocedure(ff.sig), 'EXECUTE')
)
SELECT 'illisible' AS genre, nom FROM illisibles
UNION ALL
SELECT 'fuite', nom FROM fuites
ORDER BY 1, 2;
