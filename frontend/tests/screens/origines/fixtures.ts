/** Fixtures FICTIVES (dépôt public) : adresses en `exemple.invalid`. */
export const ME = (permissions: string[], roles: string[] = ['operator']) => ({
  user: { id: 1, email: 'proprietaire@exemple.invalid', name: 'Propriétaire (exemple)' },
  roles,
  permissions,
  workspace: { id: 'w', name: 'Espace exemple' },
});

export const TOUTES = ['data.export', 'contacts.view_pii'];

export const APERCU = {
  actif: true,
  personnes: 1248,
  demandes: 1322,
  accords: 702,
  opposees: 17,
  par_famille: [
    { famille: 'guide_reseau', demandes: 612, personnes: 600 },
    { famille: 'cas_concret', demandes: 214, personnes: 200 },
    { famille: null, demandes: 19, personnes: 19 },
  ],
};

export const PAR_RESEAU = {
  actif: true,
  data: [
    { reseau: 'instagram', canal: 'commentaire', compte: 'page', demandes: 402, personnes: 400 },
    { reseau: null, canal: null, compte: null, demandes: 322, personnes: 322 },
  ],
};

export const PAR_MOT = {
  actif: true,
  data: [{ mot: 'GUIDE', famille: 'guide_reseau', detail: 'commentaire_mot', demandes: 380, personnes: 375 }],
  meta: { per_page: 25, page: 1, total: 1, has_more: false },
};

export const TENDANCE = {
  actif: true,
  data: [
    { jour: '2026-11-03', demandes: 16 },
    { jour: '2026-11-04', demandes: 19 },
  ],
};

export const PERSONNES = {
  actif: true,
  data: [
    {
      id: 2, email: 'exe•••@•••.invalid', first_name: 'Personne', last_name: 'exemple 02', entreprise: null,
      demandes: 2,
      derniere_origine: { famille: 'guide_reseau', detail: 'message_prive', reseau: 'facebook', mot: 'GUIDE', date: '2026-11-02T10:02:00Z', post_ref: 'pr_Hd83LmQ2xW' },
    },
  ],
  meta: { per_page: 25, next_cursor: null, has_more: false },
};

export const HISTORIQUE = {
  actif: true,
  data: [
    { famille: 'guide_reseau', detail: 'commentaire_mot', canal: 'commentaire', reseau: 'instagram', compte: 'page', mot: 'GUIDE', guide: 'guide-a', date: '2026-11-02T14:07:00Z', post_ref: 'pr_Xk29aF7Qm1', post_url: 'https://reseau.exemple.invalid/p/1', version_texte: null, utm_campaign: null },
    { famille: null, detail: null, canal: null, reseau: null, compte: null, mot: null, guide: null, date: '2026-10-08T18:20:00Z', post_ref: null, post_url: null, version_texte: null, utm_campaign: null },
  ],
  meta: { total: 2, par_page: 50, suivant: null },
};
