#!/bin/sh

echo "🔧 Correction des permissions MySQL..."

wait_for_container() {
  name=$1
  echo "⏳ Attente du conteneur $name..."

  # Attendre le conteneur docker
  until docker ps --format '{{.Names}}' | grep -q "$name"; do
    sleep 1
  done
  echo "✔ Conteneur $name trouvé."
}

wait_for_mysql_running() {
  name=$1
  echo "⏳ Attente que MySQL démarre dans $name..."

  until docker exec "$name" mysqladmin ping -uroot -proot --silent 2>/dev/null; do
    sleep 1
  done

  echo "✔ MySQL prêt dans $name."
}

fix_for() {
  container=$1
  path="/etc/mysql/conf.d/my.cnf"

  wait_for_container "$container"
  wait_for_mysql_running "$container"

  echo "🔒 Mise à jour des permissions sur $container..."
  docker exec "$container" chmod 644 "$path" || true
  docker exec "$container" chown mysql:mysql "$path" || true

  echo "✔ Permissions corrigées pour $container"
}

fix_for "mysql1"
fix_for "mysql2"
fix_for "mysql3" 2>/dev/null || true

echo "🎉 Permissions MySQL mises à jour avec succès !"
