# Axion CRM Pro — Installation Terraform sur Windows
# À lancer dans PowerShell en tant qu'Administrateur :
#   powershell -ExecutionPolicy Bypass -File install-terraform-windows.ps1

param(
    [string]$Version = "1.10.3"
)

$ErrorActionPreference = "Stop"
$InstallDir = "$env:LOCALAPPDATA\Terraform"
$Arch = if ([Environment]::Is64BitOperatingSystem) { "amd64" } else { "386" }
$ZipUrl = "https://releases.hashicorp.com/terraform/$Version/terraform_${Version}_windows_${Arch}.zip"
$ZipPath = "$env:TEMP\terraform.zip"

Write-Host "============================================" -ForegroundColor Cyan
Write-Host " Axion CRM Pro — Install Terraform $Version" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan

# 1. Téléchargement
Write-Host "`n[1/4] Téléchargement de Terraform $Version ($Arch)..." -ForegroundColor Yellow
Write-Host "URL : $ZipUrl"
Invoke-WebRequest -Uri $ZipUrl -OutFile $ZipPath -UseBasicParsing

# 2. Extraction
Write-Host "`n[2/4] Extraction dans $InstallDir..." -ForegroundColor Yellow
if (Test-Path $InstallDir) {
    Remove-Item -Recurse -Force $InstallDir
}
New-Item -ItemType Directory -Path $InstallDir | Out-Null
Expand-Archive -Path $ZipPath -DestinationPath $InstallDir -Force
Remove-Item $ZipPath

# 3. Ajout au PATH utilisateur (pas besoin d'admin)
Write-Host "`n[3/4] Ajout au PATH utilisateur..." -ForegroundColor Yellow
$UserPath = [Environment]::GetEnvironmentVariable("Path", "User")
if ($UserPath -notlike "*$InstallDir*") {
    [Environment]::SetEnvironmentVariable("Path", "$UserPath;$InstallDir", "User")
    $env:Path += ";$InstallDir"
    Write-Host "    PATH mis à jour. Redémarre ton terminal pour que ce soit permanent." -ForegroundColor Green
} else {
    Write-Host "    PATH contient déjà $InstallDir" -ForegroundColor Green
}

# 4. Vérification
Write-Host "`n[4/4] Vérification..." -ForegroundColor Yellow
$TerraformExe = "$InstallDir\terraform.exe"
if (Test-Path $TerraformExe) {
    & $TerraformExe version
    Write-Host "`n✅ Terraform installé avec succès." -ForegroundColor Green
} else {
    Write-Host "`n❌ terraform.exe introuvable dans $InstallDir" -ForegroundColor Red
    exit 1
}

Write-Host @"

============================================
 Prochaines étapes
============================================

1. Redémarrer PowerShell (ou : `$env:Path += ';$InstallDir'`)
2. cd $env:USERPROFILE\Documents\Projets\Axion-CRM-Pro\infra\terraform
3. Créer un fichier terraform.tfvars (PAS COMMIT — gitignored) avec :

       hcloud_token          = "<hetzner-api-token>"
       cloudflare_api_token  = "cfut_xxxxxxxxxxxx"
       cloudflare_zone_id    = "64ce9366e5eda11e7ac6f1b5b4229b2c"
       deploy_ssh_public_key = "ssh-ed25519 AAAA... axion-deploy"
       hetzner_location      = "hel1"
       ssh_allowed_cidrs     = ["X.X.X.X/32"]   # ton IP fixe

4. terraform init
5. terraform plan      # voir ce qui sera créé (PAS appliqué encore)
6. terraform apply     # créer l'infra (confirmer "yes" à la fin)

============================================
"@ -ForegroundColor Cyan
