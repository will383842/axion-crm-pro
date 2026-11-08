# Fiche K14 — inscriptions de réputation (pas à pas)

**Pour qui** : le responsable du projet. **Règle** : cette fiche explique ; elle n'inscrit rien à votre place. Chaque inscription se fait avec VOTRE compte, sur VOTRE ordinateur. Rien ici n'envoie de courrier.

**Quand** : après que le serveur existe, que les domaines d'envoi sont achetés et que le DNS est vert (contrôle K12). Les inscriptions se font **avant** le premier envoi réel : elles sont en place quand les premières données arrivent (il n'y en a pas au tout début : il faut du volume, voir le point 5 de Google Postmaster).

**Ce qu'il faut sous la main** : la liste de vos `<domaine-N>`, l'`<ip-envoi>` (l'adresse du serveur qui envoie, pas celle de gestion), l'accès au DNS de chaque domaine, et la boîte de réception D2 (celle qui reçoit `postmaster@` et `abuse@`).

**Règle de sécurité** : n'écrivez jamais un mot de passe, une IP ou un domaine réels dans le dépôt, un ticket ou une discussion publique. Notez-les dans votre gestionnaire de mots de passe. Pour trouver chaque service, cherchez son nom (en gras ci-dessous) dans votre moteur de recherche : le dépôt public ne contient pas de liens.

## 1. Google Postmaster Tools (Gmail)
À quoi ça sert : voir la réputation de vos domaines chez Gmail et le taux de plaintes.
1. Ouvrez **Google Postmaster Tools** avec un compte Google dédié à l'envoi (pas un compte personnel).
2. Cliquez sur « Ajouter un domaine » et saisissez `<domaine-1>`.
3. Google donne un texte à publier en TXT dans le DNS du domaine. Publiez-le, attendez quelques minutes, puis « Valider ».
4. Répétez pour chaque `<domaine-N>`.
5. Les graphiques n'apparaissent qu'avec du volume (quelques centaines de messages par jour vers Gmail). Pas de données au début : c'est normal.
6. Notez : qui regarde, à quelle fréquence (conseillé : chaque jour pendant la chauffe).

## 2. Microsoft SNDS et JMRP (Outlook, Hotmail)
À quoi ça sert : SNDS montre l'état de votre IP chez Microsoft ; JMRP vous renvoie les plaintes des utilisateurs.
**Attention** : Microsoft a migré SNDS vers un **nouveau portail** et l'ancien parcours a changé ; les écrans décrits ci-dessous sont à revérifier à l'inscription. SNDS est **facultatif** pour démarrer (JMRP, lui, reçoit les plaintes) ; comme Postmaster, il ne montre rien tant qu'il n'y a pas de volume.
1. Créez/utilisez un compte Microsoft dédié. Ouvrez **Smart Network Data Services (SNDS)**.
2. Demandez l'accès pour `<ip-envoi>` (une demande par IP). Microsoft envoie un message de confirmation à une adresse liée à l'IP/au domaine du PTR : c'est la boîte D2 (`postmaster@`). Elle doit donc déjà recevoir du courrier.
3. Cliquez sur le lien de confirmation reçu. Activez aussi, si proposé, l'accès aux données automatisées (facultatif).
4. Ouvrez **Junk Mail Reporting Program (JMRP)** et inscrivez la même `<ip-envoi>` ; l'adresse qui reçoit les rapports est la boîte D2 (les rapports y arrivent au format ARF, déjà lus par le CRM, K11).
5. À chaque nouvelle IP ajoutée au pool : refaire SNDS et JMRP pour cette IP.

## 3. Yahoo — boucle de plaintes (CFL, Complaint Feedback Loop)
À quoi ça sert : Yahoo (et AOL) vous renvoie les plaintes.
1. Cherchez **Yahoo Sender Hub** et ouvrez la section « Complaint Feedback Loop ».
2. Connectez-vous avec un compte Yahoo dédié. Ajoutez chaque `<domaine-N>` (le domaine qui signe en DKIM).
3. Yahoo vérifie le domaine (DKIM ou TXT). Quand c'est validé, indiquez l'adresse de réception des rapports : la boîte D2.
4. Les plaintes arrivent en ARF ; elles comptent comme des plaintes dans le CRM (freins de la désinscription et de la santé).

## 4. Orange (Orange / Wanadoo, très présent en France)
Il n'y a pas de tableau de bord public comparable. Le chemin utile est le contact délivrabilité.
1. Cherchez **Orange Postmaster** (page « postmaster » ou « délivrabilité » d'Orange).
2. Lisez les conditions : IP avec PTR correct, SPF, DKIM, DMARC, liste de désinscription claire. Tout cela est déjà vérifié par le contrôle K12.
3. Utilisez le formulaire ou l'adresse indiquée pour vous présenter : nom du domaine, `<ip-envoi>`, type d'envoi (prospection B2B, faible volume, opposition en un clic), adresse de contact (`postmaster@`).
4. En cas de blocage de l'IP, la même page donne la procédure de déblocage. Gardez la copie de votre demande (hors dépôt).
5. À vérifier par vous : si Orange propose entre-temps un outil d'inscription ou une boucle de plaintes, ajoutez-le ici (cette fiche a été écrite sans accéder à ces services).

## 5. Signal Spam (France) — seulement s'il est ouvert aux expéditeurs
Signal Spam est surtout fait pour recevoir les signalements des internautes ; l'accès « expéditeur » (boucle de retour des signalements) n'est pas toujours ouvert à tous.
1. Cherchez **Signal Spam** et lisez la page destinée aux « professionnels / expéditeurs ».
2. Si une inscription expéditeur est proposée : suivez-la avec `<domaine-N>` et `<ip-envoi>`, et indiquez la boîte D2 comme adresse de retour.
3. Si elle n'est pas ouverte à votre profil : ne forcez rien. Notez la date de vérification et revenez dans quelques mois.
4. Un message signalé à Signal Spam doit toujours pouvoir être traité comme une plainte : même circuit que les autres (opposition).

## Après chaque inscription
- Notez dans votre gestionnaire de mots de passe : service, compte, date, domaine/IP concerné.
- Vérifiez que la boîte D2 reçoit bien : envoyez-vous un message de test depuis un autre compte vers `postmaster@<domaine-1>`.
- Dites à l'équipe technique « inscription X faite » : elle ouvre le suivi (K15) sans connaître vos identifiants.

## Ce que cette fiche ne fait pas (dettes nommées)
- **K14-D1** : aucune capture d'écran ni lien direct (règle du dépôt public : pas de domaine réel). Les écrans des services changent : la fiche est à relire à la première inscription réelle et à corriger.
- **K14-D2** : Orange et Signal Spam non vérifiés auprès des services eux-mêmes (aucun accès réseau pendant la rédaction).
- **K14-D3** : le suivi automatique des données Postmaster/SNDS dans le CRM relève de K15, pas de cette fiche.
