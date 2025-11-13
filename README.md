## Cluster HAProxy · Guide utilisateur

Cet environnement Docker illustre un mini-cluster complet :
- HAProxy HTTP qui équilibre les serveurs `web1`, `web2`, `web3` (avec cookies `SRV`);
- HAProxy TCP placé devant trois MySQL répliqués;
- des applications PHP démontrant les sessions locales et partagées;
- un tableau de bord (`web-dashboard`) pour piloter l’ensemble.

---

### 1. Prérequis
- Docker 20+ et Docker Compose installés
- Environ 2 Go de RAM disponible
- PowerShell (facultatif) pour exécuter `reset-docker.ps1`

---

### 2. Lancement rapide
#### Tutoriel complet (environnement Windows + WSL)
1. Démarrer Docker Desktop.
2. Dans PowerShell (admin), exécuter :  
   `Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass`
3. Lancer `reset-docker.ps1`.
4. Dans un terminal WSL :
   ```
   wsl dos2unix ./scripts/setup-replication.sh
   wsl dos2unix ./haproxy-web/haproxy-entrypoint.sh
   wsl dos2unix ./haproxy-db/haproxy-entrypoint.sh
   wsl chmod +x ./scripts/setup-replication.sh
   wsl chmod +x ./haproxy-web/haproxy-entrypoint.sh
   wsl chmod +x ./haproxy-db/haproxy-entrypoint.sh
   ```
5. Relancer `reset-docker.ps1`.
6. Terminer par `docker-compose up -d`.

---

### 3. Services exposés

| Service         | Rôle                                                                   | Point d’accès                         |
|-----------------|------------------------------------------------------------------------|--------------------------------------|
| `haproxy-web`   | Load balancer HTTP + cookie `SRV`                                      | http://localhost:8080 · stats : :8404/stats |
| `web1/2/3`      | Apache + PHP 8.2 (démos sessions locales / DB)                         | via `haproxy-web`                    |
| `haproxy-db`    | Proxy TCP MySQL (3307) + page de stats                                 | TCP :3307 · http://localhost:8405/stats |
| `mysql1/2/3`    | MySQL 8, base `clustering`, utilisateur root/root                      | via `haproxy-db`                     |
| `web-dashboard` | UI d’administration (ajout serveurs, actions HAProxy, mode session…)   | http://localhost:8090                |

Deux boutons “Stats HAProxy Web” et “Stats HAProxy DB” sont disponibles dans l’interface pour accéder rapidement aux pages `/stats`.

---

### 4. Fonctionnement
1. **Trafic HTTP** : `haproxy-web` distribue les requêtes par round-robin et injecte un cookie `SRV`.  
   - Mode “sticky” → sessions PHP locales (filesystem).  
   - Mode “DB” → le `session_router.php` active automatiquement le `DBSessionHandler`.

2. **Sessions partagées** : `web/shared/db_session_handler.php` écrit la table `sessions` via `haproxy-db` (3307). L’historique utilisateur est conservé dans `web/shared/session_activity_store/`, dossier partagé entre les web servers.

3. **Dashboard** :
   - Ajout/suppression/édition de serveurs web et DB dans les fichiers HAProxy.  
   - Choix du mode de session (sticky ↔ base).  
   - Actions runtime (désactiver, redémarrer, rafraîchir) via les sockets admin HAProxy.

⚠️ **Après chaque changement de mode de session dans le dashboard, redémarrez HAProxy web :**
```bash
docker-compose restart haproxy-web
```
Sans ce redémarrage, la nouvelle configuration (cookie SRV activé/désactivé) ne sera pas appliquée.

---

### 5. Structure du dépôt
```
.
├─ docker-compose.yml           # orchestration complète
├─ haproxy-web/                 # config HAProxy HTTP + scripts
├─ haproxy-db/                  # config HAProxy TCP (MySQL)
├─ mysql/                       # my.cnf + init.sql (table sessions) pour mysql1/2/3
├─ scripts/setup-replication.sh # script d’initialisation master↔master
├─ web/
│   ├─ shared/                  # Session handler, router et stockage activité
│   ├─ web1/, web2/, web3/      # démos PHP/Apache
│   └─ web-dashboard/           # interface de supervision/pilotage
└─ reset-docker.ps1             # reset total pour Docker Desktop
```

---

### 6. Parcours conseillé
1. `docker-compose up -d`
2. http://localhost:8080 → tester la page “Session locale” (bouton “Effacer les cookies” pour casser le stickiness).  
3. http://localhost:8090 → passer en “Sessions via base de données”, puis `docker-compose restart haproxy-web`.  
4. http://localhost:8080/index-db.php → vérifier que la couleur saisie apparaît aussi en passant par `web2`.  
5. Dans le dashboard, ajouter/désactiver une base ou un serveur web, observer les effets dans HAProxy (et via les boutons “Stats”).

---

### 7. Dépannage rapide

| Symptom                                   | Piste                                                                                   |
|-------------------------------------------|-----------------------------------------------------------------------------------------|
| `session_router.php` introuvable          | Vérifier que `./web/shared` est monté dans **tous** les conteneurs web (volumes Compose).|
| Sessions DB incohérentes                  | S’assurer que `haproxy-db` a bien `mysql2` en `backup` et redémarrer `haproxy-db` au besoin. |
| Réplication MySQL cassée                  | Relancer `docker-compose restart replication-init` après que `mysql1` et `mysql2` soient prêts. |
| Ports déjà utilisés                       | Adapter les sections `ports:` dans `docker-compose.yml`.                                 |

---

### 8. Aller plus loin
- Ajouter un nouveau serveur web via le dashboard (cookies HAProxy gérés automatiquement).
- Déclarer une nouvelle base MySQL : elle sera insérée en mode `backup` par défaut.
- Connecter Prometheus/Grafana sur les sockets runtime HAProxy ou les pages `/stats`.
