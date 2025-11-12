## Architecture multi-PC

| Poste | Rôle | Adresse IPv4 |
|-------|------|--------------|
| PC1   | Client + (temporairement) rôle de PC3 : `haproxy-db`, `web2`, `mysql2` | `172.20.10.4` |
| PC2   | `haproxy-web`, `web1`, `mysql1` | `172.20.10.2` |
| PC3   | (à venir) reprendra ce que PC1 héberge actuellement | `à définir` |

L’objectif est que PC2 serve le site aux clients tandis que PC1 fait office de passerelle vers la base de données (HAProxy DB + MySQL2). Lorsque le vrai PC3 sera prêt, il suffira de mettre à jour les IP indiquées ci‑dessous.

---

## Configuration HAProxy centralisée

- Le répertoire `shared-config/` contient **la seule source de vérité** pour les fichiers `haproxy-*.cfg`.
- Le service `config-service` (conteneur Nginx) expose ces fichiers sur `http://<PC1>:8088/`.
- Les conteneurs `haproxy-web` et `haproxy-db` téléchargent automatiquement leur configuration depuis cette URL au démarrage et à chaque reload (fichier `runtime/reload.flag`).

👉 Pour mettre à jour la config :
1. Modifier `shared-config/haproxy-web.cfg` ou `shared-config/haproxy-db.cfg`.
2. (Optionnel) vérifier depuis un navigateur `http://172.20.10.4:8088/haproxy-web.cfg`.
3. Sur le ou les PC concernés, déclencher un reload :
   - Via l’API REST (préféré) : `curl -X POST http://<pc>:9101/reload -d '{"token":"..."}'`.
   - En dernier recours (même machine uniquement) : `touch haproxy-web/runtime/reload.flag`.

## API de contrôle HAProxy (runtime-api-*)

Chaque HAProxy dispose d’un petit service REST (conteneurs `runtime-api-web` et `runtime-api-db`) capable :
- d’exécuter une commande runtime (`POST /execute` avec `{"command":"disable server ...", "token":"..."}`) ;
- de déclencher un reload (`POST /reload`).

Ces services :
- tournent sur le même hôte que HAProxy (accès direct à `admin.sock` via volume) ;
- sont protégés par un token (`WEB_RUNTIME_API_TOKEN`, `DB_RUNTIME_API_TOKEN`) ;
- publient par défaut les ports `9101` (web) et `9002` (db), accessibles sur le réseau local (`http://172.20.10.2:9101`, `http://172.20.10.4:9002`, etc.).

Le dashboard consomme désormais ces API pour arrêter/redémarrer les serveurs, ce qui permet d’administrer HAProxy à distance même lorsqu’il tourne sur un autre PC.

---

## 1. Préparer les fichiers d’environnement

Chaque poste doit avoir son propre fichier `.env` (non versionné) à partir des modèles fournis :

```bash
# Sur PC1 (simule PC3)
cp .env.pc1-example .env

# Sur PC2
cp .env.pc2-example .env
```

Mettez à jour les valeurs suivantes si les IP changent :

| Variable | Fichier | Description |
|----------|---------|-------------|
| `HAPROXY_WEB_CONFIG_URL`, `HAPROXY_DB_CONFIG_URL` | PC1 & PC2 | URL HTTP du service `config-service` (ex : `http://172.20.10.4:8088/haproxy-web.cfg`) |
| `WEB2_REMOTE_IP` | PC2 | IP du poste qui héberge `web2` (PC1 maintenant, PC3 plus tard) |
| `DB_PROXY_HOST` | PC2 | Adresse à laquelle les serveurs web contactent `haproxy-db` |
| `MYSQL1_REMOTE_IP`, `MYSQL1_PORT` | PC1 | Adresse/port publiés de `mysql1` sur PC2 |
| `MYSQL2_REMOTE_IP` | PC2 | IP du poste qui héberge `mysql2` (nécessaire pour la réplication master-master) |
| `WEB_RUNTIME_API_URL`, `DB_RUNTIME_API_URL` | PC1 & PC2 | URL REST des services runtime (ex : `http://172.20.10.2:9101`) |
| `WEB_RUNTIME_API_TOKEN`, `DB_RUNTIME_API_TOKEN` | PC1 & PC2 | Token partagé pour sécuriser les appels REST |

Quand PC3 sera en place, copiez `.env.pc1-example` dessus, remplacez `WEB2_REMOTE_IP` et `DB_PROXY_HOST` par l’IP de PC3, puis n’exécutez plus `haproxy-db/web2/mysql2` sur PC1.

---

## 2. Démarrer les services

### Sur PC2 (172.20.10.2)

1. Charger l’environnement local :
   ```bash
   cd /mnt/h/itu/s5/architecture-logiciel/clustering
   source .env   # ou export DB_PROXY_HOST=...
   ```
