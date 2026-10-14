<?php
/**
 * Onglet « Exporter ».
 *
 * @package LwbMigration
 */

defined( 'ABSPATH' ) || exit;

$lwbm_env    = \LwbMigration\Admin::environment();
$lwbm_direct = false;
?>
<div class="lwbm-grid">
	<section class="lwbm-card lwbm-card-main">
		<div class="lwbm-card-head">
			<span class="lwbm-icon lwbm-icon-primary" aria-hidden="true"><span class="dashicons dashicons-archive"></span></span>
			<div>
				<h2><?php esc_html_e( 'Créer un paquet de migration', 'lwb-migration' ); ?></h2>
				<p><?php esc_html_e( 'Une archive (fichiers + base de données) et un installer.php autonome, prêts à déposer sur le nouveau serveur.', 'lwb-migration' ); ?></p>
			</div>
		</div>

		<ol class="lwbm-flow">
			<li><span class="lwbm-flow-num">1</span><strong><?php esc_html_e( 'Exporter', 'lwb-migration' ); ?></strong><span><?php esc_html_e( 'en un clic, ici', 'lwb-migration' ); ?></span></li>
			<li><span class="lwbm-flow-num">2</span><strong><?php esc_html_e( 'Envoyer', 'lwb-migration' ); ?></strong><span><?php esc_html_e( '2 fichiers à la racine du serveur', 'lwb-migration' ); ?></span></li>
			<li><span class="lwbm-flow-num">3</span><strong><?php esc_html_e( 'Installer', 'lwb-migration' ); ?></strong><span><?php esc_html_e( 'via votre-domaine/installer.php', 'lwb-migration' ); ?></span></li>
		</ol>

		<div class="lwbm-field">
			<label for="lwbm-password"><?php esc_html_e( 'Mot de passe de l’installeur', 'lwb-migration' ); ?> <span class="lwbm-pill lwbm-pill-info"><?php esc_html_e( 'obligatoire', 'lwb-migration' ); ?></span></label>
			<div class="lwbm-input-group">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<input type="text" id="lwbm-password" autocomplete="new-password" spellcheck="false" minlength="8" placeholder="<?php esc_attr_e( 'Laissez vide pour en générer un automatiquement', 'lwb-migration' ); ?>">
				<button type="button" class="lwbm-btn lwbm-btn-soft" id="lwbm-generate-password"><span class="dashicons dashicons-randomize" aria-hidden="true"></span> <?php esc_html_e( 'Générer', 'lwb-migration' ); ?></button>
			</div>
			<p class="lwbm-hint"><?php esc_html_e( 'Demandé à l’ouverture d’installer.php : personne d’autre ne pourra lancer l’installation. Laissé vide, un mot de passe est généré et affiché à la fin de l’export : notez-le bien.', 'lwb-migration' ); ?></p>
		</div>

		<button type="button" class="lwbm-btn lwbm-btn-primary lwbm-btn-lg" id="lwbm-export">
			<span class="dashicons dashicons-download" aria-hidden="true"></span>
			<?php esc_html_e( 'Exporter le site', 'lwb-migration' ); ?>
		</button>

		<?php require __DIR__ . '/progress.php'; ?>

		<div class="lwbm-result" id="lwbm-export-result" hidden>
			<span class="lwbm-result-icon" aria-hidden="true"><span class="dashicons dashicons-yes"></span></span>
			<div>
				<h3><?php esc_html_e( 'Paquet prêt !', 'lwb-migration' ); ?></h3>
				<p><?php esc_html_e( 'Téléchargez les deux fichiers et envoyez-les à la racine du site de destination (FTP ou gestionnaire de fichiers).', 'lwb-migration' ); ?></p>
				<div class="lwbm-actions">
					<a class="lwbm-btn lwbm-btn-primary" data-lwbm-dl="archive" href="#"><span class="dashicons dashicons-media-archive" aria-hidden="true"></span> <?php esc_html_e( 'Télécharger l’archive', 'lwb-migration' ); ?></a>
					<a class="lwbm-btn lwbm-btn-dark" data-lwbm-dl="installer" href="#"><span class="dashicons dashicons-media-code" aria-hidden="true"></span> <?php esc_html_e( 'Télécharger installer.php', 'lwb-migration' ); ?></a>
				</div>
				<p class="lwbm-password-reminder" hidden><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e( 'Mot de passe de l’installeur :', 'lwb-migration' ); ?> <code data-lwbm-password></code></p>
			</div>
		</div>
	</section>

	<aside class="lwbm-side">
		<section class="lwbm-card">
			<h2 class="lwbm-card-title"><span class="dashicons dashicons-performance" aria-hidden="true"></span> <?php esc_html_e( 'Votre serveur', 'lwb-migration' ); ?></h2>
			<ul class="lwbm-env">
				<?php foreach ( $lwbm_env as $lwbm_item ) : ?>
					<li class="<?php echo $lwbm_item['ok'] ? 'is-ok' : 'is-warn'; ?>">
						<span class="lwbm-env-dot" aria-hidden="true"></span>
						<span class="lwbm-env-label"><?php echo esc_html( $lwbm_item['label'] ); ?></span>
						<strong><?php echo esc_html( $lwbm_item['value'] ); ?></strong>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<section class="lwbm-card lwbm-card-tip">
			<h2 class="lwbm-card-title"><span class="dashicons dashicons-shield" aria-hidden="true"></span> <?php esc_html_e( 'Conçu pour tous les hébergeurs', 'lwb-migration' ); ?></h2>
			<p><?php esc_html_e( 'Traitement par petites étapes et reprise automatique : aucune configuration, même avec 30 s d’exécution et 128 Mo de mémoire.', 'lwb-migration' ); ?></p>
		</section>
	</aside>
</div>

<section class="lwbm-card">
	<div class="lwbm-section-head">
		<h2 class="lwbm-card-title"><span class="dashicons dashicons-portfolio" aria-hidden="true"></span> <?php esc_html_e( 'Paquets disponibles', 'lwb-migration' ); ?></h2>
	</div>
	<div class="lwbm-packages" id="lwbm-packages-body">
		<div class="lwbm-empty"><?php esc_html_e( 'Chargement…', 'lwb-migration' ); ?></div>
	</div>
</section>
