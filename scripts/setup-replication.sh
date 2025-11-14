#!/bin/sh
set -e

echo "⏳ Attente du démarrage de MySQL..."

wait_for_mysql() {
  host="$1"
  until mysqladmin ping -h"$host" -uroot -proot --silent; do
    echo "⏳ En attente de $host..."
    sleep 3
  done
  echo "✅ $host est prêt."
}

wait_for_mysql mysql1
wait_for_mysql mysql2

HAS_MYSQL3=0
if mysqladmin ping -hmysql3 -uroot -proot --silent >/dev/null 2>&1; then
  wait_for_mysql mysql3
  HAS_MYSQL3=1
else
  echo "ℹ️ mysql3 n'est pas présent (optionnel)."
fi

echo "🔐 Configuration des server_id..."
mysql -h mysql1 -uroot -proot -e "SET GLOBAL server_id = 1;"
mysql -h mysql2 -uroot -proot -e "SET GLOBAL server_id = 2;"
if [ "$HAS_MYSQL3" -eq 1 ]; then
  mysql -h mysql3 -uroot -proot -e "SET GLOBAL server_id = 3;"
fi

echo "👥 Création de l'utilisateur de réplication..."
for host in mysql1 mysql2; do
  mysql -h "$host" -uroot -proot <<EOF
DROP USER IF EXISTS 'repl'@'%';
CREATE USER 'repl'@'%' IDENTIFIED WITH mysql_native_password BY 'replpass';
GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%';
FLUSH PRIVILEGES;
ALTER USER 'root'@'%' IDENTIFIED WITH mysql_native_password BY 'root';
FLUSH PRIVILEGES;
EOF
done
if [ "$HAS_MYSQL3" -eq 1 ]; then
  mysql -h mysql3 -uroot -proot <<EOF
DROP USER IF EXISTS 'repl'@'%';
CREATE USER 'repl'@'%' IDENTIFIED WITH mysql_native_password BY 'replpass';
GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%';
FLUSH PRIVILEGES;
ALTER USER 'root'@'%' IDENTIFIED WITH mysql_native_password BY 'root';
FLUSH PRIVILEGES;
EOF
fi

echo "🧹 Reset des binlogs et des esclaves..."
for host in mysql1 mysql2; do
  mysql -h "$host" -uroot -proot -e "RESET MASTER; RESET SLAVE ALL;"
done
if [ "$HAS_MYSQL3" -eq 1 ]; then
  mysql -h mysql3 -uroot -proot -e "RESET MASTER; RESET SLAVE ALL;"
fi

echo "� Vérification du mode GTID..."
GTID_MODE1=$(mysql -h mysql1 -uroot -proot -sN -e "SELECT @@GLOBAL.GTID_MODE;")
GTID_MODE2=$(mysql -h mysql2 -uroot -proot -sN -e "SELECT @@GLOBAL.GTID_MODE;")
if [ "$HAS_MYSQL3" -eq 1 ]; then
  GTID_MODE3=$(mysql -h mysql3 -uroot -proot -sN -e "SELECT @@GLOBAL.GTID_MODE;")
fi

echo "GTID mysql1=$GTID_MODE1 / mysql2=$GTID_MODE2"
if [ "$HAS_MYSQL3" -eq 1 ]; then
  echo "GTID mysql3=$GTID_MODE3"
fi

configure_peer_gtid() {
  src="$1"
  dst="$2"
  mysql -h "$dst" -uroot -proot <<EOF
STOP SLAVE;
CHANGE MASTER TO 
  MASTER_HOST='${src}',
  MASTER_USER='repl',
  MASTER_PASSWORD='replpass',
  MASTER_AUTO_POSITION=1;
START SLAVE;
EOF
}

