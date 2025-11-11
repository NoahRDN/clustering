#!/bin/sh
set -e

MYSQL1_HOST=${MYSQL1_HOST:-mysql1}
MYSQL2_HOST=${MYSQL2_HOST:-mysql2}
MYSQL1_PORT=${MYSQL1_PORT:-3306}
MYSQL2_PORT=${MYSQL2_PORT:-3306}

echo "⏳ Attente du démarrage de MySQL (${MYSQL1_HOST}:${MYSQL1_PORT} / ${MYSQL2_HOST}:${MYSQL2_PORT})..."

# ✅ Fonction qui attend que le serveur MySQL soit prêt
wait_for_mysql() {
  host="$1"
  port="$2"
  until mysqladmin ping -h"$host" -P"$port" -uroot -proot --silent; do
    echo "⏳ En attente de $host:$port..."
    sleep 3
  done
  echo "✅ $host:$port est prêt."
}

wait_for_mysql "${MYSQL1_HOST}" "${MYSQL1_PORT}"
wait_for_mysql "${MYSQL2_HOST}" "${MYSQL2_PORT}"

echo "🔐 Configuration des permissions et server IDs..."
# Configuration des server_id uniques (car les fichiers my.cnf sont ignorés)
mysql -h "${MYSQL1_HOST}" -P "${MYSQL1_PORT}" -uroot -proot -e "SET GLOBAL server_id = 1;"
mysql -h "${MYSQL2_HOST}" -P "${MYSQL2_PORT}" -uroot -proot -e "SET GLOBAL server_id = 2;"
echo "✅ Server IDs configurés : mysql1=1, mysql2=2"

echo "👥 Création de l'utilisateur de réplication..."
for host in "${MYSQL1_HOST}" "${MYSQL2_HOST}"; do
  port="${MYSQL1_PORT}"
  [ "$host" = "${MYSQL2_HOST}" ] && port="${MYSQL2_PORT}"
  mysql -h "${host}" -P "${port}" -uroot -proot <<EOF
DROP USER IF EXISTS 'repl'@'%';
CREATE USER 'repl'@'%' IDENTIFIED WITH mysql_native_password BY 'replpass';
GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%';
FLUSH PRIVILEGES;
ALTER USER 'root'@'%' IDENTIFIED WITH mysql_native_password BY 'root';
FLUSH PRIVILEGES;
EOF
done

echo "🧹 Réinitialisation propre des binlogs et des esclave..."
for host in "${MYSQL1_HOST}" "${MYSQL2_HOST}"; do
  port="${MYSQL1_PORT}"
  [ "$host" = "${MYSQL2_HOST}" ] && port="${MYSQL2_PORT}"
  mysql -h "${host}" -P "${port}" -uroot -proot <<EOF
RESET MASTER;
RESET SLAVE ALL;
EOF
done


echo "� Vérification du mode GTID..."
GTID_MODE1=$(mysql -h "${MYSQL1_HOST}" -P "${MYSQL1_PORT}" -uroot -proot -sN -e "SELECT @@GLOBAL.GTID_MODE;")
GTID_MODE2=$(mysql -h "${MYSQL2_HOST}" -P "${MYSQL2_PORT}" -uroot -proot -sN -e "SELECT @@GLOBAL.GTID_MODE;")

echo "GTID Mode mysql1: $GTID_MODE1"
echo "GTID Mode mysql2: $GTID_MODE2"

if [ "$GTID_MODE1" = "ON" ] && [ "$GTID_MODE2" = "ON" ]; then
  echo "�🔁 Configuration de la réplication Master <-> Master avec GTID..."
  mysql -h "${MYSQL1_HOST}" -P "${MYSQL1_PORT}" -uroot -proot <<EOF
STOP SLAVE;
CHANGE MASTER TO 
  MASTER_HOST='${MYSQL2_HOST}',
  MASTER_USER='repl',
  MASTER_PASSWORD='replpass',
  MASTER_AUTO_POSITION=1;
