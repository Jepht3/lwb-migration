<?php
// Génère le code PHP à exécuter sur lewebmax.com (création de la page Lwb Migration).
$zip  = 'https://github.com/Jepht3/lwb-migration/releases/latest/download/lwb-migration.zip';
$img  = '/wp-content/uploads/lwb-migration/';
$svg  = function ( $d ) {
	return "<svg viewBox='0 0 24 24' width='24' height='24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'>$d</svg>";
};
$card = function ( $icon, $title, $text ) use ( $svg ) {
	return "<div class='lwm-fc'><div class='lwm-ico'>" . $svg( $icon ) . "</div><h3>$title</h3><p>$text</p></div>";
};
$shot = "border-radius:20px;border:1px solid #e2e5f2;box-shadow:0 24px 50px rgba(27,17,156,.14)";

$hero = "<div class='lwm'><section class='lwm-ph'><div class='lwm-w'><div><div class='lwm-crumb'><a href='/'>Accueil</a> › Lwb Migration</div>"
	. "<span class='lwm-kicker'>Extension WordPress gratuite</span><h1>Lwb Migration</h1>"
	. "<p class='lead'>Déménagez un site WordPress complet, fichiers et base de données, vers n’importe quel serveur. Un clic pour exporter, un assistant pour installer, ou un transfert direct de serveur à serveur. Toutes les adresses du site sont mises à jour automatiquement.</p>"
	. "<ul class='lwm-ticks'><li>Gratuit</li><li>Tous les hébergeurs, même mutualisés</li><li>Mises à jour automatiques</li></ul>"
	. "<div class='lwm-btns'><a class='lwm-btn lwm-btn--p' href='$zip'>Télécharger l’extension</a><a class='lwm-btn lwm-btn--g' href='#fonctionnement'>Voir comment ça marche</a></div>"
	. "<p style='font-size:13px;margin-top:16px'>Version <span data-lwb-version>1.1.0</span> · WordPress 5.8+ · PHP 7.4 à 8.4 · MySQL 5.6+ / MariaDB 10.1+</p></div>"
	. "<div class='art' style='text-align:center'><img src='{$img}exporter.webp' alt='Écran Exporter de Lwb Migration dans l’administration WordPress' width='1120' height='1069' style='width:100%;height:auto;$shot'></div></div></section></div>"
	. "<script type='application/ld+json'>" . json_encode(
		array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				array(
					'@type'           => 'BreadcrumbList',
					'itemListElement' => array(
						array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Accueil', 'item' => 'https://lewebmax.com/' ),
						array( '@type' => 'ListItem', 'position' => 2, 'name' => 'Lwb Migration', 'item' => 'https://lewebmax.com/lwb-migration/' ),
					),
				),
				array(
					'@type'               => 'SoftwareApplication',
					'name'                => 'Lwb Migration',
					'applicationCategory' => 'WebApplication',
					'operatingSystem'     => 'WordPress',
					'url'                 => 'https://lewebmax.com/lwb-migration/',
					'downloadUrl'         => $zip,
					'image'               => 'https://lewebmax.com/wp-content/uploads/lwb-migration/icon-256x256.png',
					'publisher'           => array( '@type' => 'Organization', 'name' => 'LEWEBMAX', 'url' => 'https://lewebmax.com/' ),
					'offers'              => array( '@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR' ),
				),
			),
		),
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	) . '</script>';

$features = "<div class='lwm'><section class='lwm-sec'><div class='lwm-w'><div class='lwm-hd-sec'><span class='lwm-kicker'>Fonctionnalités</span><h2>Une migration complète, sans configuration</h2><p>Lwb Migration fait le travail que l’on fait d’habitude à la main : copier les fichiers, exporter la base, corriger les adresses et la configuration.</p></div><div class='lwm-grid3'>"
	. $card( "<path d='M12 3v12'/><path d='m7 10 5 5 5-5'/><path d='M5 21h14'/>", 'Export en un clic', 'Une archive (fichiers + base de données) et un installer.php autonome, prêts à déposer sur le nouveau serveur, même vide.' )
	. $card( "<path d='M4 17l6-6-6-6'/><path d='M12 19h8'/>", 'Installeur guidé', 'Ouvrez votre-domaine/installer.php : vérification du serveur, accès à la base avec bouton « Tester la connexion », puis tout s’enchaîne.' )
	. $card( "<path d='M17.5 19a4.5 4.5 0 1 0-1.4-8.8A6 6 0 0 0 4.5 13'/><path d='M12 12v9'/><path d='m8 16 4-4 4 4'/>", 'Migration directe', 'Collez une clé générée sur le site de destination : le site est envoyé de serveur à serveur, en HTTPS, par morceaux signés.' )
	. $card( "<rect x='3' y='4' width='18' height='6' rx='1.5'/><rect x='3' y='14' width='18' height='6' rx='1.5'/><path d='M7 7h.01M7 17h.01'/>", 'Tous les hébergeurs', 'Petites étapes avec reprise automatique : fonctionne avec 30 s d’exécution et 128 Mo de mémoire, sans exec() ni accès SSH, derrière les pare-feux anti-robot.' )
	. $card( "<path d='M21 21l-4.3-4.3'/><circle cx='11' cy='11' r='7'/><path d='M8 11h6'/>", 'Adresses corrigées partout', 'Ancienne adresse et ancien chemin remplacés, y compris dans les données sérialisées, le JSON et les URL encodées. Préfixe de tables modifiable.' )
	. $card( "<rect x='5' y='11' width='14' height='9' rx='1.5'/><path d='M8 11V8a4 4 0 0 1 8 0v3'/>", 'Sécurisé', 'Installeur protégé par mot de passe, clé de transfert signée valable 24 h, fichiers d’installation supprimés à la fin, mises à jour vérifiées par empreinte SHA-256.' )
	. "</div></div></section></div>";

