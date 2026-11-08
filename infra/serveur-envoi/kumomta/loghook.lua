-- K7 : log hook KumoMTA -> relais LOCAL (127.0.0.1) -> passerelle. Lecture seule : aucun envoi, aucune action.
-- Le relais classe (regles TOML validees en CI) ; la passerelle numerote. Si le relais est injoignable, on leve
-- une erreur : KumoMTA reessaie l'enregistrement (rien n'est perdu, la passerelle dedoublonne).
--
-- DETTE K7-D2 (à prouver au banc K10) : ce fichier est ecrit d'apres la documentation de reference de KumoMTA
-- (configure_log_hook, should_enqueue_log_record, get_queue_config, custom_lua), sans KumoMTA reel ici : seul un test
-- STATIQUE le garde. A prouver au banc K10 : (1) un seul handler `get_queue_config` coexiste avec ceux d'init.lua,
-- (2) la garde anti-boucle suffit, (3) le 451 declenche bien un nouvel essai, (4) la meta `espace` arrive au relais.
local kumo = require 'kumo'

local RELAIS = 'http://127.0.0.1:2580/'
local FILE = 'axion_loghook'

local M = {}

-- Appele UNE fois par init.lua depuis SON handler `init` (jamais un second handler `init` ici : cet
-- evenement n'en accepte qu'un). `espace` = espace de la session d'injection K4, remonte pour ne jamais melanger.
function M.configurer()
  kumo.configure_log_hook {
    name = FILE,
    meta = { 'id_injection', 'jeton', 'espace' },
    per_record = {
      Reception = { enable = true },
      Delivery = { enable = true },
      Bounce = { enable = true },
      TransientFailure = { enable = true },
      Expiration = { enable = true },
      Any = { enable = false },
    },
  }
end

-- Renvoie TOUJOURS true ou false (jamais nil). Garde anti-boucle : un enregistrement de la file du hook lui-meme
-- n'est jamais re-journalise vers le hook.
kumo.on('should_enqueue_log_record', function(msg, hook_name)
  if hook_name ~= FILE then
    return false
  end
  local log_record = msg:get_meta 'log_record'
  if log_record.queue ~= 'axion_loghook' then
    msg:set_meta('queue', FILE)
    return true
  end
  return false
end)

kumo.on('get_queue_config', function(domain, tenant, campaign, routing_domain)
  if domain == FILE then
    return kumo.make_queue_config {
      protocol = { custom_lua = { constructor = 'make.axion_loghook' } },
    }
  end
  -- Autres files : pas de reponse ici, KumoMTA applique sa configuration par defaut.
end)

kumo.on('make.axion_loghook', function(domain, tenant, campaign)
  local connection = {}
  local client = kumo.http.build_client {}
  function connection:send(message)
    local r = client:post(RELAIS):header('Content-Type', 'application/json'):body(message:get_data()):send()
    if r:status_code() == 200 then
      return
    end
    if r:status_code() == 400 then
      return -- refus DEFINITIF (enregistrement mal forme) : on n'insiste pas, sinon relance sans fin
    end
    kumo.reject(451, 'relais du log hook indisponible') -- reessai par KumoMTA
  end
  function connection:close() end
  return connection
end)

return M
