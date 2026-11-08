-- K5 : le SERVEUR signe en DKIM (ADR-K0 D3). Le CRM ne detient aucune cle privee.
-- Aucune valeur reelle ici : les cles sont lues sur disque (/etc/axion-envoi/dkim, monte en
-- lecture seule) ; le domaine vient du From, le selecteur du fichier « principal » ecrit par
-- dkim/generer-dkim.sh. Echec ferme : sans cle lisible, le message est REFUSE (451), jamais
-- envoye non signe.
local kumo = require 'kumo'

local REP_CLES = '/etc/axion-envoi/dkim'

-- MEMES listes que dkim/entetes-signes.txt et dkim/entetes-sursignes.txt (test statique
-- tests-dkim.sh) et que App\Envoi\Message\DkimSigneur::ENTETES_SIGNES / ENTETES_SURSIGNES.
local ENTETES_SIGNES = {
  'From',
  'Reply-To',
  'To',
  'Subject',
  'Date',
  'Message-ID',
  'MIME-Version',
  'Content-Type',
  'List-Unsubscribe',
  'List-Unsubscribe-Post',
}
-- Sur-signes : leur nom est repete en fin de h= (aucune 2e occurrence ajoutable apres coup).
local ENTETES_SURSIGNES = {
  'From',
  'Subject',
  'List-Unsubscribe',
}

local function liste_h()
  local h = {}
  for _, n in ipairs(ENTETES_SIGNES) do h[#h + 1] = n end
  for _, n in ipairs(ENTETES_SURSIGNES) do h[#h + 1] = n end
  return h
end

local function lire_selecteur(domaine)
  local f = io.open(REP_CLES .. '/' .. domaine .. '/principal', 'r')
  if not f then return nil end
  local s = f:read('*l')
  f:close()
  if s and s:match('^[a-z0-9][a-z0-9-]*$') then return s end
  return nil
end

local function signer(msg)
  local from = msg:from_header()
  local domaine = from and from.domain and string.lower(from.domain) or ''
  -- chemin construit depuis le From : forme stricte uniquement (pas de « .. », pas de « / »).
  if not domaine:match('^[a-z0-9][a-z0-9.-]*[a-z0-9]$') or domaine:find('..', 1, true) then
    kumo.reject(550, '5.7.1 domaine du From invalide pour la signature DKIM')
  end
  local selecteur = lire_selecteur(domaine)
  if not selecteur then
    kumo.reject(451, '4.7.0 cle DKIM indisponible : message non envoye')
  end
  local signeur = kumo.dkim.rsa_sha256_signer {
    domain = domaine,
    selector = selecteur,
    headers = liste_h(),
    key = REP_CLES .. '/' .. domaine .. '/' .. selecteur .. '.pem',
  }
  msg:dkim_sign(signeur)
end

kumo.on('smtp_server_message_received', function(msg) signer(msg) end)
kumo.on('http_message_generated', function(msg) signer(msg) end)

return { ENTETES_SIGNES = ENTETES_SIGNES, ENTETES_SURSIGNES = ENTETES_SURSIGNES }