if [ "$GTID_MODE1" = "ON" ] && [ "$GTID_MODE2" = "ON" ] && { [ "$HAS_MYSQL3" -eq 0 ] || [ "$GTID_MODE3" = "ON" ]; }; then
  echo "🔁 Configuration GTID multi-master"
  configure_peer_gtid mysql2 mysql1
  configure_peer_gtid mysql1 mysql2
  if [ "$HAS_MYSQL3" -eq 1 ]; then
    configure_peer_gtid mysql3 mysql1
    configure_peer_gtid mysql1 mysql3
    configure_peer_gtid mysql3 mysql2
    configure_peer_gtid mysql2 mysql3
  fi
else
  echo "🔁 Configuration classique (sans GTID)"
  MYSQL1_STATUS=$(mysql -h mysql1 -uroot -proot -e "SHOW MASTER STATUS\G")
  MYSQL1_FILE=$(echo "$MYSQL1_STATUS" | grep "File:" | awk '{print $2}')
  MYSQL1_POS=$(echo "$MYSQL1_STATUS" | grep "Position:" | awk '{print $2}')

  MYSQL2_STATUS=$(mysql -h mysql2 -uroot -proot -e "SHOW MASTER STATUS\G")
  MYSQL2_FILE=$(echo "$MYSQL2_STATUS" | grep "File:" | awk '{print $2}')
  MYSQL2_POS=$(echo "$MYSQL2_STATUS" | grep "Position:" | awk '{print $2}')

  mysql -h mysql1 -uroot -proot <<EOF
STOP SLAVE;
CHANGE MASTER TO 
  MASTER_HOST='mysql2',
  MASTER_USER='repl',
  MASTER_PASSWORD='replpass',
  MASTER_LOG_FILE='$MYSQL2_FILE',
  MASTER_LOG_POS=$MYSQL2_POS;
START SLAVE;
EOF

  mysql -h mysql2 -uroot -proot <<EOF
STOP SLAVE;
CHANGE MASTER TO 
  MASTER_HOST='mysql1',
  MASTER_USER='repl',
  MASTER_PASSWORD='replpass',
  MASTER_LOG_FILE='$MYSQL1_FILE',
  MASTER_LOG_POS=$MYSQL1_POS;
START SLAVE;
EOF

  if [ "$HAS_MYSQL3" -eq 1 ]; then
    MYSQL3_STATUS=$(mysql -h mysql1 -uroot -proot -e "SHOW MASTER STATUS\G")
    MYSQL3_FILE=$(echo "$MYSQL3_STATUS" | grep "File:" | awk '{print $2}')
    MYSQL3_POS=$(echo "$MYSQL3_STATUS" | grep "Position:" | awk '{print $2}')
    mysql -h mysql3 -uroot -proot <<EOF
STOP SLAVE;
CHANGE MASTER TO 
  MASTER_HOST='mysql1',
  MASTER_USER='repl',
  MASTER_PASSWORD='replpass',
  MASTER_LOG_FILE='$MYSQL3_FILE',
  MASTER_LOG_POS=$MYSQL3_POS;
START SLAVE;
EOF
  fi
fi

echo "✅ Vérification de la réplication..."
mysql -h mysql1 -uroot -proot -e "SHOW SLAVE STATUS\G" | grep Running || true
mysql -h mysql2 -uroot -proot -e "SHOW SLAVE STATUS\G" | grep Running || true
if [ "$HAS_MYSQL3" -eq 1 ]; then
  mysql -h mysql3 -uroot -proot -e "SHOW SLAVE STATUS\G" | grep Running || true
fi

echo "🧪 Test automatique..."
mysql -h mysql1 -uroot -proot <<EOF
USE clustering;
INSERT INTO test_sync (msg) VALUES ('Auto-test depuis mysql1 ✅');
EOF

sleep 2

mysql -h mysql2 -uroot -proot --table -e "USE clustering; SELECT * FROM test_sync;"
if [ "$HAS_MYSQL3" -eq 1 ]; then
  mysql -h mysql3 -uroot -proot --table -e "USE clustering; SELECT * FROM test_sync;"
fi

echo "📋 Vérification via HAProxy (port 3307) :"
mysql -h haproxy-db -P 3307 -uroot -proot --table -e "USE clustering; SELECT * FROM test_sync;"

echo "✅ Configuration terminée."
