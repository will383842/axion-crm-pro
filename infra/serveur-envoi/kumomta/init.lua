-- KumoMTA : configuration minimale du lot K3. Aucun listener SMTP entrant,
-- aucune injection : l'API d'administration est LOCALE seulement.
local kumo = require 'kumo'

-- K5 : signature DKIM par le serveur (dkim.lua monte a cote de ce fichier).
package.path = '/opt/kumomta/etc/policy/?.lua;' .. package.path
require 'dkim'

kumo.on('init', function()
  kumo.define_spool {
    name = 'data',
    path = '/var/spool/kumomta/data',
  }
  kumo.define_spool {
    name = 'meta',
    path = '/var/spool/kumomta/meta',
  }
  kumo.configure_local_logs {
    log_dir = '/var/log/kumomta',
  }
  kumo.start_http_listener {
    listen = "127.0.0.1:8000",
    trusted_hosts = { "127.0.0.1", "::1" },
  }
end)

-- Adresse source = l'IP d'envoi dediee (marqueur remplace a l'installation).
kumo.on('get_egress_source', function(source_name)
  return kumo.make_egress_source {
    name = source_name,
    source_address = '<ip-envoi>',
  }
end)
