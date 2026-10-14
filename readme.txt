=== Lwb Migration ===
Contributors: lewebmax
Tags: migration, sauvegarde, clonage, déménagement, transfert
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrez un site WordPress complet (fichiers + base de données) vers n’importe quel serveur, sans configuration, même sur un hébergement mutualisé bas de gamme.

== Description ==

Lwb Migration crée en un clic un **paquet de migration** composé de deux fichiers :

* une archive (`archive-xxxxxxxx.zip`) contenant les fichiers du site et la base de données ;
* un `installer.php` autonome, qui fonctionne même sur un serveur vide, sans WordPress.

Déposez les deux fichiers à la racine du nouveau serveur, ouvrez `votre-domaine/installer.php`, saisissez les accès à la base de données : le site est restauré, avec toutes les URL et tous les chemins remplacés.

**Migration directe** : collez sur le site source une clé générée par le site de destination, et le transfert se fait de serveur à serveur, en HTTPS, par morceaux, sans rien télécharger sur votre ordinateur.

= Conçu pour les hébergements les plus limités =

* Aucune dépendance à `exec()`, `shell_exec()`, `mysqldump`, `zip` en ligne de commande, SSH ou Composer.
* Fonctionne avec `max_execution_time = 30`, `memory_limit = 128M`, `upload_max_filesize = 2M`.
* Traitement par lots de quelques secondes, piloté par des requêtes AJAX successives ; l’état est sauvegardé après chaque lot.
* Reprise automatique après une erreur, un délai dépassé ou une coupure réseau, au dernier point de sauvegarde.
* Dump SQL en flux, table par table, lecture non bufferisée, `INSERT` multi-lignes, lots adaptatifs.
* Fichiers lus et écrits en flux : un fichier de plusieurs Go est copié sur plusieurs requêtes.
* Archive ZIP standard (ZIP64 au besoin) écrite en PHP pur, ou format de repli « LWB » sans compression.
* PHP 7.4 à 8.4, WordPress 5.8+, MySQL 5.6+ / MariaDB 10.1+.

= Rechercher/remplacer fiable =

* Ancienne URL → nouvelle URL (http et https), ancien chemin absolu → nouveau chemin.
* Données sérialisées PHP analysées et reconstruites (tableaux, objets imbriqués, données sérialisées deux fois) avec recalcul des longueurs, sans instancier aucun objet.
* URL échappées en JSON (`https:\/\/`), encodées (`https%3A%2F%2F`) et relatives au protocole (`//domaine`).
* Changement de préfixe de tables (`wp_` → autre) avec mise à jour des rôles et des capacités.

= Sécurité =

* Réservé aux administrateurs (`manage_options`), nonces sur toutes les actions AJAX.
* Paquets stockés dans `wp-content/uploads/lwb-migration-<aléatoire>/`, protégé par `.htaccess`, `web.config` et `index.php`.
* Noms d’archive aléatoires ; `installer.php` protégé par un mot de passe optionnel.
* Archive et dossier de travail supprimés automatiquement après l’installation ; bouton de suppression de l’installeur.
* Migration directe : clé signée HMAC-SHA256, valable 24 h, à usage unique, transfert HTTPS obligatoire, chaque morceau signé.

== Installation ==

1. Envoyez le dossier `lwb-migration` dans `wp-content/plugins/` (ou installez le fichier zip depuis « Extensions > Ajouter »).
2. Activez l’extension.
3. Ouvrez le menu **Lwb Migration**.

== Frequently Asked Questions ==

= L’extension est-elle copiée sur le nouveau site ? =

Non : le dossier de l’extension et ses paquets sont exclus de l’archive. Installez-la à nouveau si vous en avez besoin sur la destination.

= Quels identifiants utiliser après la migration ? =

Ceux du site source : les utilisateurs sont migrés avec la base de données.

= Mon extension de cache est désactivée après la migration =

Par sécurité, `advanced-cache.php` et `object-cache.php` sont renommés en `.lwb-disabled` (ils contiennent souvent des chemins de l’ancien serveur). Réactivez votre extension de cache après avoir vérifié le site.

= Le transfert direct est refusé (HTTPS) =

La migration directe exige un certificat SSL sur le site de destination. Utilisez sinon l’export classique (archive + installer.php).

= Le multisite est-il pris en charge ? =

Partiellement : les domaines et chemins du réseau sont mis à jour pour un réseau sur un seul domaine. Les domaines personnalisés par site doivent être vérifiés manuellement.

== Screenshots ==

1. Onglet « Exporter » : export en un clic et liste des paquets.
2. Onglet « Migration directe ».
3. L’assistant installer.php.

== Changelog ==

= 1.2.2 =
* Affichage sur mobile et tablette entièrement revu : plus aucun chevauchement ni débordement de 320 à 1280 px, dans l'administration comme dans l'installeur (pourcentage de progression, titres sur deux lignes, onglets, longs noms de fichiers et adresses, rappel du mot de passe, liste des vérifications de l'installeur)
* La barre « Enregistrer les réglages » ne recouvre plus les réglages
* Contrôle automatique de la mise en page dans un vrai navigateur, à chaque test

= 1.2.1 =
* Correction : après une migration directe, le lien « Ouvrir l'installeur sur la destination » affichait « Accès réservé » (cookie de session ignoré par le navigateur quand le lien vient d'un autre site)
* Écran de fin de transfert et pourcentage mieux affichés sur mobile

= 1.2.0 =
* Migration directe : les morceaux envoyés sont brouillés puis reconstitués à l'arrivée, pour que les pare-feux d'hébergeurs (o2switch, Imunify360, ModSecurity…) ne bloquent plus le transfert en 403 quand l'archive contient du code qu'ils jugent suspect
* Reprise possible d'un transfert bloqué après mise à jour des deux sites (bouton Reprendre)
* Messages d'erreur de pare-feu plus précis

= 1.1.0 =
* Migration directe compatible avec les pare-feux anti-robot des hébergeurs (o2switch PowerBoost, Imunify360…) : envoi en PUT avec repli automatique en POST
* Gestion des limitations de débit (HTTP 429) : attente, nouvel essai et cadence adaptée
* Message clair et solution quand un pare-feu bloque le transfert
* Mises à jour automatiques : la nouvelle version est proposée dans Extensions › Mises à jour, avec vérification SHA-256 du fichier
* Bouton « Vérifier les mises à jour » dans l’extension
* Nouvelle identité visuelle aux couleurs de Lewebmax et nouvelle icône
* Les notices des autres extensions ne s’affichent plus dans l’en-tête
* Correction : une étape ne pouvait plus avancer quand le serveur était déjà très chargé (mémoire ou temps) au moment de la reprendre

= 1.0.0 =
* Première version