2. Lancer les services nécessaires (y compris l’API runtime web) :
   ```bash
   docker compose up -d haproxy-web runtime-api-web web1 mysql1
   ```
3. `haproxy-web` lit l’entrée `pc3-web2` dans `/etc/hosts` (définie via `extra_hosts`). Pour pointer vers le futur PC3, modifiez `WEB2_REMOTE_IP` puis redémarrez `haproxy-web`.
4. L’API runtime Web est disponible sur `http://172.20.10.2:9101`.
4. Assure-toi que `HAPROXY_WEB_CONFIG_URL` pointe bien vers `http://172.20.10.4:8088/haproxy-web.cfg` (config-service hébergé sur PC1).

### Sur PC1 (172.20.10.4, rôle PC3)

1. Copier/adapter `.env.pc1-example`.
2. Démarrer d’abord le service de configuration partagé :
   ```bash
   docker compose up -d config-service
   ```
3. Démarrer les services applicatifs ainsi que l’API runtime DB :
   ```bash
   docker compose up -d haproxy-db runtime-api-db web2 mysql2 replication-init
   ```
4. `haproxy-db` contacte `mysql1` au port publié `33061` sur PC2 (`shared-config/haproxy-db.cfg`, ligne `server mysql1 pc2-mysql1:33061`). Changez simplement `MYSQL1_REMOTE_IP` quand le poste change.
5. L’API runtime DB est disponible sur `http://172.20.10.4:9002`.

---

## 3. Accès et tests

- Client (PC1 ou autre) → `http://172.20.10.2:8080` pour passer par `haproxy-web`.
- HAProxy web → `web2` via `pc3-web2:8082` (voir `shared-config/haproxy-web.cfg`). Mettre à jour `WEB2_REMOTE_IP` avant de redémarrer `haproxy-web`.
- Serveurs PHP → base via `DB_PROXY_HOST`/`DB_PROXY_PORT` (configurable, voir `web/web*/index-db.php`).
- Vérifier la réplication : `docker compose logs replication-init` sur PC1, ou se connecter à MySQL via `mysql -h 172.20.10.4 -P 3307 -uroot -proot`.
- Vérifier la config centralisée : `curl http://172.20.10.4:8088/haproxy-web.cfg`.

---

## 4. Checklist pour basculer vers le vrai PC3

1. Copier `.env.pc1-example` sur le nouveau PC3 et ajuster les IP (notamment `WEB2_REMOTE_IP`, `DB_PROXY_HOST` et `MYSQL1_REMOTE_IP`).
2. Lancer `haproxy-db`, `runtime-api-db`, `web2`, `mysql2`, `replication-init` sur le vrai PC3.
3. Mettre à jour `WEB2_REMOTE_IP` dans `.env` de PC2 pour pointer vers l’IP du vrai PC3 puis redémarrer `haproxy-web`.
4. Arrêter les services correspondants sur PC1 (qui redevient uniquement client).

Ces indications se retrouvent directement dans les fichiers :

- `shared-config/haproxy-web.cfg` → ligne `server web2 pc3-web2:8082`.
- `shared-config/haproxy-db.cfg` → ligne `server mysql1 pc2-mysql1:33061`.
- `runtime-api/` → code source de l’API REST utilisée pour piloter HAProxy à distance.

---

## Synchroniser `shared-config` entre PC1 et PC2

Si tu as besoin de modifier la configuration depuis un autre poste (ex. PC2) tout en gardant PC1 comme source de vérité :

1. Sur PC2, copie le fichier `.env.sync-example` vers `.env.sync` et renseigne :
   ```ini
   SYNC_REMOTE_USER=ton_user_pc1
   SYNC_REMOTE_HOST=192.168.1.219
   SYNC_REMOTE_PATH=/mnt/h/itu/s5/architecture-logiciel/clustering/shared-config
   ```
2. Pour récupérer la dernière version des configs depuis PC1 :
   ```bash
   ./scripts/pull-shared-config.sh
   ```
3. Après modification locale (via le dashboard ou un éditeur), renvoie les fichiers vers PC1 :
   ```bash
   ./scripts/push-shared-config.sh
   ```

Ces scripts utilisent `rsync` via SSH ; assure-toi que la commande `rsync` est disponible et que tu peux te connecter à PC1 (clé SSH ou mot de passe). Pense à relancer les HAProxy (`touch haproxy-*/runtime/reload.flag` ou `docker compose up -d haproxy-*`) après chaque synchronisation pour appliquer les changements.
- `.env.pc*-example` → valeurs à mettre à jour lors du déplacement vers PC3.

Ainsi, aucune modification de code supplémentaire n’est nécessaire le jour du basculement : seules les IP dans `.env` (et éventuellement l’entrée `extra_hosts`) sont à adapter.
