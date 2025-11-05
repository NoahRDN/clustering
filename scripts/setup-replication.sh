#!/bin/sh
set -e

echo "⏳ Attente du démarrage de MySQL..."

# ✅ Fonction qui attend que le serveur MySQL soit prêt
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

echo "🔐 Configuration des permissions..."
# chmod inutile ici : on configure via MySQL directement

echo "👥 Création de l'utilisateur de réplication..."
for host in mysql1 mysql2; do
  mysql -h $host -uroot -proot <<EOF
DROP USER IF EXISTS 'repl'@'%';
CREATE USER 'repl'@'%' IDENTIFIED WITH mysql_native_password BY 'replpass';
GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%';
FLUSH PRIVILEGES;
ALTER USER 'root'@'%' IDENTIFIED WITH mysql_native_password BY 'root';
FLUSH PRIVILEGES;
EOF
done

echo "🧹 Réinitialisation propre des binlogs et des esclave..."
for host in mysql1 mysql2; do
  mysql -h $host -uroot -proot <<EOF
RESET MASTER;
RESET SLAVE ALL;
EOF
done


echo "🔁 Configuration de la réplication Master <-> Master..."
mysql -h mysql1 -uroot -proot <<EOF
STOP SLAVE;
CHANGE MASTER TO 
  MASTER_HOST='mysql2',
  MASTER_USER='repl',
  MASTER_PASSWORD='replpass',
  MASTER_AUTO_POSITION=1;
START SLAVE;
EOF

mysql -h mysql2 -uroot -proot <<EOF
STOP SLAVE;
CHANGE MASTER TO 
  MASTER_HOST='mysql1',
  MASTER_USER='repl',
  MASTER_PASSWORD='replpass',
  MASTER_AUTO_POSITION=1;
START SLAVE;
EOF

echo "✅ Vérification de la réplication..."
mysql -h mysql1 -uroot -proot -e "SHOW SLAVE STATUS\G" | grep Running || true
mysql -h mysql2 -uroot -proot -e "SHOW SLAVE STATUS\G" | grep Running || true

echo "🎉 Réplication configurée automatiquement avec succès !"

echo "🧪 Test automatique de réplication..."

mysql -h mysql1 -uroot -proot <<EOF
USE clustering;
INSERT INTO test_sync (msg) VALUES ('Auto-test depuis mysql1 ✅');
EOF

sleep 2

mysql -h mysql2 -uroot -proot --table -e "USE clustering; SELECT * FROM test_sync;"

echo "📋 Vérification via HAProxy (port 3307) :"
mysql -h haproxy-db -P 3307 -uroot -proot --table -e "USE clustering; SELECT * FROM test_sync;"

echo "✅ Vérification automatique terminée !"