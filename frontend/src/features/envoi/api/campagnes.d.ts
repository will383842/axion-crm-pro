/**
 * Types du contrat d'API d'Envoi, zone « campagnes ».
 * FICHIER GÉNÉRÉ par `pnpm api:envoi` depuis `backend/openapi/envoi/campagnes.yaml`.
 * Ne pas modifier à la main : modifier la description, puis régénérer.
 */
export interface paths {
    "/envoi/campagnes": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Liste des campagnes */
        get: operations["listerCampagnes"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/campagnes/{id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Fiche de suivi d'une campagne */
        get: operations["lireCampagne"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/campagnes/{id}/date": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Changer la date du prochain envoi */
        post: operations["changerDateCampagne"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/campagnes/{id}/destinataires": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Destinataires et état par destinataire */
        get: operations["listerDestinatairesCampagne"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/campagnes/{id}/dupliquer": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Dupliquer une campagne (brouillon) */
        post: operations["dupliquerCampagne"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/campagnes/{id}/pause": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Mettre en pause (rien n'est perdu) */
        post: operations["mettreCampagneEnPause"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/campagnes/{id}/reprise": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Reprendre une campagne en pause */
        post: operations["reprendreCampagne"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/campagnes/{id}/terminer": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Terminer définitivement (relances comprises ; les réponses restent traitées) */
        post: operations["terminerCampagne"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/campagnes/calendrier": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Calendrier des envois prévus et passés */
        get: operations["calendrierCampagnes"];
        put?: never;
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
        CibleCampagne: {
            libelle: string;
            /** @description Identifiant de la liste ou de l'audience, ou nom de l'étiquette. */
            reference: string;
            total: number;
            type: components["schemas"]["TypeCible"];
        };
        Destinataire: {
            /** Format: email */
            adresse: string;
            campagne_id: components["schemas"]["Identifiant"];
            /** @description Code renvoyé par le serveur destinataire (ex. « 550 5.1.1 »). */
            code_serveur: string | null;
            /** @description Contact du CRM (adaptateur CRM Pro) ; nul pour le carnet d'Envoi seul. */
            contact_id: number | null;
            dernier_evenement_le: components["schemas"]["Horodatage"] | null;
            /** @description Dernier message de la séquence envoyé (0 = aucun). */
            etape: number;
            etat: components["schemas"]["EtatDestinataire"];
            id: components["schemas"]["Identifiant"];
            /** @description Code serveur traduit en français (ex. « Adresse inexistante »). */
            motif: string | null;
            nom: string | null;
            organisation: string | null;
            /** @description Nul si la campagne ne suit pas les ouvertures ou si rien n'a été vu. */
            ouverture: components["schemas"]["Ouverture"] | null;
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
        /** @enum {string} */
        EtatDestinataire: "a_envoyer" | "envoye" | "remis" | "rebond_mou" | "rebond_dur" | "a_repondu" | "desinscrit" | "plainte" | "exclu" | "relances_annulees";
        EvenementEnvoi: {
            /** Format: email */
            adresse: string;
            /** @description Pour une ouverture seulement ; nul pour les autres types. */
            automatique_probable: boolean | null;
            boite_id: components["schemas"]["Identifiant"];
            campagne_id: components["schemas"]["Identifiant"];
            code_serveur: string | null;
            destinataire_id: components["schemas"]["Identifiant"] | null;
            id: components["schemas"]["Identifiant"];
            /** @description Explication en français (code serveur traduit). */
            libelle: string | null;
            survenu_le: components["schemas"]["Horodatage"];
            type: components["schemas"]["TypeEvenementEnvoi"];
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
        /**
         * @description Ouverture estimée d'un message (pixel). `automatique_probable` vaut vrai
         *     quand l'ouverture vient probablement d'un relais (protection de la vie
         *     privée d'Apple, relais d'images, antivirus) et non d'une personne.
         */
        Ouverture: {
            automatique_probable: boolean;
            derniere_le: components["schemas"]["Horodatage"];
            nombre: number;
            premiere_le: components["schemas"]["Horodatage"];
            raison_automatique: ("protection_apple" | "relais_images" | "antivirus" | "ouverture_immediate") | null;
        };
        Pagination: {
            page: number;
            pages: number;
            par_page: number;
            total: number;
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
        /** @enum {string} */
        StatutCampagne: "brouillon" | "a_valider" | "programmee" | "en_cours" | "en_pause" | "terminee" | "arretee";
        /** @enum {string} */
        TypeCible: "liste" | "audience" | "etiquette" | "carnet";
        /** @enum {string} */
        TypeEvenementEnvoi: "remis" | "rebond_dur" | "rebond_mou" | "plainte" | "desinscription" | "ouverture" | "reponse";
    };
    responses: {
        /** @description Campagne après le geste. */
        Campagne: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["CampagneResume"];
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
    };
    parameters: {
        Identifiant: components["schemas"]["Identifiant"];
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
    listerCampagnes: {
        parameters: {
            query?: {
                page?: components["parameters"]["Page"];
                par_page?: components["parameters"]["ParPage"];
                /** @description Recherche texte (insensible à la casse et aux accents). */
                q?: components["parameters"]["Recherche"];
                statut?: components["schemas"]["StatutCampagne"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Campagnes et compte par statut. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["CampagneResume"][];
                        meta: components["schemas"]["Pagination"];
                        par_statut: {
                            [key: string]: number;
                        };
                    };
                };
            };
        };
    };
    lireCampagne: {
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
            /** @description Campagne, séquence et fil des derniers événements. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @description Test A/B sur l'objet ; nul si la campagne n'en fait pas. Le critère est les réponses. */
                        ab: {
                            choix_automatique_le: components["schemas"]["Horodatage"] | null;
                            gagnant: ("A" | "B") | null;
                            versions: {
                                en_tete: boolean;
                                envoyes: number;
                                objet: string;
                                reponses: number;
                                taux_reponse: number;
                                /** @enum {string} */
                                version: "A" | "B";
                            }[];
                        } | null;
                        campagne: components["schemas"]["CampagneResume"];
                        /** @description Décompte des destinataires par état, et taux de la campagne. */
                        compteurs: {
                            /** @description Entreprises dont la suite est arrêtée parce qu'un contact a répondu. */
                            arretes_entreprise: number;
                            desinscrits: number;
                            en_file: number;
                            /** @description Destinataires en pause (absence du bureau), reprise au retour. */
                            en_pause_absence: number;
                            exclus: number;
                            limite_refus: number;
                            ont_repondu: number;
                            partis: number;
                            refuses: number;
                            taux_desinscription: number;
                            taux_refus: number;
                        };
                        derniers_evenements: components["schemas"]["EvenementEnvoi"][];
                        fin_prevue_le: components["schemas"]["Horodatage"] | null;
                        par_boite: {
                            adresse: string;
                            boite_id: components["schemas"]["Identifiant"];
                            envoyes: number;
                            refus: number;
                            reponses: number;
                        }[];
                        par_jour: {
                            envoyes: number;
                            /** Format: date */
                            jour: string;
                        }[];
                        sequence: {
                            delai_jours: number;
                            envoyes: number;
                            etape: number;
                            message_id: components["schemas"]["Identifiant"];
                            refus: number;
                            reponses: number;
                        }[];
                    };
                };
            };
            404: components["responses"]["Erreur"];
        };
    };
    changerDateCampagne: {
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
                    le: components["schemas"]["Horodatage"];
                };
            };
        };
        responses: {
            200: components["responses"]["Campagne"];
            422: components["responses"]["Erreur"];
        };
    };
    listerDestinatairesCampagne: {
        parameters: {
            query?: {
                etat?: components["schemas"]["EtatDestinataire"];
                /** @description Filtre sur l'ouverture estimée (campagnes qui suivent les ouvertures seulement). */
                ouvert?: "oui" | "non" | "automatique";
                page?: components["parameters"]["Page"];
                par_page?: components["parameters"]["ParPage"];
                /** @description Recherche texte (insensible à la casse et aux accents). */
                q?: components["parameters"]["Recherche"];
            };
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Destinataires paginés. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["Destinataire"][];
                        meta: components["schemas"]["Pagination"];
                    };
                };
            };
            404: components["responses"]["Erreur"];
        };
    };
    dupliquerCampagne: {
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
                    /** @default false */
                    exclure_deja_recus?: boolean;
                    /** @default true */
                    garder_cible?: boolean;
                    /** @default true */
                    garder_messages?: boolean;
                    nom: string;
                };
            };
        };
        responses: {
            201: components["responses"]["Campagne"];
        };
    };
    mettreCampagneEnPause: {
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
            200: components["responses"]["Campagne"];
            409: components["responses"]["Erreur"];
        };
    };
    reprendreCampagne: {
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
            200: components["responses"]["Campagne"];
            409: components["responses"]["Erreur"];
        };
    };
    terminerCampagne: {
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
            200: components["responses"]["Campagne"];
            409: components["responses"]["Erreur"];
        };
    };
    calendrierCampagnes: {
        parameters: {
            query: {
                au: string;
                du: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Une ligne par jour et par campagne. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: {
                            campagne_id: components["schemas"]["Identifiant"];
                            envoyes: number;
                            /** Format: date */
                            jour: string;
                            nom: string;
                            prevus: number;
                            statut: components["schemas"]["StatutCampagne"];
                        }[];
                    };
                };
            };
        };
    };
}