$shots = "<div class='lwm'><section class='lwm-sec lwm-soft'><div class='lwm-w'><div class='lwm-hd-sec'><span class='lwm-kicker'>Aperçu</span><h2>Captures d’écran</h2></div><div style='display:flex;gap:20px;overflow-x:auto;padding:4px 4px 18px;scroll-snap-type:x mandatory'>";
foreach ( array(
	'exporter.webp'               => 'Création d’un paquet de migration',
	'migration-directe.webp'      => 'Migration directe : clé de transfert',
	'installeur-mot-de-passe.webp' => 'installer.php protégé par mot de passe',
	'installeur-base.webp'        => 'Installeur : accès à la base de données',
	'installeur-progression.webp' => 'Installeur : extraction en cours',
	'installeur-termine.webp'     => 'Installeur : migration réussie',
) as $file => $alt ) {
	$shots .= "<figure style='margin:0;flex:0 0 min(460px,85vw);scroll-snap-align:start'><img src='{$img}{$file}' alt='$alt' loading='lazy' style='width:100%;height:auto;border-radius:16px;border:1px solid #e2e5f2'><figcaption style='font-size:13px;margin-top:8px;color:#5A607F'>$alt</figcaption></figure>";
}
$shots .= '</div></div></section></div>';

$how = "<div class='lwm' id='fonctionnement'><section class='lwm-sec'><div class='lwm-w'><div class='lwm-hd-sec'><span class='lwm-kicker'>Mode d’emploi</span><h2>Votre site déménagé en 3 étapes</h2><p>Méthode classique, avec le paquet à télécharger. Pour la migration directe, générez une clé sur le site de destination et collez-la sur le site source.</p></div><div class='lwm-grid3 lwm-steps'>"
	. "<div class='lwm-fc'><h3>1. Exporter</h3><p>Installez l’extension sur le site à déménager. Dans Lwb Migration › Exporter, choisissez un mot de passe puis cliquez sur « Exporter le site ».</p></div>"
	. "<div class='lwm-fc'><h3>2. Envoyer</h3><p>Téléchargez l’archive et installer.php, puis déposez-les à la racine du nouvel hébergement (FTP ou gestionnaire de fichiers).</p></div>"
	. "<div class='lwm-fc'><h3>3. Installer</h3><p>Ouvrez votre-domaine/installer.php, saisissez les accès à la base de données : le site est restauré à sa nouvelle adresse.</p></div>"
	. '</div></div></section></div>';

$changelog = "<div class='lwm'><section class='lwm-sec lwm-soft'><div class='lwm-w'><div class='lwm-hd-sec'><span class='lwm-kicker'>Nouveautés</span><h2>Journal des modifications</h2></div><div class='lwm-faq' id='lwb-log'>"
	. "<details open><summary>Version 1.1.0</summary><div class='a'><ul><li>Migration directe compatible avec les pare-feux anti-robot des hébergeurs</li><li>Mises à jour automatiques depuis WordPress</li><li>Nouvelle identité visuelle aux couleurs de Lewebmax</li></ul></div></details>"
	. "<details><summary>Version 1.0.0</summary><div class='a'><ul><li>Première version</li></ul></div></details>"
	. "</div><p style='text-align:center;font-size:14px;margin-top:18px'><a href='https://github.com/Jepht3/lwb-migration/releases' target='_blank' rel='noopener' style='color:#2A1FD0;font-weight:700'>Toutes les versions sur GitHub</a></p></div></section></div>"
	. "<script>(function(){var box=document.getElementById('lwb-log');if(!box||!window.fetch)return;function esc(s){return String(s).replace(/[&<>\"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',\"'\":'&#39;'}[c];});}"
	. "fetch('https://api.github.com/repos/Jepht3/lwb-migration/releases?per_page=6').then(function(r){return r.ok?r.json():[];}).then(function(list){list=(list||[]).filter(function(x){return !x.draft&&!x.prerelease;});if(!list.length)return;"
	. "document.querySelectorAll('[data-lwb-version]').forEach(function(el){el.textContent=list[0].tag_name.replace(/^v/,'');});"
	. "box.innerHTML=list.map(function(rel,i){var d=new Date(rel.published_at).toLocaleDateString('fr-FR',{day:'numeric',month:'long',year:'numeric'});var html='';(rel.body||'').split(/\\r?\\n/).forEach(function(l){if(/^[-*]\\s/.test(l))html+='<li>'+esc(l.replace(/^[-*]\\s*/,''))+'</li>';});"
	. "return '<details'+(i===0?' open':'')+'><summary>Version '+esc(rel.tag_name.replace(/^v/,''))+' · '+esc(d)+'</summary><div class=\"a\"><ul>'+html+'</ul></div></details>';}).join('');}).catch(function(){});})();</script>";

