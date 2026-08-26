<?php

namespace App\Http\Requests;

use App\Crm\Taxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création d'un contact presse.
 *
 * ── Ce que ce formulaire n'accepte PAS, et pourquoi ────────────────────────
 * `opt_out` est absent des règles, volontairement. Il a son propre point
 * d'entrée (`POST /journalists/{id}/opt-out`), qui ne se contente pas de
 * basculer la colonne : il émet aussi vers le site, via `ConsentOutboundRecorder`,
 * pour que l'opposition converge des deux côtés. L'exposer ici en donnerait un
 * second chemin, MUET — la personne serait opposée dans le CRM et le site
 * continuerait de l'adresser, jusqu'à ce que la prochaine synchro la
 * « rouvre ». Un champ de plus dans un tableau de règles suffit à créer ce
 * trou ; son absence est la garde.
 *
 * `dedup_key` est absent aussi : c'est une colonne GENERATED, la base la
 * calcule. L'accepter en entrée laisserait un client la contredire.
 */
class StoreJournalistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Au moins un nom. La table tolère deux NULL (héritage du scraping
            // d'émissions), mais une fiche sans aucun nom n'est pas un contact :
            // elle ne peut ni être cherchée, ni être dédoublonnée, ni servir à
            // adresser quelqu'un.
            'first_name' => ['nullable', 'string', 'max:120', 'required_without:last_name'],
            'last_name'  => ['nullable', 'string', 'max:120', 'required_without:first_name'],

            'role'  => ['nullable', 'string', 'max:160'],
            'beat'  => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],

            // Rattachement média : soit un `media_id` vérifié, soit la chaîne
            // brute. Jamais un nom de média inventé à la volée — c'est ce qui
            // salirait la base des 55 830 médias.
            'media_id'  => ['nullable', 'integer', 'exists:media,id'],
            'media_raw' => ['nullable', 'string', 'max:500'],

            // URL complète en entrée, slug normalisé en sortie (le contrôleur
            // appelle `LinkedinSlug::normalize`). On valide donc large ici et
            // on refuse au moment de la normalisation, où le message peut dire
            // POURQUOI (page entreprise, autre domaine, URL illisible).
            'linkedin_url' => ['nullable', 'string', 'max:500'],

            'acces'         => ['nullable', Rule::in(Taxonomy::ACCES_PRESSE)],
            'lien_linkedin' => ['sometimes', Rule::in(Taxonomy::LIENS_LINKEDIN)],
            // Une date de mise en relation dans le futur est une saisie fautive,
            // pas un état possible.
            'lien_linkedin_le' => ['nullable', 'date', 'before_or_equal:today'],

            'priorite'    => ['nullable', 'integer', 'between:1,4'],
            'score'       => ['nullable', 'integer', 'between:0,200'],
            'abonnes'     => ['nullable', 'integer', 'min:0'],
            'collecte_le' => ['nullable', 'date', 'before_or_equal:today'],

            'media_portee_raw'  => ['nullable', 'string', 'max:80'],
            'media_support_raw' => ['nullable', 'string', 'max:80'],

            // Traçabilité RGPD : d'où vient la donnée. `source_url` n'est pas
            // décoratif — c'est ce qui permet de répondre « d'où tenez-vous
            // cela ? » à une personne qui exerce son droit d'information.
            'source'     => ['nullable', 'string', 'max:40'],
            'source_url' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required_without' => 'Un contact doit porter au moins un nom ou un prénom.',
            'last_name.required_without'  => 'Un contact doit porter au moins un nom ou un prénom.',
            'media_id.exists'             => "Ce média n'existe pas dans la base médias.",
            'lien_linkedin_le.before_or_equal' => 'Une mise en relation ne peut pas être datée dans le futur.',
        ];
    }
}
