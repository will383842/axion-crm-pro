/**
 * Types du contrat d'API d'Envoi, zone « infrastructure ».
 * FICHIER GÉNÉRÉ par `pnpm api:envoi` depuis `backend/openapi/envoi/infrastructure.yaml`.
 * Ne pas modifier à la main : modifier la description, puis régénérer.
 */
export interface paths {
    "/envoi/boites": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Boîtes d'envoi */
        get: operations["listerBoites"];
        put?: never;
        /**
         * Ajouter une boîte (non disponible au palier 0)
         * @description Au palier 0, une boîte s'ajoute par commande locale sur le serveur
         *     (les identifiants ne transitent jamais par l'écran) : la route répond 501.
         */
        post: operations["ajouterBoite"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/boites/{id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Fiche d'une boîte */
        get: operations["lireBoite"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/boites/{id}/reactiver": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Réactiver une boîte suspendue
         * @description Permission requise, `workspaces.manage` (403 sinon). Refusée (409) tant
         *     qu'une pause de frein ou une pause automatique est active.
         */
        post: operations["reactiverBoite"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/boites/{id}/reglages": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        /** Enregistrer la signature et le mode absence d'une boîte */
        put: operations["enregistrerReglagesBoite"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/boites/{id}/suspendre": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Suspendre une boîte
         * @description Permission requise, `users.manage` (403 sinon).
         */
        post: operations["suspendreBoite"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/boites/test-connexion": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Tester la connexion d'une boîte avant de l'ajouter (non disponible au palier 0)
         * @description Au palier 0, l'ajout se fait par commande locale, la route répond 501.
         */
        post: operations["testerConnexionBoite"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/chauffe": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Chauffe des domaines et des adresses IP (montée en douceur par palier) */
        get: operations["lireChauffe"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/chauffe/{id}/reculer": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Reculer une ressource d'un palier
         * @description `id` est l'identifiant opaque d'une ressource de chauffe (ex. `domaine:<ref>`),
         *     pas l'identifiant d'une boîte. Impossible en P1 (409). Un second recul le même
         *     jour ne recule pas une seconde fois. Il n'existe aucune route pour avancer.
         */
        post: operations["reculerChauffe"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/configuration": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Configuration versionnée (courante et prête) */
        get: operations["lireConfiguration"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/configuration/appliquer": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Appliquer la version prête */
        post: operations["appliquerConfiguration"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/configuration/versions/{numero}/revenir": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Revenir à une version précédente */
        post: operations["revenirConfiguration"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/domaines": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Domaines d'envoi */
        get: operations["listerDomaines"];
        put?: never;
        /** Ajouter un domaine (rend les enregistrements DNS à créer) */
        post: operations["ajouterDomaine"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/domaines/{id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Fiche d'un domaine sur 30 jours */
        get: operations["lireDomaine"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/domaines/{id}/dmarc": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** DMARC progressif — passer à la politique suivante ou poser un rappel */
        post: operations["avancerDmarc"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/domaines/{id}/dns/verifier": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Vérifier les enregistrements DNS */
        post: operations["verifierDns"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/listes-noires": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Présence des domaines et adresses IP sur les listes noires */
        get: operations["listerListesNoires"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/serveurs": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Serveurs et adresses IP */
        get: operations["listerServeurs"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/serveurs/{id}/adresses-ip": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Ajouter une adresse IP (prépare une nouvelle version de configuration) */
        post: operations["ajouterAdresseIp"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/serveurs/{id}/sante": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Santé d'un serveur d'envoi, file et paliers de chauffe de ses adresses IP */
        get: operations["lireSanteServeur"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/serveurs/{id}/test": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Lancer le test du serveur (boîtes témoins internes, aucun envoi réel) */
        post: operations["lancerTestServeur"];
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
        /** @enum {string} */
        ClassementReponse: "interesse" | "pas_maintenant" | "question" | "refus" | "stop" | "absence" | "a_rattacher";
        Configuration: {
            /** @description Nulle tant qu'aucune version n'a été appliquée. */
            courante: components["schemas"]["VersionConfiguration"] | null;
            historique: components["schemas"]["VersionConfiguration"][];
            prete: components["schemas"]["VersionConfiguration"] | null;
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
        EnregistrementDns: {
            /** @enum {string} */
            etat: "ok" | "absent" | "different";
            nom: string;
            /**
             * @description Ce que l'enregistrement sert à faire.
             * @enum {string}
             */
            role: "spf" | "dkim" | "dmarc" | "ptr" | "mta_sts";
            /** @enum {string} */
            type: "TXT" | "CNAME" | "MX" | "PTR";
            valeur: string;
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
        EvenementEnvoi: {
            /** Format: email */
            adresse: string;
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
        /** @enum {string} */
        FluxEnvoi: "prospection" | "lettre";
        /** @description Le terme qui limite le budget du jour. */
        GoulotChauffe: {
            libelle: string;
            ressource_id: string | null;
            terme: components["schemas"]["TermeGoulot"];
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
        JourActivite: {
            envoyes: number;
            /** Format: date */
            jour: string;
            plaintes: number;
            rebonds_durs: number;
            rebonds_mous: number;
            remis: number;
            reponses: number;
        };
        ListeNoire: {
            element: string;
            /** @description Adresse de la demande de retrait de la liste (vide si l'élément est propre). */
            lien_retrait: string | null;
            liste: string;
            present: boolean;
            /** @description Fiche « que faire » en français (vide si l'élément est propre). */
            que_faire: string | null;
            verifie_le: components["schemas"]["Horodatage"];
        };
        /** @description Pendant l'absence, la boîte n'envoie plus et les réponses automatiques sont mises de côté. */
        ModeAbsence: {
            actif: boolean;
            jusqu_au: string | null;
            message: string | null;
        };
        /**
         * @description Palier de la montée en douceur (P1 le plus bas, P8 le plus haut).
         * @enum {string}
         */
        PalierChauffe: "P1" | "P2" | "P3" | "P4" | "P5" | "P6" | "P7" | "P8";
        PalierTable: {
            jours_max: number;
            jours_min: number;
            palier: components["schemas"]["PalierChauffe"];
            total_jour: number;
        };
        /** @enum {string} */
        PolitiqueDmarc: "none" | "quarantine" | "reject";
        ReglagesBoite: {
            mode_absence: components["schemas"]["ModeAbsence"];
            signature: string;
        };
        ReponseResume: {
            boite_id: components["schemas"]["Identifiant"];
            campagne_id: components["schemas"]["Identifiant"] | null;
            classement: components["schemas"]["ClassementReponse"];
            /** Format: email */
            de: string;
            destinataire_id: components["schemas"]["Identifiant"] | null;
            extrait: string;
            id: components["schemas"]["Identifiant"];
            nom: string | null;
            objet: string;
            organisation: string | null;
            prenom: string | null;
            recue_le: components["schemas"]["Horodatage"];
            suggestion: {
                classement: components["schemas"]["ClassementReponse"];
                confiance: number;
            };
            traitee: boolean;
        };
        RessourceChauffe: {
            envoyes_aujourdhui: number;
            /** @description Identifiant opaque (ex. `domaine:<ref>`). */
            id: string;
            jours_avant_palier_suivant: number | null;
            jours_effectifs: number;
            libelle: string;
            motif_tenue: ("frein" | "manuel" | "inactivite") | null;
            palier: components["schemas"]["PalierChauffe"];
            peut_reculer: boolean;
            tenu_jusqu_au: string | null;
            total_palier: number;
            /** @enum {string} */
            type: "ip" | "domaine";
        };
        SanteServeur: {
            /** @description Messages en attente d'envoi sur ce serveur (nul si non mesuré). */
            en_file: number | null;
            /** @description Nul si non mesuré. */
            en_ligne_depuis_jours: number | null;
            /** @description Paliers de chauffe des adresses IP en montée. */
            paliers: {
                adresse: string;
                jour: number;
                plafond_aujourdhui: number;
                sur: number;
            }[];
            serveur_id: components["schemas"]["Identifiant"];
            /** @description Dernier test du serveur (nul s'il n'est pas conservé). */
            test: {
                etapes: {
                    libelle: string;
                    ok: boolean;
                }[];
                fait_le: components["schemas"]["Horodatage"];
            } | null;
        };
        ServeurResume: {
            adresses_ip: components["schemas"]["AdresseIp"][];
            etat: components["schemas"]["EtatFeu"];
            id: components["schemas"]["Identifiant"];
            nom: string;
            /** @description Version de configuration appliquée ; nulle si aucune n'a été appliquée. */
            version_configuration: number | null;
        };
        /**
         * @description Statut d'une boîte ou d'une adresse IP ; `inconnu` (ajouté de façon compatible) quand la santé n'est pas mesurable.
         * @enum {string}
         */
        StatutBoite: "prete" | "ralentie" | "en_chauffe" | "suspendue" | "inconnu";
        /** @enum {string} */
        TermeGoulot: "courbe_ip" | "courbe_domaine" | "plafond_boites" | "plafond_serveur" | "capacite_reponse";
        /** @description Valeur de chaque terme du budget (nul si le terme ne s'applique pas). */
        TermesChauffe: {
            capacite_reponse: number | null;
            courbe_domaine: number | null;
            courbe_ip: number | null;
            plafond_boites: number | null;
            plafond_serveur: number | null;
        };
        /** @enum {string} */
        TypeEvenementEnvoi: "remis" | "rebond_dur" | "rebond_mou" | "plainte" | "desinscription" | "reponse";
        VersionConfiguration: {
            appliquee_le: components["schemas"]["Horodatage"] | null;
            changements: {
                libelle: string;
                /** @enum {string} */
                sorte: "ajout" | "retrait" | "modification";
            }[];
            creee_le: components["schemas"]["Horodatage"];
            /** @description Empreinte SHA-256 du paquet (jamais son contenu). */
            empreinte?: string;
            numero: number;
            /** @description Qui a appliqué la version (prénom, ou « Système »). */
            par: string | null;
            /** @enum {string} */
            resultat: "prete" | "appliquee" | "remplacee" | "retour_automatique" | "invalide" | "retour_arriere" | "derive_refusee" | "incertaine";
        };
    };
    responses: {
        /** @description Boîte après le geste. */
        Boite: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["BoiteResume"];
            };
        };
        /** @description Configuration versionnée. */
        Configuration: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Configuration"];
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
    };
    requestBodies: never;
    headers: never;
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
    listerBoites: {
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
                        data: components["schemas"]["BoiteResume"][];
                    };
                };
            };
        };
    };
    ajouterBoite: {
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
                    domaine_id: components["schemas"]["Identifiant"];
                    mode_absence?: components["schemas"]["ModeAbsence"];
                    nom_affiche: string;
                    /** @description Signature ajoutée en bas des messages de cette boîte. */
                    signature?: string;
                };
            };
        };
        responses: {
            201: components["responses"]["Boite"];
            422: components["responses"]["Erreur"];
            501: components["responses"]["Erreur"];
        };
    };
    lireBoite: {
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
            /** @description Boîte, 30 jours d'activité et derniers refus. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        boite: components["schemas"]["BoiteResume"];
                        derniers_refus: components["schemas"]["EvenementEnvoi"][];
                        jours: components["schemas"]["JourActivite"][];
                        reglages: components["schemas"]["ReglagesBoite"];
                        reponses: components["schemas"]["ReponseResume"][];
                    };
                };
            };
            404: components["responses"]["Erreur"];
        };
    };
    reactiverBoite: {
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
            200: components["responses"]["Boite"];
            403: components["responses"]["Erreur"];
            404: components["responses"]["Erreur"];
            409: components["responses"]["Erreur"];
        };
    };
    enregistrerReglagesBoite: {
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
                "application/json": components["schemas"]["ReglagesBoite"];
            };
        };
        responses: {
            /** @description Réglages enregistrés. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ReglagesBoite"];
                };
            };
            404: components["responses"]["Erreur"];
        };
    };
    suspendreBoite: {
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
            200: components["responses"]["Boite"];
            403: components["responses"]["Erreur"];
            404: components["responses"]["Erreur"];
        };
    };
    testerConnexionBoite: {
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
                    domaine_id: components["schemas"]["Identifiant"];
                };
            };
        };
        responses: {
            /** @description Résultat du test, étape par étape. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        etapes: {
                            libelle: string;
                            ok: boolean;
                        }[];
                        reussi: boolean;
                    };
                };
            };
            501: components["responses"]["Erreur"];
        };
    };
    lireChauffe: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Budget du jour, goulot, ressources en chauffe et table des paliers. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @description Nombre d'envois permis aujourd'hui, tous termes confondus. */
                        budget_du_jour: number;
                        goulot: components["schemas"]["GoulotChauffe"];
                        /** Format: date */
                        jour: string;
                        paliers: components["schemas"]["PalierTable"][];
                        /** @description Plafond d'envois par boîte et par jour (150 en V0). */
                        plafond_boite: number;
                        ressources: components["schemas"]["RessourceChauffe"][];
                        termes: components["schemas"]["TermesChauffe"];
                    };
                };
            };
        };
    };
    reculerChauffe: {
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
            /** @description La ressource après le recul. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["RessourceChauffe"];
                };
            };
            404: components["responses"]["Erreur"];
            409: components["responses"]["Erreur"];
        };
    };
    lireConfiguration: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["Configuration"];
        };
    };
    appliquerConfiguration: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["Configuration"];
            409: components["responses"]["Erreur"];
        };
    };
    revenirConfiguration: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                numero: number;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["Configuration"];
        };
    };
    listerDomaines: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Domaines. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["DomaineResume"][];
                        /** @description Date d'expiration de chaque domaine (identifiant du domaine -> date). */
                        expirations: {
                            [key: string]: components["schemas"]["Horodatage"];
                        };
                        /**
                         * @description Garde anti-dispersion : nombre de domaines utilisés et limite
                         *     (trois domaines par serveur d'envoi). Au-delà, l'ajout est refusé.
                         */
                        garde_dispersion: {
                            limite: number;
                            utilises: number;
                        };
                    };
                };
            };
        };
    };
    ajouterDomaine: {
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
            /** @description Domaine et enregistrements à créer. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        dns: components["schemas"]["EnregistrementDns"][];
                        domaine: components["schemas"]["DomaineResume"];
                    };
                };
            };
            422: components["responses"]["Erreur"];
        };
    };
    lireDomaine: {
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
            /** @description Domaine, DNS, 30 jours, listes noires. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        dns: components["schemas"]["EnregistrementDns"][];
                        domaine: components["schemas"]["DomaineResume"];
                        /** @description Nul si la date d'expiration n'est pas conservée. */
                        expire_le: components["schemas"]["Horodatage"] | null;
                        jours: components["schemas"]["JourActivite"][];
                        listes_noires: components["schemas"]["ListeNoire"][];
                    };
                };
            };
            404: components["responses"]["Erreur"];
        };
    };
    avancerDmarc: {
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
                    politique: components["schemas"]["PolitiqueDmarc"];
                    rappel_le?: components["schemas"]["Horodatage"];
                };
            };
        };
        responses: {
            /** @description Domaine après le geste. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["DomaineResume"];
                };
            };
        };
    };
    verifierDns: {
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
            /** @description État de chaque enregistrement. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        dns: components["schemas"]["EnregistrementDns"][];
                    };
                };
            };
        };
    };
    listerListesNoires: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Une ligne par liste et par élément surveillé. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["ListeNoire"][];
                    };
                };
            };
        };
    };
    listerServeurs: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Serveurs. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["ServeurResume"][];
                    };
                };
            };
        };
    };
    ajouterAdresseIp: {
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
                    adresse: string;
                    domaine_id: components["schemas"]["Identifiant"];
                    flux: components["schemas"]["FluxEnvoi"];
                };
            };
        };
        responses: {
            200: components["responses"]["Configuration"];
            422: components["responses"]["Erreur"];
        };
    };
    lireSanteServeur: {
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
            /** @description Santé du serveur. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["SanteServeur"];
                };
            };
            404: components["responses"]["Erreur"];
        };
    };
    lancerTestServeur: {
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
            /** @description Santé du serveur après le test. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["SanteServeur"];
                };
            };
            404: components["responses"]["Erreur"];
        };
    };
}
