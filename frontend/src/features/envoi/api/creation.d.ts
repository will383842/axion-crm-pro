/**
 * Types du contrat d'API d'Envoi, zone « creation ».
 * FICHIER GÉNÉRÉ par `pnpm api:envoi` depuis `backend/openapi/envoi/creation.yaml`.
 * Ne pas modifier à la main : modifier la description, puis régénérer.
 */
export interface paths {
    "/envoi/brouillons": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Créer un brouillon de campagne */
        post: operations["creerBrouillon"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/brouillons/{id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Lire un brouillon */
        get: operations["lireBrouillon"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/brouillons/{id}/apercu": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Aperçu d'un message pour un destinataire exemple */
        post: operations["apercuMessage"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/brouillons/{id}/cible/estimation": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Compter la cible après exclusions */
        post: operations["estimerCible"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/brouillons/{id}/controles": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Les 7 contrôles avant validation */
        get: operations["lancerControles"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/brouillons/{id}/etapes/{etape}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        /** Enregistrer une étape de l'assistant (1 à 5) */
        put: operations["enregistrerEtape"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/brouillons/{id}/test": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** M'envoyer un test (à mes adresses de test seulement) */
        post: operations["envoyerTest"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/brouillons/{id}/validation/code": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Envoyer le code de validation par e-mail */
        post: operations["demanderCodeValidation"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/brouillons/{id}/valider": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Valider avec le code e-mail (annulable 5 minutes) */
        post: operations["validerCampagne"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/campagnes/{id}/annuler-validation": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Annuler la validation (dans les 5 minutes) */
        post: operations["annulerValidation"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/creation/boites": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Boîtes d'envoi choisissables pour une campagne */
        get: operations["listerBoitesPourCampagne"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/creation/cibles": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Cibles proposables (étiquettes, audiences, listes, entreprise d'origine) avec les joignables */
        get: operations["listerCibles"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/creation/destinataires-exemples": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Destinataires pour l'aperçu réel d'un message */
        get: operations["listerDestinatairesExemples"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/messages": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Messages types (jamais supprimés, les archivés se montrent sur demande) */
        get: operations["listerMessagesTypes"];
        put?: never;
        /** Créer un message type */
        post: operations["creerMessageType"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/messages/{id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Lire un message type */
        get: operations["lireMessageType"];
        /** Enregistrer (crée une nouvelle version) */
        put: operations["enregistrerMessageType"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/messages/{id}/archivage": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Archiver ou désarchiver un message type (il n'est jamais supprimé) */
        post: operations["archiverMessageType"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/messages/{id}/versions/{numero}/restaurer": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Restaurer une ancienne version (crée une nouvelle version) */
        post: operations["restaurerVersion"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/messages/{id}/versions/{numero}/valider": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Valider une version (elle seule est utilisable dans une campagne) */
        post: operations["validerVersion"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/messages/apercu": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Aperçu réel d'un message pour un destinataire exemple */
        post: operations["apercuMessageType"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/messages/controler": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Contrôleur de contenu (mots interdits, liens, bloc d'information art. 14, texte simple, valeurs de repli) */
        post: operations["controlerMessage"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/messages/suggestion": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Brouillon de séquence proposé par l'IA (une suggestion à relire, jamais envoyée seule) */
        post: operations["suggererSequence"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/messages/test": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** M'envoyer un test (à mes adresses de test seulement, jamais à un destinataire) */
        post: operations["envoyerTestMessage"];
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
        BlocReutilisable: {
            id: string;
            nom: string;
            texte: string;
            utilise: number;
        };
        BoiteChoix: {
            adresse: string;
            id: components["schemas"]["Identifiant"];
            quota_jour: number;
            statut: components["schemas"]["StatutBoite"];
        };
        Brouillon: {
            /** @description Fin de la fenêtre d'annulation de 5 minutes (présent une fois validé). */
            annulable_jusqua?: components["schemas"]["Horodatage"] | null;
            /** @description Campagne créée par la validation (présent une fois validé). */
            campagne_id?: components["schemas"]["Identifiant"] | null;
            contenu: components["schemas"]["ContenuEtape"];
            etape_courante: number;
            etapes_faites: number[];
            id: components["schemas"]["Identifiant"];
            mis_a_jour_le: components["schemas"]["Horodatage"];
            nom: string;
        };
        CampagneResume: {
            /** @description Test A/B sur l'objet du premier message. */
            ab_objet: boolean;
            boites: components["schemas"]["Identifiant"][];
            cible: components["schemas"]["CibleCampagne"];
            cree_le: components["schemas"]["Horodatage"];
            envoyes: number;
            /** @description Nombre de messages de la séquence (1 à 4). */
            etapes: number;
            id: components["schemas"]["Identifiant"];
            nom: string;
            /** @description Présent seulement si la campagne suit les ouvertures. */
            ouvertures: components["schemas"]["ResumeOuvertures"] | null;
            prochain_envoi_le: components["schemas"]["Horodatage"] | null;
            reponses: number;
            reponses_interessees: number;
            statut: components["schemas"]["StatutCampagne"];
            /** @description Case « Suivre les ouvertures » de la campagne (pixel optionnel, désactivé par défaut). */
            suivre_ouvertures: boolean;
            total: number;
        };
        Cible: {
            exclusions?: ("deja_contactes_90j" | "clients" | "desinscrits" | "opposition" | "rebonds_durs" | "sans_adresse" | "autres_campagnes_en_cours")[];
            /** @description Adresses, organisations ou domaines à exclure en plus (saisie libre). */
            exclusions_libres?: string[];
            libelle?: string;
            reference: string;
            /**
             * @description Revérifier les adresses vues il y a plus de 30 jours avant l'envoi.
             * @default true
             */
            reverifier_anciennes: boolean;
            type: components["schemas"]["TypeCible"];
        };
        CibleCampagne: {
            libelle: string;
            /** @description Identifiant de la liste ou de l'audience, ou nom de l'étiquette. */
            reference: string;
            total: number;
            type: components["schemas"]["TypeCible"];
        };
        CibleProposee: {
            joignables: number;
            libelle: string;
            reference: string;
            total: number;
            type: components["schemas"]["TypeCible"];
        };
        /** @description Contenu d'une étape ; seules les clés de l'étape sont lues. */
        ContenuEtape: {
            boites?: components["schemas"]["Identifiant"][];
            cible?: components["schemas"]["Cible"];
            debut_le?: components["schemas"]["Horodatage"];
            /** @description Départ dès la validation (sinon programmé à `debut_le`). */
            depart_immediat?: boolean;
            heures?: {
                a: string;
                de: string;
            };
            jours?: ("lun" | "mar" | "mer" | "jeu" | "ven" | "sam" | "dim")[];
            nom?: string;
            plafond_jour?: number;
            /** @description Valeur de repli saisie pour chaque champ (`prénom`, `entreprise`, `lien_rdv`…). */
            repli?: {
                [key: string]: string;
            };
            sequence?: components["schemas"]["EtapeSequence"][];
            /**
             * @description Case « Suivre les ouvertures » (pixel). Les ouvertures seront affichées comme une estimation.
             * @default false
             */
            suivre_ouvertures: boolean;
        };
        Controle: {
            /** @enum {string} */
            code: "cible_non_vide" | "opposition_retiree" | "lien_desinscription" | "identite_expediteur" | "boites_pretes" | "domaines_sains" | "plafond_respecte";
            detail: string | null;
            /** @enum {string} */
            etat: "ok" | "attention" | "bloquant";
            libelle: string;
        };
        ControleContenu: {
            /** @enum {string} */
            code: "mots_interdits" | "liens" | "bloc_information" | "texte_simple" | "valeurs_repli";
            detail: string | null;
            /** @enum {string} */
            etat: "ok" | "attention" | "bloquant";
            libelle: string;
        };
        DestinataireExemple: {
            id: components["schemas"]["Identifiant"];
            libelle: string;
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
        EtapeSequence: {
            /** @description Jours après le message précédent (0 pour le premier). */
            delai_jours: number;
            etape: number;
            message_id: components["schemas"]["Identifiant"];
            /** @description Objet B du test A/B (premier message seulement). */
            objet_b?: string | null;
        };
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
        MessageType: {
            id: components["schemas"]["Identifiant"];
            nom: string;
            /** @description Nombre de campagnes qui l'utilisent. */
            utilise_par: number;
            version_courante: number;
            versions: components["schemas"]["VersionMessage"][];
        };
        /** @description Un message type de l'espace et son état de validation. */
        MessageTypeEtat: components["schemas"]["MessageType"] & {
            /** @description Bloc d'information (art. 14 du RGPD) et lien de désinscription ajoutés au message. */
            bloc_information: boolean;
            statut: components["schemas"]["StatutMessage"];
            /** @description Version validée, la seule utilisable dans une campagne. */
            version_validee: number | null;
        };
        /**
         * @description Ouvertures d'une campagne qui les suit. Toujours affichées comme une
         *     « estimation » ; `automatiques_probables` est déjà retiré de `estimees`.
         */
        ResumeOuvertures: {
            automatiques_probables: number;
            estimees: number;
            /** @constant */
            mention: "estimation";
            /** @description Pourcentage des messages remis, ouvertures automatiques retirées. */
            taux_estime: number;
        };
        SaisieMessage: {
            /**
             * @description Ajouter le bloc d'information (art. 14) et le lien de désinscription.
             * @default true
             */
            bloc_information: boolean;
            corps: string;
            nom: string;
            objet: string;
            objet_b?: string | null;
        };
        /** @enum {string} */
        StatutBoite: "prete" | "ralentie" | "en_chauffe" | "suspendue";
        /** @enum {string} */
        StatutCampagne: "brouillon" | "a_valider" | "programmee" | "en_cours" | "en_pause" | "terminee" | "arretee";
        /** @enum {string} */
        StatutMessage: "brouillon" | "a_relire" | "valide" | "archive";
        Succes: {
            message?: string;
            /** @constant */
            ok: true;
        };
        /** @enum {string} */
        TypeCible: "liste" | "audience" | "etiquette" | "carnet";
        VersionMessage: {
            corps: string;
            modifie_le: components["schemas"]["Horodatage"];
            numero: number;
            objet: string;
            /** @description Objet B du test A/B, s'il y en a un. */
            objet_b?: string | null;
        };
    };
    responses: {
        /** @description Brouillon. */
        Brouillon: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Brouillon"];
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
        /** @description Message type et ses versions. */
        MessageType: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["MessageTypeEtat"];
            };
        };
        /** @description Message type, ses versions et son état. */
        MessageTypeEtat: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["MessageTypeEtat"];
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
        Identifiant: components["schemas"]["Identifiant"];
        /** @description Recherche texte (insensible à la casse et aux accents). */
        Recherche: string;
    };
    requestBodies: never;
    headers: never;
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
    creerBrouillon: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    nom: string;
                };
            };
        };
        responses: {
            201: components["responses"]["Brouillon"];
        };
    };
    lireBrouillon: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["Brouillon"];
            404: components["responses"]["Erreur"];
        };
    };
    apercuMessage: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    destinataire_id?: components["schemas"]["Identifiant"];
                    etape: number;
                    /** @enum {string} */
                    variante?: "a" | "b";
                };
            };
        };
        responses: {
            /** @description Message rendu. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        corps: string;
                        de: string;
                        objet: string;
                    };
                };
            };
        };
    };
    estimerCible: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["Cible"];
            };
        };
        responses: {
            /** @description Comptes de la cible. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        exclus: number;
                        exclusions: {
                            [key: string]: number;
                        };
                        restant: number;
                        total: number;
                    };
                };
            };
        };
    };
    lancerControles: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Résultat de chaque contrôle. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        bloquants: number;
                        controles: components["schemas"]["Controle"][];
                    };
                };
            };
        };
    };
    enregistrerEtape: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                etape: number;
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ContenuEtape"];
            };
        };
        responses: {
            200: components["responses"]["Brouillon"];
            422: components["responses"]["Erreur"];
        };
    };
    envoyerTest: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    destinataire_exemple_id?: components["schemas"]["Identifiant"];
                    etape: number;
                };
            };
        };
        responses: {
            200: components["responses"]["Succes"];
        };
    };
    demanderCodeValidation: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Code envoyé. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        envoye_a: string;
                        expire_le: components["schemas"]["Horodatage"];
                    };
                };
            };
        };
    };
    validerCampagne: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    code: string;
                };
            };
        };
        responses: {
            /** @description Campagne validée ; annulable jusqu'à `annulable_jusqua`. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        annulable_jusqua: components["schemas"]["Horodatage"];
                        campagne: components["schemas"]["CampagneResume"];
                    };
                };
            };
            422: components["responses"]["Erreur"];
        };
    };
    annulerValidation: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["Brouillon"];
            409: components["responses"]["Erreur"];
        };
    };
    listerBoitesPourCampagne: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Boîtes. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["BoiteChoix"][];
                    };
                };
            };
        };
    };
    listerCibles: {
        parameters: {
            query?: {
                /** @description Identifiant d'une entreprise du CRM (ajoute sa cible « carnet »). */
                entreprise?: string;
                /** @description Recherche texte (insensible à la casse et aux accents). */
                q?: components["parameters"]["Recherche"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Cibles. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["CibleProposee"][];
                    };
                };
            };
        };
    };
    listerDestinatairesExemples: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Destinataires exemples. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["DestinataireExemple"][];
                    };
                };
            };
        };
    };
    listerMessagesTypes: {
        parameters: {
            query?: {
                /** @description Inclure les messages archivés. */
                archives?: boolean;
                /** @description Recherche texte (insensible à la casse et aux accents). */
                q?: components["parameters"]["Recherche"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Messages types. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @description Blocs réutilisables (signatures, appels, bloc d'information). */
                        blocs: components["schemas"]["BlocReutilisable"][];
                        data: components["schemas"]["MessageTypeEtat"][];
                    };
                };
            };
        };
    };
    creerMessageType: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["SaisieMessage"];
            };
        };
        responses: {
            201: components["responses"]["MessageType"];
        };
    };
    lireMessageType: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["MessageType"];
            404: components["responses"]["Erreur"];
        };
    };
    enregistrerMessageType: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["SaisieMessage"];
            };
        };
        responses: {
            200: components["responses"]["MessageType"];
        };
    };
    archiverMessageType: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    archive: boolean;
                };
            };
        };
        responses: {
            200: components["responses"]["MessageTypeEtat"];
        };
    };
    restaurerVersion: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
                numero: number;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["MessageType"];
        };
    };
    validerVersion: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
                numero: number;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["MessageTypeEtat"];
            422: components["responses"]["Erreur"];
        };
    };
    apercuMessageType: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @default true */
                    bloc_information?: boolean;
                    corps: string;
                    destinataire_exemple_id?: components["schemas"]["Identifiant"];
                    objet: string;
                };
            };
        };
        responses: {
            /** @description Message rendu. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        corps: string;
                        de: string;
                        objet: string;
                    };
                };
            };
        };
    };
    controlerMessage: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["SaisieMessage"];
            };
        };
        responses: {
            /** @description Résultat de chaque contrôle. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        bloquants: number;
                        controles: components["schemas"]["ControleContenu"][];
                    };
                };
            };
        };
    };
    suggererSequence: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    cible?: string;
                };
            };
        };
        responses: {
            /** @description Suggestion. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        messages: {
                            corps: string;
                            delai_jours: number;
                            objet: string;
                        }[];
                        /** @constant */
                        suggestion: true;
                    };
                };
            };
        };
    };
    envoyerTestMessage: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @default true */
                    a_moi?: boolean;
                    /** @default true */
                    boites_temoins?: boolean;
                    corps: string;
                    destinataire_exemple_id?: components["schemas"]["Identifiant"];
                    /** @default false */
                    deux_versions?: boolean;
                    objet: string;
                    objet_b?: string | null;
                };
            };
        };
        responses: {
            /** @description Test envoyé. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        adresses: number;
                        /** @constant */
                        ok: true;
                    };
                };
            };
        };
    };
}
