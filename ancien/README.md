

les choses a installer
installation ubuntu

installation ha-proxy
    sudo apt update
    sudo apt install haproxy -y

installation xampp (telecharger sur google et installer)

installation d'apache: 
sudo apt install apache2 -y

installation outils pour qu'apache puisse lire php
---------------------------
configuration initial

aller dans /var/www/ et faire la commande sudo chmod -R 777 html/
aller dans /opt/lampp/ et faire la commande sudo chmod -R 777 htdocs/

lancer apache et xampp

----------------------------------------------------
Configurer le fichier /etc/haproxy/haproxy.cfg (la onfugration a faire est deja dans haproxy.cfg)

aller dans http://localhost:8080/clustering/test.html pour tester le switch entre deux serveur
aller dans http://localhost:8080/clustering/session-avec-bd pour tester le session avec base de donnée