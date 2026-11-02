<?php
// Mise à jour de la page lewebmax.com/lwb-migration pour la version 1.7.0 (exécutée sur le serveur, à la suite des modifications faites en ligne).
$p = get_page_by_path('lwb-migration', OBJECT, 'page');
$raw = get_post_meta($p->ID, '_elementor_data', true);
$j = json_decode($raw, true);
$v = '?v=170';
$ico = function ($d) { return "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\" width='24' height='24' fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.75\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\">$d</svg>"; };
$card = function ($d, $t, $x) use ($ico) { return "<div class='lwm-fc'><div class='lwm-ico'>" . $ico($d) . "</div><h3>$t</h3><p>$x</p></div>"; };
$img = '/wp-content/uploads/lwb-migration/';
$edits = array(
	'lwbm-hero' => array(
		array("<p class='lead'>Déménagez un site WordPress complet, fichiers et base de données, vers n’importe quel serveur. Un clic pour exporter, un assistant pour installer, ou un transfert direct de serveur à serveur. Toutes les adresses du site sont mises à jour automatiquement.</p>",
			"<p class='lead'>Déménagez et sauvegardez un site WordPress complet, fichiers et base de données. Exportez un seul fichier, importez-le sur le nouveau site et cliquez sur Restaurer, ou transférez-le directement de serveur à serveur. Toutes les adresses du site sont mises à jour automatiquement.</p>"),
		array("<li>Retour arrière en un clic</li>", "<li>Un seul fichier</li><li>Sauvegardes automatiques</li><li>Retour arrière en un clic</li>"),
		array("<span data-lwb-version>1.4.0</span>", "<span data-lwb-version>1.7.0</span>"),
		array("<img src='/wp-content/uploads/lwb-migration/exporter.webp' alt='Écran Exporter de Lwb Migration dans l’administration WordPress' width='1120' height='1069'",
			"<img src='{$img}tableau-de-bord.webp{$v}' alt='Tableau de bord de Lwb Migration : Exporter, Migration directe, Importer, Sauvegardes, Paramètres' width='1120' height='1010'"),
	),
	'lwbm-features' => array(
		array("<h2>Une migration complète, sans configuration</h2><p>Lwb Migration fait le travail que l’on fait d’habitude à la main : copier les fichiers, exporter la base, corriger les adresses et la configuration.</p>",
			"<h2>Migrer, sauvegarder, restaurer : sans configuration</h2><p>Lwb Migration fait le travail que l’on fait d’habitude à la main : copier les fichiers, exporter la base, corriger les adresses et la configuration. Et elle sauvegarde votre site toute seule.</p>"),
		array("<h3>Export en un clic</h3><p>Une archive (fichiers + base de données) et un installer.php autonome, prêts à déposer sur le nouveau serveur, même vide.</p></div>",
			"<h3>Un seul fichier</h3><p>Le site entier (fichiers + base de données) dans un seul fichier au nom lisible. Sur le nouveau site : Importer, puis Restaurer. Serveur vide ? installer.php reste disponible.</p></div>"
			. $card("<path d='M12 21V9'/><path d='m7 14 5-5 5 5'/><path d='M5 3h14'/>", 'Import sans FTP', 'Glissez le fichier dans l’administration du nouveau site : il est envoyé par petits morceaux, avec reprise automatique, même avec une limite d’envoi de 2 Mo et derrière les pare-feux.')
			. $card("<rect x='3' y='4' width='18' height='18' rx='2'/><path d='M16 2v4'/><path d='M8 2v4'/><path d='M3 10h18'/><path d='M12 14v3l2 1'/>", 'Sauvegardes automatiques', 'Deux fois par jour, chaque jour, chaque semaine ou chaque mois, à l’heure choisie : site complet ou base seule, nombre de copies conservées, alerte par e-mail. Chaque sauvegarde se restaure en un clic.')),
		array("<h3>Contrôle après migration</h3><p>À la fin, l’installeur vérifie l’accueil, les liens vers l’ancien domaine, les styles et la page de connexion. Une extension qui provoque une erreur critique est désactivée automatiquement.</p></div>",
			"<h3>Contrôle après migration</h3><p>À la fin, l’installeur vérifie l’accueil, les liens vers l’ancien domaine, les styles et la page de connexion. Une extension qui provoque une erreur critique est désactivée automatiquement.</p></div>"
			. $card("<rect x='3' y='3' width='7' height='7' rx='1.5'/><rect x='14' y='3' width='7' height='7' rx='1.5'/><rect x='3' y='14' width='7' height='7' rx='1.5'/><rect x='14' y='14' width='7' height='7' rx='1.5'/>", 'Tableau de bord simple', 'De grandes tuiles « Que voulez-vous faire ? », des choix par cartes et de gros boutons, aussi confortables sur ordinateur que sur téléphone.')),
	),
	'lwbm-how' => array(
		array("<p>Méthode classique, avec le paquet à télécharger. Pour la migration directe, générez une clé sur le site de destination et collez-la sur le site source.</p>",
			"<p>Avec un seul fichier, comme vous le feriez avec All-in-One WP Migration. Serveur vide ? Téléchargez aussi installer.php à la fin de l’export. Pas envie de télécharger ? Utilisez la migration directe, de serveur à serveur.</p>"),
		array("<h3>1. Exporter</h3><p>Installez l’extension sur le site à déménager. Dans Lwb Migration › Exporter, cliquez sur « Exporter le site » : un mot de passe d’installeur est généré (ou choisissez le vôtre), et il chiffre aussi l’archive.</p>",
			"<h3>1. Exporter</h3><p>Sur le site à déménager : Lwb Migration › Exporter le site. Téléchargez le fichier du site ; notez le mot de passe affiché s’il est chiffré.</p>"),
		array("<h3>2. Envoyer</h3><p>Téléchargez l’archive et installer.php, puis déposez-les à la racine du nouvel hébergement (FTP ou gestionnaire de fichiers).</p>",
			"<h3>2. Préparer</h3><p>Sur le nouvel hébergement, installez WordPress puis Lwb Migration, comme n’importe quelle extension.</p>"),
		array("<h3>3. Installer</h3><p>Ouvrez votre-domaine/installer.php, saisissez les accès à la base de données : le site est restauré à sa nouvelle adresse. En cas de problème, un bouton remet l’ancien site en place.</p>",
			"<h3>3. Restaurer</h3><p>Lwb Migration › Importer un site : glissez le fichier et cliquez sur « Restaurer ce site ». Les adresses sont corrigées, et l’ancien site reste récupérable en un clic.</p>"),
	),
	'lwbm-faq' => array(
		array("<p>Non. Pour la méthode classique, le serveur de destination peut être vide : installer.php restaure tout, fichiers et base de données. Il suffit d’avoir créé une base de données chez l’hébergeur (ou de laisser l’installeur la créer si vos droits le permettent).</p>",
			"<p>Pour la méthode la plus simple, oui : installez WordPress et Lwb Migration sur le nouveau site, puis importez le fichier et cliquez sur Restaurer. Si le serveur est vide, téléchargez aussi installer.php à la fin de l’export et déposez les deux fichiers par FTP : l’installeur restaure tout, sans WordPress.</p>"),
		array("</div></details></div></div></section></div>",
			"</div></details><details><summary>Comment fonctionnent les sauvegardes automatiques ?</summary><div class='a'><p>Dans Lwb Migration › Sauvegardes, choisissez la fréquence, l’heure, le contenu (site complet ou base seule), le nombre de copies à garder et les alertes par e-mail. Les sauvegardes se font en arrière-plan, par petites étapes, sans ralentir le site, et se restaurent en un clic. Téléchargez-en régulièrement une copie pour vous protéger aussi d’une panne de l’hébergement.</p></div></details></div></div></section></div>"),
	),
	'lwbm-cta' => array(
		array("et créez votre premier paquet en un clic.", "et exportez votre site en un seul fichier."),
	),
	'lwbm-changelog' => array(
		array("<details open><summary>Version 1.4.0</summary>",
			"<details open><summary>Version 1.7.0</summary><div class='a'><ul><li>Nouveau tableau de bord : grandes tuiles, choix par cartes, gros boutons</li><li>Un seul fichier à exporter puis importer et restaurer, comme All-in-One WP Migration</li><li>Import par glisser-déposer</li></ul></div></details><details><summary>Version 1.6.0</summary><div class='a'><ul><li>Sauvegardes automatiques configurables et restauration en un clic</li></ul></div></details><details><summary>Version 1.4.0</summary>"),
	),
);
$errors = array();
foreach ($j[0]['elements'][0]['elements'] as &$w) {
	if (!isset($edits[$w['id']])) continue;
	foreach ($edits[$w['id']] as $e) {
		$n = substr_count($w['settings']['html'], $e[0]);
		if (1 !== $n) { $errors[] = $w['id'] . ' : ' . $n . ' × ' . mb_substr($e[0], 0, 60); continue; }
		$w['settings']['html'] = str_replace($e[0], $e[1], $w['settings']['html']);
	}
}
unset($w);
// Captures : nouvelle galerie (tableau de bord 1.7.0 + installeur).
$figs = array(
	array('tableau-de-bord.webp', 'Accueil : « Que voulez-vous faire ? »', 460),
	array('exporter.webp', 'Exporter le site : un seul fichier', 460),
	array('importer.webp', 'Importer un site : glisser-déposer, puis Restaurer', 460),
	array('sauvegardes.webp', 'Sauvegardes automatiques', 460),
	array('migration-directe.webp', 'Migration directe, de serveur à serveur', 460),
	array('mobile.webp', 'Sur téléphone', 260),
	array('installeur-termine.webp', 'installer.php : migration réussie', 460),
);
$shots = "<div class='lwm'><section class='lwm-sec lwm-soft'><div class='lwm-w'><div class='lwm-hd-sec'><span class='lwm-kicker'>Aperçu</span><h2>Captures d’écran</h2></div><div style='display:flex;gap:20px;overflow-x:auto;padding:4px 4px 18px;scroll-snap-type:x mandatory;align-items:flex-start'>";
foreach ($figs as $f) {
	$src = $img . $f[0] . ('installeur-termine.webp' === $f[0] ? '' : $v);
	$shots .= "<figure style='margin:0;flex:0 0 min({$f[2]}px,85vw);scroll-snap-align:start'><img src='$src' alt='" . esc_attr($f[1]) . "' loading='lazy' style='width:100%;height:auto;border-radius:16px;border:1px solid #e2e5f2'><figcaption style='font-size:13px;margin-top:8px;color:#5A607F'>" . esc_html($f[1]) . "</figcaption></figure>";
}
$shots .= '</div></div></section></div>';
foreach ($j[0]['elements'][0]['elements'] as &$w) { if ('lwbm-shots' === $w['id']) { $w['settings']['html'] = $shots; } }
unset($w);
if ($errors) return array('erreurs' => $errors);
if (!defined('LWM_SAVE')) return array('essai' => 'ok', 'remplacements' => 'tous trouvés');
update_post_meta($p->ID, '_elementor_data_backup_v16', wp_slash($raw));
update_post_meta($p->ID, '_elementor_data', wp_slash(wp_json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
update_post_meta($p->ID, '_yoast_wpseo_metadesc', "Exportez votre site WordPress en un seul fichier, importez-le et restaurez-le en un clic, ou migrez de serveur à serveur. Sauvegardes automatiques, adresses corrigées, tous les hébergeurs. Extension gratuite par LEWEBMAX.");
wp_update_post(array('ID' => $p->ID));
delete_post_meta($p->ID, '_elementor_css');
delete_post_meta($p->ID, '_elementor_element_cache');
if (class_exists('\Elementor\Plugin')) { \Elementor\Plugin::$instance->files_manager->clear_cache(); }
$r = wp_remote_get(add_query_arg('nc', time(), get_permalink($p->ID)), array('timeout' => 30));
$b = wp_remote_retrieve_body($r);
$chk = array();
foreach (array('Un seul fichier', 'Import sans FTP', 'Sauvegardes automatiques', 'Tableau de bord simple', 'tableau-de-bord.webp', 'importer.webp', 'mobile.webp', '3. Restaurer', 'Comment fonctionnent les sauvegardes', 'Version 1.7.0') as $k) { $chk[$k] = substr_count($b, $k); }
return array('code' => wp_remote_retrieve_response_code($r), 'len' => strlen($b), 'checks' => $chk);
