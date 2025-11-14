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

> ℹ️ Les services MySQL sont maintenant construits à partir de Dockerfiles (`mysql/mysql*/Dockerfile`) qui copient directement `my.cnf` dans l’image. Cela évite que MySQL ignore la configuration à cause des permissions Windows sur les volumes montés. Pensez à reconstruire les images (`docker compose build mysql1 mysql2 mysql3` ou `make up`) si vous modifiez ces fichiers.

> ℹ️ Les services HAProxy construisent désormais leurs propres images (création de `/var/run/haproxy` appartenant à `haproxy:haproxy`). Le runtime admin passe par des ports internes (`haproxy-web` écoute sur `0.0.0.0:9999`, `haproxy-db` sur `0.0.0.0:10000`) et le dashboard s’y connecte directement en TCP (`tcp://haproxy-web:9999`, `tcp://haproxy-db:10000`). Les demandes de reload utilisent des volumes nommés (`haproxy_web_flags`, `haproxy_db_flags`) montés dans les conteneurs HAProxy et dashboard pour déposer les fichiers `reload.flag`. Après modification des fichiers `haproxy-*/haproxy.cfg` ou des entrypoints, relancez `docker compose up -d --build haproxy-web haproxy-db` puis `docker compose restart web-dashboard`.

---

### 4. Fonctionnement
1. **Trafic HTTP** : `haproxy-web` distribue les requêtes par round-robin et injecte un cookie `SRV`.  
   - Mode “sticky” → sessions PHP locales (filesystem).  
   - Mode “DB” → le `session_router.php` active automatiquement le `DBSessionHandler`.

2. **Sessions partagées** : `web/shared/db_session_handler.php` écrit la table `sessions` via `haproxy-db` (3307). L’historique utilisateur est conservé dans `web/shared/session_activity_store/`, dossier partagé entre les web servers.

3. **Préférence globale ou session** : sur `/index-db.php`, un bouton permet de basculer entre :
   - *Session individuelle* → la couleur préférée reste liée à l’ID de session (comme auparavant) ;
   - *Valeur globale* → la couleur est stockée dans la table `global_preferences` (clé `favorite_color`) et est instantanément visible par tous les navigateurs.
   Le choix est mémorisé dans le cookie `SESSION_DATA_SCOPE`.

4. **Dashboard** :
   - Ajout/suppression/édition de serveurs web et DB dans les fichiers HAProxy.  
   - Choix du mode de session (sticky ↔ base).  
   - Actions runtime (désactiver, redémarrer, rafraîchir) via les sockets admin HAProxy.

> ⚠️ Dès que vous modifiez le “Mode de gestion des sessions”, ou que vous ajoutez/éditez un serveur web/DB, les scripts demandent automatiquement un reload HAProxy. **Si vous êtes dans un environnement sans ces scripts (ou si vous préférez être explicite), redémarrez `haproxy-web` et/ou `haproxy-db` :**  
```bash
docker-compose restart haproxy-web   # pour les changements côté web
docker-compose restart haproxy-db    # pour les changements côté bases
```
Et lorsque vous passez du mode HAProxy (sticky) au mode “base de données”, effacez les cookies `SRV` et `PHPSESSID` pour repartir sur un flux propre (bouton “Effacer les cookies” disponible sur les pages).

---

### 5. Structure du dépôt
```
.
├─ docker-compose.yml           # orchestration complète
├─ haproxy-web/                 # config HAProxy HTTP + scripts
├─ haproxy-db/                  # config HAProxy TCP (MySQL)
├─ mysql/                       # my.cnf + init.sql (tables sessions + global_preferences)
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
4. http://localhost:8080/index-db.php → tester les deux modes (“Session individuelle” vs “Valeur globale”). En mode global, ouvrez un second navigateur : la couleur se synchronise immédiatement.  
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
