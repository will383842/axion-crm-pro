variable "hcloud_token" {
  description = "Hetzner Cloud API token"
  type        = string
  sensitive   = true
}

variable "cloudflare_api_token" {
  description = "Cloudflare API token (zone DNS edit)"
  type        = string
  sensitive   = true
}

variable "cloudflare_zone_id" {
  description = "Cloudflare zone ID pour axion-crm-pro.com"
  type        = string
}

variable "deploy_ssh_public_key" {
  description = "Clé SSH publique pour déploiement"
  type        = string
}

variable "ssh_allowed_cidrs" {
  description = "CIDRs autorisés à SSH (cible : VPN ou IP fixe Will)"
  type        = list(string)
  default     = []
}
