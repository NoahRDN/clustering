# =====================================
# Makefile PRO – Docker MySQL Cluster
# =====================================

COMPOSE ?= docker compose

.PHONY: up down stop restart fix-mysql-perms logs reset-cluster status inspect reconfigure seed cluster-status


# -----------------------------
# 🟢 Démarre les conteneurs
# -----------------------------
up:
	$(COMPOSE) up -d
	./scripts/fix-mysql-perms.sh
	@echo "Cluster démarré ✔"


# -----------------------------
# 🔴 Stoppe + supprime conteneurs
# -----------------------------
down:
	$(COMPOSE) down
	@echo "Cluster arrêté ✔"


# -----------------------------
# 🟡 Stoppe uniquement
# -----------------------------
stop:
	$(COMPOSE) stop
	@echo "Cluster stoppé ✔"


# -----------------------------
# 🔄 Restart propre
# -----------------------------
restart:
	$(COMPOSE) down
	$(COMPOSE) up -d
	./scripts/fix-mysql-perms.sh
	@echo "Cluster redémarré ✔"


# -----------------------------
# 🔐 Fix permissions MySQL
# -----------------------------
fix-mysql-perms:
	./scripts/fix-mysql-perms.sh
	@echo "Permissions MySQL corrigées ✔"


# -----------------------------
# 📜 Logs
# -----------------------------
logs:
	$(COMPOSE) logs -f


# -----------------------------
# 💣 RESET TOTAL DU CLUSTER
# -----------------------------
reset-cluster:
	@echo "⚠️ RESET COMPLET DU CLUSTER"
	$(COMPOSE) down --remove-orphans
	-docker rm -f mysql1 mysql2 mysql3 haproxy-db 2>/dev/null || true
	-docker volume rm $$(docker volume ls -q | grep mysql) 2>/dev/null || true
	-docker network prune -f
	@echo "➡️ Cluster reset ✔"
	$(COMPOSE) up -d
	./scripts/fix-mysql-perms.sh
	@echo "➡️ Cluster redémarré proprement ✔"


# -----------------------------
# 📡 Status réplication
# -----------------------------
status:
	@echo "=== MySQL1 ==="
	mysql -h mysql1 -uroot -proot -e "SHOW SLAVE STATUS\G" | grep Running || true
	@echo "\n=== MySQL2 ==="
	mysql -h mysql2 -uroot -proot -e "SHOW SLAVE STATUS\G" | grep Running || true
	@echo "\n=== MySQL3 ==="
	-mysql -h mysql3 -uroot -proot -e "SHOW SLAVE STATUS\G" | grep Running || true


# -----------------------------
# 🔍 Variables importantes
# -----------------------------
inspect:
	@echo "🧪 Inspect MySQL1"
	mysql -h mysql1 -uroot -proot -e "SHOW VARIABLES LIKE 'gtid_mode';"
	mysql -h mysql1 -uroot -proot -e "SHOW VARIABLES LIKE 'server_id';"
	mysql -h mysql1 -uroot -proot -e "SHOW VARIABLES LIKE 'auto_increment%';"
	@echo "\n🧪 Inspect MySQL2"
	mysql -h mysql2 -uroot -proot -e "SHOW VARIABLES LIKE 'gtid_mode';"
	mysql -h mysql2 -uroot -proot -e "SHOW VARIABLES LIKE 'server_id';"
	mysql -h mysql2 -uroot -proot -e "SHOW VARIABLES LIKE 'auto_increment%';"
	@echo "\n🧪 Inspect MySQL3"
	-mysql -h mysql3 -uroot -proot -e "SHOW VARIABLES LIKE 'gtid_mode';"
	-mysql -h mysql3 -uroot -proot -e "SHOW VARIABLES LIKE 'server_id';"
	-mysql -h mysql3 -uroot -proot -e "SHOW VARIABLES LIKE 'auto_increment%';"


# -----------------------------
# 🔄 Relance setup réplication
# -----------------------------
reconfigure:
	./scripts/setup-replication.sh
	@echo "Réplication reconfigurée ✔"


# -----------------------------
# 🌱 Données de test
# -----------------------------
seed:
	mysql -h mysql1 -uroot -proot -e "USE clustering; INSERT INTO test_sync (msg) VALUES ('Seed data');"
	@echo "Données seed insérées ✔"


# -----------------------------
# 🧭 Vue globale du cluster
# -----------------------------
cluster-status:
	@echo "=== STATUS REPLICATION ==="
	make status
	@echo "\n=== VARIABLES IMPORTANTES ==="
	make inspect
	@echo "\n=== TEST LECTURE VIA HAPROXY ==="
	mysql -h haproxy-db -P 3307 -uroot -proot -e "USE clustering; SELECT * FROM test_sync;"
	@echo "Cluster status OK ✔"
