## Architecture multi-PC

| Poste | Rôle | Adresse IPv4 |
|-------|------|--------------|
| PC1   | Client + (temporairement) rôle de PC3 : `haproxy-db`, `web2`, `mysql2` | `172.20.10.4` |
| PC2   | `haproxy-web`, `web1`, `mysql1` | `172.20.10.2` |
| PC3   | (à venir) reprendra ce que PC1 héberge actuellement | `à définir` |

L’objectif est que PC2 serve le site aux clients tandis que PC1 fait office de passerelle vers la base de données (HAProxy DB + MySQL2). Lorsque le vrai PC3 sera prêt, il suffira de mettre à jour les IP indiquées ci‑dessous.

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
| `WEB2_REMOTE_IP` | PC2 | IP du poste qui héberge `web2` (PC1 maintenant, PC3 plus tard) |
| `DB_PROXY_HOST` | PC2 | Adresse à laquelle les serveurs web contactent `haproxy-db` |
| `MYSQL1_REMOTE_IP`, `MYSQL1_PORT` | PC1 | Adresse/port publiés de `mysql1` sur PC2 |

Quand PC3 sera en place, copiez `.env.pc1-example` dessus, remplacez `WEB2_REMOTE_IP` et `DB_PROXY_HOST` par l’IP de PC3, puis n’exécutez plus `haproxy-db/web2/mysql2` sur PC1.

---

## 2. Démarrer les services

### Sur PC2 (172.20.10.2)

1. Charger l’environnement local :
   ```bash
   cd /mnt/h/itu/s5/architecture-logiciel/clustering
   source .env   # ou export DB_PROXY_HOST=...
   ```
2. Lancer les services nécessaires :
   ```bash
   docker compose up -d haproxy-web web1 mysql1
   ```
3. `haproxy-web` lit l’entrée `pc3-web2` dans `/etc/hosts` (définie via `extra_hosts`). Pour pointer vers le futur PC3, modifiez `WEB2_REMOTE_IP` puis redémarrez `haproxy-web`.

### Sur PC1 (172.20.10.4, rôle PC3)

1. Copier/adapter `.env.pc1-example`.
2. Démarrer les services :
   ```bash
   docker compose up -d haproxy-db web2 mysql2 replication-init
   ```
3. `haproxy-db` contacte `mysql1` au port publié `33061` sur PC2 (`haproxy-db/haproxy.cfg`, ligne `server mysql1 pc2-mysql1:33061`). Changez simplement `MYSQL1_REMOTE_IP` quand le poste change.

---

## 3. Accès et tests

- Client (PC1 ou autre) → `http://172.20.10.2:8080` pour passer par `haproxy-web`.
- HAProxy web → `web2` via `pc3-web2:8082` (défini dans `haproxy-web/haproxy.cfg`). Mettre à jour `WEB2_REMOTE_IP` avant de redémarrer `haproxy-web`.
- Serveurs PHP → base via `DB_PROXY_HOST`/`DB_PROXY_PORT` (configurable, voir `web/web*/index-db.php`).
- Vérifier la réplication : `docker compose logs replication-init` sur PC1, ou se connecter à MySQL via `mysql -h 172.20.10.4 -P 3307 -uroot -proot`.

---

## 4. Checklist pour basculer vers le vrai PC3

1. Copier `.env.pc1-example` sur le nouveau PC3 et ajuster les IP (notamment `WEB2_REMOTE_IP`, `DB_PROXY_HOST` et `MYSQL1_REMOTE_IP`).
2. Lancer `haproxy-db`, `web2`, `mysql2`, `replication-init` sur le vrai PC3.
3. Mettre à jour `WEB2_REMOTE_IP` dans `.env` de PC2 pour pointer vers l’IP du vrai PC3 puis redémarrer `haproxy-web`.
4. Arrêter les services correspondants sur PC1 (qui redevient uniquement client).

Ces indications se retrouvent directement dans les fichiers :

- `haproxy-web/haproxy.cfg` → ligne `server web2 pc3-web2:8082`.
- `haproxy-db/haproxy.cfg` → ligne `server mysql1 pc2-mysql1:33061`.
- `.env.pc*-example` → valeurs à mettre à jour lors du déplacement vers PC3.

Ainsi, aucune modification de code supplémentaire n’est nécessaire le jour du basculement : seules les IP dans `.env` (et éventuellement l’entrée `extra_hosts`) sont à adapter.
