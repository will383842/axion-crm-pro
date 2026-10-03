/**
 * Un libellé lisible suivi de son code en petit (« SAS, société par actions
 * simplifiée (5710) »). Sans libellé connu : le code seul, jamais une
 * invention. Sans code : « — ».
 */
export function LibelleEtCode({
  libelle,
  code,
  infobulle,
  codeVisible = true,
}: {
  libelle: string | null;
  code: string | null | undefined;
  infobulle: string;
  /** `false` : le code ne figure qu'en infobulle (l'effectif « 03 » n'apprend rien). */
  codeVisible?: boolean;
}) {
  const brut = code?.trim() ?? '';
  if (brut === '') return <>—</>;
  if (libelle === null || libelle === '') return <span className="font-mono">{brut}</span>;
  return (
    <span title={`${infobulle} : ${brut}`}>
      {libelle}
      {codeVisible ? (
        <span className="ml-1.5 font-mono text-xs text-slate-500 dark:text-slate-400">({brut})</span>
      ) : null}
    </span>
  );
}
