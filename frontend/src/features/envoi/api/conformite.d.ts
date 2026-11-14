/**
 * Types du contrat d'API d'Envoi, zone « conformite ».
 * FICHIER GÉNÉRÉ par `pnpm api:envoi` depuis `backend/openapi/envoi/conformite.yaml`.
 * Ne pas modifier à la main : modifier la description, puis régénérer.
 */
export interface paths {
    "/desinscription/{jeton}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Page publique de désinscription (sans connexion) */
        get: operations["lirePageDesinscription"];
        put?: never;
        /** Confirmer la désinscription (un clic) */
        post: operations["confirmerDesinscription"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/alertes": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Alertes d'envoi */
        get: operations["listerAlertesEnvoi"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/alertes/{id}/regler": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Marquer une alerte comme réglée (elle reste dans l'historique) */
        post: operations["marquerAlerteReglee"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/carnet": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Carnet de destinataires (Envoi sans CRM) */
        get: operations["listerCarnet"];
        put?: never;
        /** Ajouter une entrée au carnet */
        post: operations["ajouterAuCarnet"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/desinscriptions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Désinscriptions */
        get: operations["listerDesinscriptions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/opposition": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Registre d'opposition (« ne plus écrire ») */
        get: operations["listerOpposition"];
        put?: never;
        /** Inscrire une adresse, une organisation ou un domaine */
        post: operations["inscrireOpposition"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/opposition/export": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Export du registre (CSV)
         * @description Réservé au propriétaire (`workspaces.manage`) ET à la permission `data.export`. Envoyé en flux, journalisé
         *     (qui, quand, combien de lignes ; jamais les adresses). Les cellules commençant par `=`, `+`, `-`, `@` sont
         *     neutralisées. Limite : 2 exports par heure (429 au-delà).
         */
        get: operations["exporterOpposition"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/puis-je-ecrire": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * « Puis-je écrire à… ? »
         * @description Rend seulement `peut` et un motif codé REGROUPÉ (jamais le détail d'une garde). Une adresse inconnue de l'espace
         *     et une adresse connue seulement d'un autre espace reçoivent EXACTEMENT la même réponse (aucun oracle « contact connu »).
         *     Limite : 30 demandes par minute et par compte (429 au-delà). POST de LECTURE SEULE : rien n'est écrit (ni registre, ni drapeau, ni journal d'audit). L'adresse voyage dans le corps JSON, jamais dans l'URL : elle n'apparaît ni dans les journaux d'accès du proxy ni dans la chaîne de requête envoyée à Sentry (`before_send` retire la chaîne de requête des routes `/envoi/*`), et elle n'est jamais journalisée en clair par l'application.
         */
        post: operations["puisJeEcrire"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reglages": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Réglages d'envoi (vue d'ensemble) */
        get: operations["lireReglagesEnvoi"];
        /** Enregistrer les réglages d'envoi */
        put: operations["enregistrerReglagesEnvoi"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reglages/absence": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        /** Mode absence (campagnes en pause, réponses en attente) */
        put: operations["enregistrerModeAbsence"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reglages/alertes": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Règles d'alerte (type × canal) */
        get: operations["listerReglesAlerte"];
        /** Enregistrer les règles d'alerte */
        put: operations["enregistrerReglesAlerte"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reglages/alertes/test": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Envoyer une alerte de test (cloche et e-mail seulement ; aucun message réel en démo) */
        post: operations["envoyerAlerteDeTest"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reglages/apercu": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Accueil des réglages d'envoi (résumé et boucles de retour) */
        get: operations["lireApercuReglages"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reglages/connexion-crm": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Connexion au CRM (adaptateur) */
        get: operations["lireConnexionCrm"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reglages/connexion-crm/tester": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Tester la connexion au CRM */
        post: operations["testerConnexionCrm"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reglages/identite": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Identité de l'expéditeur (mentions, adresse postale, signature) */
        get: operations["lireIdentite"];
        /** Enregistrer l'identité */
        put: operations["enregistrerIdentite"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reglages/regles": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Règles de prudence et horaires */
        get: operations["lireRegles"];
        /** Enregistrer les règles (durcir seulement ; un assouplissement répond 422) */
        put: operations["enregistrerRegles"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
}
export type webhooks = Record<string, never>;
export interface components {
    schemas: {
        AlerteEnvoi: {
            ce_que_le_systeme_a_fait: string;
            ce_qui_se_passe: string;
            close: boolean;
            detail: string;
            id: components["schemas"]["Identifiant"];
            lien: string | null;
            /** @enum {string} */
            niveau: "a_faire" | "attention" | "nouveau" | "fait";
            que_faire: string;
            survenue_le: components["schemas"]["Horodatage"];
            titre: string;
        };
        ApercuReglages: {
            absence: components["schemas"]["ModeAbsence"];
            boucles_de_retour: components["schemas"]["BoucleDeRetour"][];
            canaux: components["schemas"]["CanalAlerte"][];
            oppositions: number;
            regles_actives: number;
        };
        BoucleDeRetour: {
            detail: string;
            /** @enum {string} */
            etat: "fait" | "a_faire";
            nom: string;
        };
        /**
         * @description Alertes par la cloche de la console et par e-mail seulement.
         * @enum {string}
         */
        CanalAlerte: "console" | "email";
        ConnexionCrm: {
            /** @enum {string} */
            adaptateur: "crm_pro" | "generique" | "aucun";
            /** @description Ce qui ne quitte jamais Envoi ou le CRM. */
            ce_qui_ne_passe_pas: string[];
            /** @description Ce que l'adaptateur échange avec le CRM (phrases courtes). */
            ce_qui_passe: string[];
            contacts_vus: number;
            derniere_synchro_le: components["schemas"]["Horodatage"] | null;
            etat: components["schemas"]["EtatFeu"];
        };
        /**
         * @description Une désinscription ne garde qu'une empreinte de l'adresse : `adresse` n'est
         *     renseignée que si la fiche du CRM de l'espace la porte encore (et si le compte
         *     a le droit de voir les coordonnées) ; sinon `null`. `adresse_masquee` est toujours
         *     présente et ne permet pas de retrouver l'adresse.
         */
        Desinscription: {
            adresse: string | null;
            adresse_masquee: string;
            campagne_id: components["schemas"]["Identifiant"] | null;
            id: components["schemas"]["Identifiant"];
            source: components["schemas"]["SourceDesinscription"];
            survenue_le: components["schemas"]["Horodatage"];
        };
        EntreeCarnet: {
            /** Format: email */
            adresse: string;
            ajoute_le: components["schemas"]["Horodatage"];
            etiquettes: string[];
            id: components["schemas"]["Identifiant"];
            nom: string | null;
            organisation: string | null;
            prenom: string | null;
        };
        Erreur: {
            details?: {
                [key: string]: string[];
            };
            /** @description Code machine stable (ex. `non_trouve`, `validation`, `envois_arretes`). */
            error: string;
            /** @description Phrase en français, au vouvoiement, affichable telle quelle. */
            message: string;
        };
        /**
         * @description Feu de santé (vert bon, ambre attention, rouge arrêté, bleu en montée, gris inconnu).
         * @enum {string}
         */
        EtatFeu: "vert" | "ambre" | "rouge" | "bleu" | "gris";
        /**
         * Format: date-time
         * @description Date et heure ISO 8601 (UTC).
         */
        Horodatage: string;
        /**
         * @description Identifiant stable, opaque.
         * @example cmp-01
         */
        Identifiant: string;
        Identite: {
            adresse_postale: string;
            /** @description Bloc d'information des destinataires (art. 14 du RGPD), présent sous chaque message. */
            bloc_information: string;
            /**
             * @description Forme juridique de l'entité d'envoi (SAS française).
             * @example SAS
             */
            forme: string;
            /** @constant */
            lien_desinscription: true;
            /** @description Lien de rendez-vous ajouté au message (facultatif). */
            lien_rendez_vous: string | null;
            raison_sociale: string;
            signature: string;
        };
        ModeAbsence: {
            actif: boolean;
            au: string | null;
            du: string | null;
        };
        /** @description Ligne du registre d'opposition (« ne plus écrire »). Jamais supprimée. */
        Opposition: {
            id: components["schemas"]["Identifiant"];
            inscrite_le: components["schemas"]["Horodatage"];
            motif: string;
            /** @enum {string} */
            portee: "adresse" | "organisation" | "domaine";
            source: components["schemas"]["SourceDesinscription"];
            valeur: string;
        };
        Pagination: {
            page: number;
            pages: number;
            par_page: number;
            total: number;
        };
        Reglages: {
            /** @example Europe/Paris */
            fuseau: string;
            heures: {
                a: string;
                de: string;
            };
            jours: ("lun" | "mar" | "mer" | "jeu" | "ven" | "sam" | "dim")[];
            pause_si_refus_pct: number;
            plafond_jour: number;
        };
        /** @description Règles d'alerte par type et canal (console et e-mail seulement), seconde adresse, regroupement. */
        ReglagesAlertes: {
            data: components["schemas"]["RegleAlerte"][];
            /** @description Les alertes de même type sont regroupées sur cette durée (15 minutes). */
            regroupement_minutes: number;
            seconde_adresse: string | null;
        };
        RegleAlerte: {
            actif: boolean;
            canaux: components["schemas"]["CanalAlerte"][];
            /** @enum {string} */
            condition: "taux_refus" | "plainte" | "liste_noire" | "boite_ralentie" | "test_matin" | "reponse_interessee" | "quota_atteint";
            id: components["schemas"]["Identifiant"];
            nom: string;
            seuil: number | null;
        };
        /**
         * @description Règles de prudence et horaires. On ne peut que DURCIR (valeur plus
         *     prudente) : un seuil plus haut, une fenêtre plus large ou un frein plus
         *     lâche répond 422 `assouplissement_refuse`.
         */
        Regles: {
            /** @description Au-delà de ce nombre de réponses en attente par jour, les envois ralentissent (15 par défaut). */
            frein_reponses_par_jour: number;
            /** @example 17:30 */
            heure_a: string;
            /** @example 09:00 */
            heure_de: string;
            /** @description Pas de week-end ni de jour férié français. */
            jours_ouvres_seulement: boolean;
            pause_si_plaintes_pour_mille: number;
            pause_si_refus_pct: number;
            /** @description Nombre maximal de messages vers une même organisation sur 30 jours (pression commerciale). */
            pression_max_par_organisation_30j: number;
            /** @constant */
            test_du_matin_bloquant: true;
        };
        ReponsePuisJeEcrire: {
            /** @description Groupe de motif quand `peut` est faux ; `null` quand c'est oui. */
            motif: ("opposition" | "adresse_non_verifiee" | "contact_ecarte" | "conditions_envoi") | null;
            peut: boolean;
        };
        /** @enum {string} */
        SourceDesinscription: "lien" | "reponse_stop" | "plainte" | "manuel";
        Succes: {
            message?: string;
            /** @constant */
            ok: true;
        };
    };
    responses: {
        /** @description État de la connexion au CRM. */
        ConnexionCrm: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["ConnexionCrm"];
            };
        };
        /** @description Erreur, avec un message en français. */
        Erreur: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Erreur"];
            };
        };
        /** @description Identité de l'expéditeur. */
        Identite: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Identite"];
            };
        };
        /** @description Réglages d'envoi. */
        Reglages: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Reglages"];
            };
        };
        /** @description Règles d'alerte. */
        ReglagesAlertes: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["ReglagesAlertes"];
            };
        };
        /** @description Règles de prudence et horaires. */
        Regles: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Regles"];
            };
        };
        /** @description Geste accepté. */
        Succes: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Succes"];
            };
        };
    };
    parameters: {
        Page: number;
        ParPage: number;
        /** @description Recherche texte (insensible à la casse et aux accents). */
        Recherche: string;
    };
    requestBodies: never;
    headers: never;
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
    lirePageDesinscription: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                jeton: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Adresse masquée et expéditeur. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        adresse_masquee: string;
                        deja_desinscrit: boolean;
                        expediteur: string;
                        /** @description Nom de l'organisation du destinataire, si connue (option « toute mon organisation »). */
                        organisation: string | null;
                        /** @description Texte « pourquoi ce message » (source, données, et aucun suivi des clics). */
                        pourquoi_ce_message: string;
                    };
                };
            };
            404: components["responses"]["Erreur"];
        };
    };
    confirmerDesinscription: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                jeton: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                "application/json": {
                    /** @description Inscrit aussi l'organisation (portée « organisation » du registre). */
                    toute_l_organisation?: boolean;
                };
            };
        };
        responses: {
            200: components["responses"]["Succes"];
            404: components["responses"]["Erreur"];
        };
    };
    listerAlertesEnvoi: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Alertes ouvertes et récentes. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["AlerteEnvoi"][];
                    };
                };
            };
        };
    };
    marquerAlerteReglee: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description L'alerte après changement. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AlerteEnvoi"];
                };
            };
            404: components["responses"]["Erreur"];
        };
    };
    listerCarnet: {
        parameters: {
            query?: {
                page?: components["parameters"]["Page"];
                par_page?: components["parameters"]["ParPage"];
                /** @description Recherche texte (insensible à la casse et aux accents). */
                q?: components["parameters"]["Recherche"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Entrées du carnet. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["EntreeCarnet"][];
                        meta: components["schemas"]["Pagination"];
                    };
                };
            };
        };
    };
    ajouterAuCarnet: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** Format: email */
                    adresse: string;
                    etiquettes?: string[];
                    nom?: string;
                    organisation?: string;
                    prenom?: string;
                };
            };
        };
        responses: {
            /** @description Entrée créée. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["EntreeCarnet"];
                };
            };
        };
    };
    listerDesinscriptions: {
        parameters: {
            query?: {
                page?: components["parameters"]["Page"];
                par_page?: components["parameters"]["ParPage"];
                /** @description Recherche texte (insensible à la casse et aux accents). */
                q?: components["parameters"]["Recherche"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Désinscriptions paginées. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["Desinscription"][];
                        meta: components["schemas"]["Pagination"];
                    };
                };
            };
        };
    };
    listerOpposition: {
        parameters: {
            query?: {
                page?: components["parameters"]["Page"];
                par_page?: components["parameters"]["ParPage"];
                /** @description Recherche texte (insensible à la casse et aux accents). */
                q?: components["parameters"]["Recherche"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Lignes du registre. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["Opposition"][];
                        meta: components["schemas"]["Pagination"];
                        /** @description Chiffres du registre entier (indépendants de la page affichée). */
                        resume: {
                            ce_mois: number;
                            ce_mois_par_lien: number;
                            domaines_entiers: number;
                            organisations: number;
                            total: number;
                        };
                    };
                };
            };
        };
    };
    inscrireOpposition: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    motif: string;
                    /** @enum {string} */
                    portee: "adresse" | "organisation" | "domaine";
                    valeur: string;
                };
            };
        };
        responses: {
            /** @description Ligne inscrite. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Opposition"];
                };
            };
        };
    };
    exporterOpposition: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Fichier CSV. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "text/csv": string;
                };
            };
            403: components["responses"]["Erreur"];
            429: components["responses"]["Erreur"];
        };
    };
    puisJeEcrire: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** Format: email */
                    adresse: string;
                };
            };
        };
        responses: {
            /** @description La réponse et, si c'est non, le groupe de motif. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ReponsePuisJeEcrire"];
                };
            };
            422: components["responses"]["Erreur"];
            429: components["responses"]["Erreur"];
        };
    };
    lireReglagesEnvoi: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["Reglages"];
        };
    };
    enregistrerReglagesEnvoi: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["Reglages"];
            };
        };
        responses: {
            200: components["responses"]["Reglages"];
        };
    };
    enregistrerModeAbsence: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ModeAbsence"];
            };
        };
        responses: {
            /** @description Mode absence enregistré. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ModeAbsence"];
                };
            };
            422: components["responses"]["Erreur"];
        };
    };
    listerReglesAlerte: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["ReglagesAlertes"];
        };
    };
    enregistrerReglesAlerte: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ReglagesAlertes"];
            };
        };
        responses: {
            200: components["responses"]["ReglagesAlertes"];
        };
    };
    envoyerAlerteDeTest: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["Succes"];
        };
    };
    lireApercuReglages: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Résumé. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ApercuReglages"];
                };
            };
        };
    };
    lireConnexionCrm: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["ConnexionCrm"];
        };
    };
    testerConnexionCrm: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["ConnexionCrm"];
        };
    };
    lireIdentite: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["Identite"];
        };
    };
    enregistrerIdentite: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["Identite"];
            };
        };
        responses: {
            200: components["responses"]["Identite"];
        };
    };
    lireRegles: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["Regles"];
        };
    };
    enregistrerRegles: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["Regles"];
            };
        };
        responses: {
            200: components["responses"]["Regles"];
            422: components["responses"]["Erreur"];
        };
    };
}
