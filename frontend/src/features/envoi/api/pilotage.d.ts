/**
 * Types du contrat d'API d'Envoi, zone « pilotage ».
 * FICHIER GÉNÉRÉ par `pnpm api:envoi` depuis `backend/openapi/envoi/pilotage.yaml`.
 * Ne pas modifier à la main : modifier la description, puis régénérer.
 */
export interface paths {
    "/envoi/envois/chercher": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Chercher les retours reçus pour une adresse
         * @description Recherche par empreinte de l'adresse saisie (aucune adresse n'est stockée ni journalisée).
         *     10 recherches par minute et par compte ; chaque recherche est journalisée (qui, quand, empreinte).
         *     Une adresse inconnue et une adresse d'un autre espace donnent la même réponse vide (aucun oracle) :
         *     la réponse ne dit ni « personne connue » ni « peut-on lui écrire ».
         */
        get: operations["chercherEnvoi"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/feu-vert": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Liste « feu vert » avant le premier envoi réel
         * @description Les points `auto.*` sont calculés et jamais écrits ; les points `manuel.*`
         *     portent une preuve humaine. Les verrous sont en lecture seule.
         */
        get: operations["lireFeuVert"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/feu-vert/{code}/fait": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Poser ou retirer la preuve d'un point manuel du feu vert
         * @description Permission requise, `workspaces.manage` (403 sinon, au format `Erreur`). 404 si le
         *     code est inconnu. 422 si le code commence par `auto.` (calculé, jamais écrit ;
         *     même réponse que le code existe ou non), si la preuve (ou le motif de retrait)
         *     fait moins de 10 caractères ou contient une adresse e-mail. La preuve est lue
         *     dans le corps JSON seulement. Un point qui attend le vrai serveur reste non fait
         *     (`fait` false, `bloquant` true) : sa preuve est affichée mais ne le valide pas.
         */
        post: operations["marquerPointFeuVert"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/resultats": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Résultats regroupés */
        get: operations["lireResultats"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/resultats/courbe": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Courbe jour par jour (30 jours) */
        get: operations["lireCourbeResultats"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/sante": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Santé des envois (vue d'ensemble) */
        get: operations["lireSante"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/sante/test": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Lancer le test des boîtes témoins maintenant */
        post: operations["lancerTestDuMatin"];
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
        AdresseIp: {
            /** @description Adresse IP. En démo, plages de documentation seulement (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24). */
            adresse: string;
            domaine_id: components["schemas"]["Identifiant"];
            envoyes_aujourdhui: number;
            flux: components["schemas"]["FluxEnvoi"];
            statut: components["schemas"]["StatutBoite"];
        };
        /**
         * @description Boîte d'envoi. Elle n'a plus de chauffe propre : la montée en douceur se
         *     lit par domaine et par adresse IP (`GET /envoi/chauffe`). Jamais d'identifiants
         *     chiffrés, d'hôte ni de port dans la liste ni dans la fiche.
         */
        BoiteResume: {
            /** Format: email */
            adresse: string;
            /** @description Identifiant du domaine de la boîte ; nul si ce domaine n'est pas enregistré dans l'espace. */
            domaine_id: components["schemas"]["Identifiant"] | null;
            envoyes_aujourdhui: number;
            etat: components["schemas"]["EtatFeu"];
            id: components["schemas"]["Identifiant"];
            nom_affiche: string;
            quota_jour: number;
            reponses_7j: number;
            statut: components["schemas"]["StatutBoite"];
            /** @description Refus (durs et mous) sur 7 jours, en pourcentage ; nul tant qu'il n'est pas mesurable (aucun envoi, ou source des refus mous absente). */
            taux_refus: number | null;
        };
        /** @description Position d'un domaine dans la montée en douceur. */
        Chauffe: {
            jours_effectifs: number;
            palier: components["schemas"]["PalierChauffe"];
            /** @description Date jusqu'à laquelle le palier est tenu (frein, pause manuelle ou inactivité), sinon nul. */
            tenu_jusqu_au: string | null;
            total_palier: number;
        };
        CourbeResultats: {
            data: {
                /** Format: date */
                jour: string;
                rebonds: number;
                remis: number;
                reponses: number;
            }[];
        };
        DomaineResume: {
            ajoute_le: components["schemas"]["Horodatage"];
            boites: number;
            chauffe: components["schemas"]["Chauffe"] | null;
            /** @description Politique DMARC publiée ; nulle tant qu'elle n'est pas connue. */
            dmarc: components["schemas"]["PolitiqueDmarc"] | null;
            /** @description DMARC progressif — prochaine politique et date prévue. */
            dmarc_prochaine_etape: {
                le: components["schemas"]["Horodatage"];
                politique: components["schemas"]["PolitiqueDmarc"];
            } | null;
            etat: components["schemas"]["EtatFeu"];
            id: components["schemas"]["Identifiant"];
            listes_noires: number;
            nom: string;
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
        /** @enum {string} */
        FluxEnvoi: "prospection" | "lettre";
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
         * @description Définition unique, la même que celle des freins : `remis` = `envoyes` moins `rebonds_durs` moins `rebonds_mous`
         *     (jamais négatif) ; le taux de réponse se calcule sur les envoyés.
         */
        LigneResultat: {
            cle: string;
            desinscriptions: number;
            envoyes: number;
            libelle: string;
            plaintes: number;
            rebonds_durs: number;
            rebonds_mous: number;
            remis: number;
            reponses: number;
            reponses_interessees: number;
            /** @description Version du message type (regroupement par message). */
            version?: number;
        };
        /**
         * @description Palier de la montée en douceur (P1 le plus bas, P8 le plus haut).
         * @enum {string}
         */
        PalierChauffe: "P1" | "P2" | "P3" | "P4" | "P5" | "P6" | "P7" | "P8";
        PointFeuVert: {
            /** @description Vrai exactement quand `fait` est faux. */
            bloquant: boolean;
            /** @description Préfixé `auto.` (calculé) ou `manuel.` (preuve humaine). */
            code: string;
            detail: string | null;
            /** @enum {string} */
            etat: "fait" | "a_faire" | "attend_serveur_reel";
            /** @description Vrai exactement quand `etat` vaut `fait` (jamais pour `attend_serveur_reel`, preuve ou non). */
            fait: boolean;
            /** @enum {string} */
            groupe: "verrous" | "banc" | "dns" | "serveur" | "temoins" | "conformite" | "exploitation";
            libelle: string;
            /** @enum {string} */
            nature: "auto" | "manuel";
            preuve: {
                le: components["schemas"]["Horodatage"];
                par: string;
                texte: string;
            } | null;
            /** @description Référence au document de cadrage (ex. « 26§8.5 »). */
            source: string;
        };
        /** @enum {string} */
        PolitiqueDmarc: "none" | "quarantine" | "reject";
        RechercheEnvoi: {
            data: {
                boite: string;
                /** @enum {string} */
                classe: "reponse_humaine" | "auto_reponse" | "desinscription" | "hostile" | "rebond_dur" | "rebond_mou";
                recu_le: components["schemas"]["Horodatage"];
            }[];
        };
        Resultats: {
            data: components["schemas"]["LigneResultat"][];
            /**
             * @description `rendez_vous` et `gagnes` valent null tant que le lien avec le CRM n'est pas défini
             *     (on ne les invente pas).
             */
            entonnoir: {
                envoyes: number;
                gagnes: number | null;
                rendez_vous: number | null;
                reponses: number;
            };
            /** @description Jours civils d'Europe/Paris, bornes comprises. */
            periode: {
                /** Format: date */
                au: string;
                /** Format: date */
                du: string;
            };
            /** @description Totaux de la période d'avant, de même durée (pour la comparaison). */
            precedent: components["schemas"]["LigneResultat"];
            totaux: components["schemas"]["LigneResultat"];
        };
        ServeurResume: {
            adresses_ip: components["schemas"]["AdresseIp"][];
            etat: components["schemas"]["EtatFeu"];
            id: components["schemas"]["Identifiant"];
            nom: string;
            /** @description Version de configuration appliquée ; nulle si aucune n'a été appliquée. */
            version_configuration: number | null;
        };
<<<<<<< ours
        /** @enum {string} */
        StatutBoite: "prete" | "ralentie" | "en_chauffe" | "suspendue";
=======
        /**
         * @description Statut d'une boîte ou d'une adresse IP ; `inconnu` (ajouté de façon compatible) quand la santé n'est pas mesurable.
         * @enum {string}
         */
        StatutBoite: "prete" | "ralentie" | "en_chauffe" | "suspendue" | "inconnu";
        /** @enum {string} */
        TypeEvenementEnvoi: "remis" | "rebond_dur" | "rebond_mou" | "plainte" | "desinscription" | "reponse";
>>>>>>> theirs
        /** @description État des verrous d'envoi, en lecture seule. */
        VerrousFeuVert: {
            envoi_reel_actif: boolean;
            fournisseurs_temoins_couverts: number;
            /** @constant */
            fournisseurs_temoins_requis: 6;
            mode_temoins: boolean;
            signature_date: string | null;
            signature_presente: boolean;
        };
    };
    responses: {
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
    parameters: never;
    requestBodies: never;
    headers: never;
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
    chercherEnvoi: {
        parameters: {
            query: {
                adresse: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Retours reçus pour cette adresse (au plus 50, du plus récent au plus ancien). */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["RechercheEnvoi"];
                };
            };
            /** @description Adresse absente ou invalide. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Plus de 10 recherches dans la minute. */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
        };
    };
    lireFeuVert: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Points de contrôle, bloquants restants et verrous. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        bloquants_restants: number;
                        faits: number;
                        points: components["schemas"]["PointFeuVert"][];
                        total: number;
                        verrous: components["schemas"]["VerrousFeuVert"];
                    };
                };
            };
        };
    };
    marquerPointFeuVert: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                code: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @default true */
                    fait?: boolean;
                    /** @description Preuve humaine, ou motif quand `fait` vaut false : 10 à 500 caractères après nettoyage, ni phrase générique ni adresse e-mail. */
                    preuve: string;
                };
            };
        };
        responses: {
            /** @description Le point à jour. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["PointFeuVert"];
                };
            };
            403: components["responses"]["Erreur"];
            404: components["responses"]["Erreur"];
            422: components["responses"]["Erreur"];
        };
    };
    lireResultats: {
        parameters: {
            query: {
                jours?: 7 | 30 | 90;
                par: "campagne" | "boite" | "domaine" | "liste" | "message";
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Une ligne par groupe. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Resultats"];
                };
            };
        };
    };
    lireCourbeResultats: {
        parameters: {
            query?: {
                campagne_id?: components["schemas"]["Identifiant"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Points par jour. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["CourbeResultats"];
                };
            };
        };
    };
    lireSante: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Feux par boîte, domaine et serveur, et test du matin. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        alertes: number;
                        boites: components["schemas"]["BoiteResume"][];
                        domaines: components["schemas"]["DomaineResume"][];
                        envoyes_14_jours: {
                            envoyes: number;
                            /** Format: date */
                            jour: string;
                        }[];
                        feu: components["schemas"]["EtatFeu"];
                        /** @description Pauses d'envoi actives (champ facultatif, ajouté de façon compatible). Aucune = liste vide. */
                        pauses?: {
                            /** @description Fin prévue de la pause ; nulle si elle dure jusqu'à une levée humaine. */
                            fin: components["schemas"]["Horodatage"] | null;
                            /** @description Famille de messagerie visée ; nulle pour une pause globale. */
                            fournisseur: ("gmail" | "microsoft" | "autre") | null;
                            /** @description Code du frein ou de la pause manuelle (ex. `indesirable_chez_un_temoin`). */
                            motif: string;
                            /** @enum {string} */
                            portee: "global" | "fournisseur";
                        }[];
                        rythme_messageries: {
                            envoyes: number;
                            /** @enum {string} */
                            etat: "normal" | "freine";
                            messagerie: string;
                            /** @description Rythme mesuré sur la dernière heure ; nul tant qu'il n'est pas mesuré. */
                            par_heure: number | null;
                        }[];
                        serveurs: components["schemas"]["ServeurResume"][];
                        test_du_matin: {
                            etat: components["schemas"]["EtatFeu"];
                            /** @description Date du dernier test ; nulle tant qu'aucun test n'a été fait (l'état est alors « gris »). */
                            le: components["schemas"]["Horodatage"] | null;
                            temoins_ok: number;
                            temoins_total: number;
                        };
                        /** @description Dernier relevé de santé reçu ; nul tant qu'aucun relevé n'existe. */
                        verifie_le: components["schemas"]["Horodatage"] | null;
                        volume_jour: {
                            envoyes: number;
                            possibles: number;
                        };
                    };
                };
            };
        };
    };
    lancerTestDuMatin: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Résultat du test. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        etat: components["schemas"]["EtatFeu"];
                        le: components["schemas"]["Horodatage"];
                        temoins_ok: number;
                        temoins_total: number;
                    };
                };
            };
        };
    };
}