$faq_items = array(
	'Faut-il installer WordPress sur le nouveau serveur ?' => 'Non. Pour la méthode classique, le serveur de destination peut être vide : installer.php restaure tout, fichiers et base de données. Il suffit d’avoir créé une base de données chez l’hébergeur (ou de laisser l’installeur la créer si vos droits le permettent).',
	'Fonctionne-t-il chez mon hébergeur ?' => 'Lwb Migration est conçue pour les hébergements les plus limités : pas de commande système, pas de SSH, des étapes de quelques secondes avec reprise automatique. Elle passe aussi les pare-feux anti-robot qui bloquent les échanges entre serveurs (o2switch PowerBoost, Imunify360…).',
	'Quels identifiants utiliser après la migration ?' => 'Ceux du site d’origine : les comptes utilisateurs sont migrés avec la base de données.',
	'L’extension se met-elle à jour toute seule ?' => 'Oui. Chaque nouvelle version est proposée dans Extensions › Mises à jour de votre WordPress, comme n’importe quelle extension, et son intégrité est vérifiée avant installation.',
	'Mon extension de cache est désactivée après la migration' => 'C’est volontaire : les fichiers advanced-cache.php et object-cache.php contiennent souvent des chemins de l’ancien serveur. Réactivez votre extension de cache après avoir vérifié le site.',
);
$faq = "<div class='lwm'><section class='lwm-sec'><div class='lwm-w'><div class='lwm-hd-sec'><h2>Questions fréquentes</h2></div><div class='lwm-faq'>";
foreach ( $faq_items as $q => $a ) {
	$faq .= "<details><summary>$q</summary><div class='a'><p>$a</p></div></details>";
}
$faq .= '</div></div></section></div>';

$cta = "<div class='lwm'><section class='lwm-sec lwm-soft'><div class='lwm-w'><div class='lwm-cta'><h2>Prêt à déménager votre site ?</h2><p>Téléchargez Lwb Migration, installez-la comme n’importe quelle extension, et créez votre premier paquet en un clic. Les versions suivantes vous seront proposées automatiquement.</p><div class='lwm-btns'><a class='lwm-btn lwm-btn--p' href='$zip'>Télécharger l’extension</a><a class='lwm-btn lwm-btn--g' href='/contact/'>Une question ? Contactez-nous</a></div></div></div></section></div>";

$widgets = array(
	array( 'id' => 'lwbm-header', 'elType' => 'widget', 'widgetType' => 'template', 'settings' => array( 'template_id' => '6269' ), 'elements' => array() ),
	array( 'id' => 'lwbm-hero', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => $hero ), 'elements' => array() ),
	array( 'id' => 'lwbm-features', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => $features ), 'elements' => array() ),
	array( 'id' => 'lwbm-shots', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => $shots ), 'elements' => array() ),
	array( 'id' => 'lwbm-how', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => $how ), 'elements' => array() ),
	array( 'id' => 'lwbm-changelog', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => $changelog ), 'elements' => array() ),
	array( 'id' => 'lwbm-faq', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => $faq ), 'elements' => array() ),
	array( 'id' => 'lwbm-cta', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => $cta ), 'elements' => array() ),
	array( 'id' => 'lwbm-footer', 'elType' => 'widget', 'widgetType' => 'template', 'settings' => array( 'template_id' => '6271' ), 'elements' => array() ),
);
$zero = array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true );
$data = array(
	array(
		'id'       => 'lwbm-page',
		'elType'   => 'section',
		'settings' => array( 'layout' => 'full_width', 'gap' => 'no', 'padding' => $zero, 'margin' => $zero ),
		'elements' => array(
			array( 'id' => 'lwbm-col', 'elType' => 'column', 'settings' => array( '_column_size' => 100, 'padding' => $zero ), 'elements' => $widgets ),
		),
		'isInner'  => false,
	),
);
file_put_contents( __DIR__ . '/elementor.json', json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
echo strlen( json_encode( $data ) ), "\n";
