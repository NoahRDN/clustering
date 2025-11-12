# ===============================
#  Script : reset-docker.ps1
#  Objectif : Réinitialiser et redémarrer la stack Docker Compose
# ===============================

Write-Host "=== Nettoyage et redemarrage des conteneurs Docker ===" -ForegroundColor Cyan

#  Arrêt et suppression des conteneurs existants
Write-Host ">> Arret et suppression des conteneurs existants..." -ForegroundColor Yellow
docker compose down --remove-orphans

# Suppression manuelle des conteneurs MySQL et HAProxy
Write-Host ">> Suppression manuelle des conteneurs MySQL et HAProxy..." -ForegroundColor Yellow
docker rm -f mysql1 mysql2 mysql3 haproxy-db haproxy-web 2>$null

# Nettoyage des réseaux Docker inutilisés
Write-Host ">> Nettoyage des reseaux Docker inutilises..." -ForegroundColor Yellow
docker network prune -f

# Redémarrage de la stack Docker Compose
Write-Host ">> Redemarrage de la stack Docker Compose..." -ForegroundColor Green
docker compose up -d --build --force-recreate

# Liste des conteneurs actifs
Write-Host "`n=== Liste des conteneurs actifs ===" -ForegroundColor Cyan
docker ps

Write-Host "`nTermine avec succes !" -ForegroundColor Green
