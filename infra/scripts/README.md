# `infra/scripts/` — automatisations

## Pour Will (Windows) — installer Terraform en local

```powershell
# Dans PowerShell (pas besoin admin)
cd C:\Users\willi\Documents\Projets\Axion-CRM-Pro\infra\scripts
powershell -ExecutionPolicy Bypass -File install-terraform-windows.ps1
```

Le script :
1. Télécharge Terraform 1.10.3 amd64 depuis releases.hashicorp.com (~30 Mo)
2. Extrait dans `%LOCALAPPDATA%\Terraform`
3. Ajoute au PATH utilisateur (pas de droits admin nécessaires)
4. Affiche `terraform version` pour vérifier

Une fois installé, redémarre PowerShell puis :
```powershell
cd C:\Users\willi\Documents\Projets\Axion-CRM-Pro\infra\terraform
cp terraform.tfvars.example terraform.tfvars
# édite terraform.tfvars avec tes credentials
terraform init
terraform plan
```

## Pour Hetzner CPX22 — setup automatique du serveur fresh Ubuntu 24.04

Après création du serveur sur console.hetzner.cloud, depuis ton PC :

```powershell
# 1. Copier ta clé SSH si pas déjà dans agent
ssh -i $HOME\.ssh\axion_hetzner root@46.62.248.239

# 2. Une fois connecté en SSH sur le serveur, lance :
curl -fsSL https://raw.githubusercontent.com/will383842/axion-crm-pro/main/infra/scripts/setup-hetzner-cpx22.sh | bash
```

Le script automatise les **8 étapes** :
1. Update système (apt update + upgrade)
2. Install Docker + git + outils
3. UFW firewall (22 + 80 + 443 only)
4. Fail2ban + hardening SSH (no password auth)
5. Clone repo Axion CRM Pro
6. Génère `.env` avec `APP_KEY` + `AUDIT_HASH_CHAIN_SECRET` aléatoires
7. `docker compose pull && up -d`
8. Migrations + seeders

Durée : ~10-15 min (dont 5 min pull images Docker).

Logs : `/var/log/axion-setup.log`

## Après le setup automatique

1. Sur le serveur Hetzner SSH :
   ```bash
   nano /opt/axion-crm-pro/.env
   ```
2. Renseigner les credentials récupérés (Mistral, INSEE, France Travail)
3. Redémarrer les services :
   ```bash
   cd /opt/axion-crm-pro
   docker compose restart api horizon scheduler
   ```
4. Tester depuis ton PC :
   ```powershell
   curl https://api.axion-crm-pro.com/up
   ```
   Doit retourner `200 OK` avec SSL valide Cloudflare.

## DR — restauration disaster recovery

```bash
bash /opt/axion-crm-pro/infra/scripts/dr-drill.sh
```
