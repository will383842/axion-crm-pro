# Envoi P0 — relève IMAP (lot L6)

## Principes
- Lecture seule de la boîte (BODY.PEEK) ; rien n'est déplacé, marqué ni effacé.
- Seuls des empreintes, la classe du retour et des identifiants techniques sont écrits (`envoi_evenements`, ajout seul).
- **Isolement par message** : un message piégé (From multiple, accentué, mal formé, trop gros, illisible) ou dont l'arrêt est inapplicable (`RetourInapplicable`) devient « à rattacher » **sans effet** ; la boîte continue et le curseur avance. Toute autre exception (CRM ou base indisponibles) interrompt la relève : l'événement n'est pas noté et sera relu.
- Limites : au plus 200 messages par passage ; `RFC822.SIZE` lu d'abord, au-delà de 256 Kio le message est écarté sans être téléchargé ; aucune pièce jointe enregistrée ; chiffrement `ssl`/`tls`/`starttls` seulement, certificat toujours vérifié.
- Rattrapage après panne : fenêtre de 30 jours fondée sur INTERNALDATE (date du serveur), jamais sur l'en-tête `Date`.

## ⚠ File « à rattacher » : revue humaine obligatoire avant mise en service (lot L8)
Un message « à rattacher » n'a **aucun effet**. Une opposition (STOP, plainte) qui s'y trouve n'est donc **pas** appliquée tant qu'une personne ne l'a pas traitée. Avant toute mise en service, le lot L8 doit fournir : une vue de la file (`statut = 'a_rattacher'`, `classe` en `desinscription`/`hostile`), un délai de traitement tenu, et une alerte si la file dépasse un seuil. Sans ce circuit, ne pas envoyer.

## Dépendance `webklex/php-imap` 6.2.0
Version **exacte** épinglée (pas de `^`). Risque : un seul mainteneur, environ 18 mois sans activité. `composer audit` (job `composer-audit`, bloquant) couvre les avis connus. Plan de sortie : tout passe par l'interface `LecteurBoite` ; remplacer `LecteurBoiteWebklex` n'affecte ni la classification, ni le rattachement, ni les arrêts.
