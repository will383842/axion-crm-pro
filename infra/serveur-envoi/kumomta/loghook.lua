-- K7 : log hook KumoMTA -> relais LOCAL (127.0.0.1) -> passerelle. Lecture seule : aucun envoi, aucune action.
-- Le relais classe (regles TOML validees en CI) ; la passerelle numerote. Si le relais est injoignable, on leve
-- une erreur : KumoMTA reessaie l'enregistrement (rien n'est perdu, la passerelle dedoublonne).
local kumo = require 'kumo'

local RELAIS = 'http://127.0.0.1:2580/'

kumo.on('init', function()
  kumo.configure_log_hook {
    name = 'axion_loghook',
    meta = { 'id_injection', 'jeton' },
    per_record = {
      Reception = { enable = true },
      Delivery = { enable = true },
      Bounce = { enable = true },
      TransientFailure = { enable = true },
      Expiration = { enable = true },
      Any = { enable = false },
    },
  }
end)

kumo.on('should_enqueue_log_record', function(msg, hook_name)
  if hook_name ~= 'axion_loghook' then
    return
  end
  msg:set_meta('queue', 'axion_loghook')
end)

kumo.on('make.axion_loghook', function(domain, tenant, campaign)
  local connection = {}
  local client = kumo.http.build_client {}
  function connection:send(message)
    local r = client:post(RELAIS):header('Content-Type', 'application/json'):body(message:get_data()):send()
    if r:status_code() ~= 200 then
      kumo.reject(451, 'relais du log hook indisponible') -- reessai par KumoMTA
    end
  end
  function connection:close() end
  return connection
end)