START SLAVE;
EOF

  mysql -h "${MYSQL2_HOST}" -P "${MYSQL2_PORT}" -uroot -proot <<EOF
STOP SLAVE;
CHANGE MASTER TO 
  MASTER_HOST='${MYSQL1_HOST}',
  MASTER_USER='repl',
  MASTER_PASSWORD='replpass',
  MASTER_AUTO_POSITION=1;
START SLAVE;
EOF
else
  echo "🔁 Configuration de la réplication Master <-> Master sans GTID (méthode traditionnelle)..."
  
  # Obtenir les positions des logs binaires
  MYSQL1_STATUS=$(mysql -h "${MYSQL1_HOST}" -P "${MYSQL1_PORT}" -uroot -proot -e "SHOW MASTER STATUS\G")
  MYSQL1_FILE=$(echo "$MYSQL1_STATUS" | grep "File:" | awk '{print $2}')
  MYSQL1_POS=$(echo "$MYSQL1_STATUS" | grep "Position:" | awk '{print $2}')
  
  MYSQL2_STATUS=$(mysql -h "${MYSQL2_HOST}" -P "${MYSQL2_PORT}" -uroot -proot -e "SHOW MASTER STATUS\G")
  MYSQL2_FILE=$(echo "$MYSQL2_STATUS" | grep "File:" | awk '{print $2}')
  MYSQL2_POS=$(echo "$MYSQL2_STATUS" | grep "Position:" | awk '{print $2}')
  
  echo "MySQL1 Master Status: File=$MYSQL1_FILE, Position=$MYSQL1_POS"
  echo "MySQL2 Master Status: File=$MYSQL2_FILE, Position=$MYSQL2_POS"
  
  # Configuration de mysql1 comme esclave de mysql2
  mysql -h "${MYSQL1_HOST}" -P "${MYSQL1_PORT}" -uroot -proot <<EOF
STOP SLAVE;
CHANGE MASTER TO 
  MASTER_HOST='${MYSQL2_HOST}',
  MASTER_USER='repl',
  MASTER_PASSWORD='replpass',
  MASTER_LOG_FILE='$MYSQL2_FILE',
  MASTER_LOG_POS=$MYSQL2_POS;
START SLAVE;
EOF

  # Configuration de mysql2 comme esclave de mysql1
  mysql -h "${MYSQL2_HOST}" -P "${MYSQL2_PORT}" -uroot -proot <<EOF
STOP SLAVE;
CHANGE MASTER TO 
  MASTER_HOST='${MYSQL1_HOST}',
  MASTER_USER='repl',
  MASTER_PASSWORD='replpass',
  MASTER_LOG_FILE='$MYSQL1_FILE',
  MASTER_LOG_POS=$MYSQL1_POS;
START SLAVE;
EOF
fi

echo "✅ Vérification de la réplication..."
mysql -h "${MYSQL1_HOST}" -P "${MYSQL1_PORT}" -uroot -proot -e "SHOW SLAVE STATUS\G" | grep Running || true
mysql -h "${MYSQL2_HOST}" -P "${MYSQL2_PORT}" -uroot -proot -e "SHOW SLAVE STATUS\G" | grep Running || true

echo "🎉 Réplication configurée automatiquement avec succès !"

echo "🧪 Test automatique de réplication..."

mysql -h "${MYSQL1_HOST}" -P "${MYSQL1_PORT}" -uroot -proot <<EOF
USE clustering;
INSERT INTO test_sync (msg) VALUES ('Auto-test depuis mysql1 ✅');
EOF

sleep 2

mysql -h "${MYSQL2_HOST}" -P "${MYSQL2_PORT}" -uroot -proot --table -e "USE clustering; SELECT * FROM test_sync;"

echo "📋 Vérification via HAProxy (port 3307) :"
mysql -h haproxy-db -P 3307 -uroot -proot --table -e "USE clustering; SELECT * FROM test_sync;"

echo "✅ Vérification automatique terminée !"
