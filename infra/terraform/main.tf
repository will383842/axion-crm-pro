# Axion CRM Pro — Terraform Hetzner Cloud + Cloudflare DNS
# Cf. spec/18_deploiement_hetzner.md — 7 serveurs + vSwitch + firewall + floating IP.

terraform {
  required_version = ">= 1.7"
  required_providers {
    hcloud     = { source = "hetznercloud/hcloud", version = "~> 1.48" }
    cloudflare = { source = "cloudflare/cloudflare", version = "~> 4.50" }
  }

  backend "s3" {
    bucket                      = "axion-crm-pro-tfstate"
    key                         = "prod/terraform.tfstate"
    region                      = "auto"
    endpoint                    = "https://fsn1.your-objectstorage.com"
    skip_credentials_validation = true
    skip_metadata_api_check     = true
    skip_region_validation      = true
    force_path_style            = true
  }
}

provider "hcloud"     { token = var.hcloud_token }
provider "cloudflare" { api_token = var.cloudflare_api_token }

# ---------------------------------------------------------------------------
# vSwitch privé (réseau interne intra-Hetzner, pas de coût bande passante)
# ---------------------------------------------------------------------------
resource "hcloud_network" "axion_net" {
  name     = "axion-crm-pro"
  ip_range = "10.0.0.0/16"
}

resource "hcloud_network_subnet" "axion_subnet" {
  network_id   = hcloud_network.axion_net.id
  type         = "cloud"
  network_zone = "eu-central"
  ip_range     = "10.0.1.0/24"
}

# ---------------------------------------------------------------------------
# Firewall : SSH 22 + HTTP 80 + HTTPS 443 + monitoring 3000-9090 restreint
# ---------------------------------------------------------------------------
resource "hcloud_firewall" "axion_fw" {
  name = "axion-crm-pro-fw"

  rule {
    direction = "in"
    protocol  = "tcp"
    port      = "22"
    source_ips = var.ssh_allowed_cidrs
  }
  rule {
    direction = "in"
    protocol  = "tcp"
    port      = "80"
    source_ips = ["0.0.0.0/0", "::/0"]
  }
  rule {
    direction = "in"
    protocol  = "tcp"
    port      = "443"
    source_ips = ["0.0.0.0/0", "::/0"]
  }
  # ICMP autorisé
  rule {
    direction = "in"
    protocol  = "icmp"
    source_ips = ["0.0.0.0/0", "::/0"]
  }
}

# ---------------------------------------------------------------------------
# Serveurs (7) — cf. spec/02 dimensionnement
# ---------------------------------------------------------------------------
locals {
  servers = {
    edge        = { type = "cax21", role = "caddy + cloudflare cache" }
    app         = { type = "cpx42", role = "Laravel + Frontend" }
    data        = { type = "ccx13", role = "Postgres + Redis" }
    worker_1    = { type = "cpx31", role = "Playwright workers" }
    worker_2    = { type = "cpx31", role = "Playwright workers" }
    observable  = { type = "cpx21", role = "Prometheus + Grafana + Loki + Tempo + GlitchTip" }
    staging     = { type = "ccx13", role = "Staging env" }
  }
}

resource "hcloud_server" "nodes" {
  for_each = local.servers

  name        = "axion-crm-${each.key}"
  image       = "ubuntu-24.04"
  server_type = each.value.type
  # Datacenters UE/RGPD : fsn1 (Falkenstein DE), nbg1 (Nuremberg DE), hel1 (Helsinki FI).
  # Variable configurable selon la latence souhaitée vers utilisateurs France.
  location    = var.hetzner_location
  ssh_keys    = [hcloud_ssh_key.axion.id]
  firewall_ids = [hcloud_firewall.axion_fw.id]

  network {
    network_id = hcloud_network.axion_net.id
    ip         = "10.0.1.${10 + index(keys(local.servers), each.key)}"
  }

  labels = {
    project = "axion-crm-pro"
    role    = each.key
  }

  user_data = file("${path.module}/cloud-init.yaml")
}

resource "hcloud_ssh_key" "axion" {
  name       = "axion-crm-pro-deploy"
  public_key = var.deploy_ssh_public_key
}

# ---------------------------------------------------------------------------
# Floating IP edge (DNS pointe ici, bascule au besoin)
# ---------------------------------------------------------------------------
resource "hcloud_floating_ip" "edge" {
  type          = "ipv4"
  home_location = "fsn1"
  description   = "axion-crm-pro edge"
}

resource "hcloud_floating_ip_assignment" "edge_to_node" {
  floating_ip_id = hcloud_floating_ip.edge.id
  server_id      = hcloud_server.nodes["edge"].id
}

# ---------------------------------------------------------------------------
# Cloudflare DNS A → floating IP edge
# ---------------------------------------------------------------------------
resource "cloudflare_record" "root" {
  zone_id = var.cloudflare_zone_id
  name    = "@"
  value   = hcloud_floating_ip.edge.ip_address
  type    = "A"
  proxied = true
  ttl     = 1
}

resource "cloudflare_record" "api" {
  zone_id = var.cloudflare_zone_id
  name    = "api"
  value   = hcloud_floating_ip.edge.ip_address
  type    = "A"
  proxied = true
  ttl     = 1
}

# ---------------------------------------------------------------------------
# Outputs
# ---------------------------------------------------------------------------
output "edge_ip" {
  value = hcloud_floating_ip.edge.ip_address
}

output "server_ips" {
  value = { for k, s in hcloud_server.nodes : k => s.ipv4_address }
}
