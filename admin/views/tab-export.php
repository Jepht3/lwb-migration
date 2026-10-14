<?php
/**
 * Onglet « Exporter ».
 *
 * @package LwbMigration
 */

defined( 'ABSPATH' ) || exit;

$lwbm_env = \LwbMigration\Admin::environment();
?>
<div class="lwbm-grid">
	<section class="lwbm-card lwbm-card-main">
		<h2><?php esc_html_e( 'Créer un paquet de migration', 'lwb-migration' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Le paquet contient une archive (fichiers + base de données) et un installer.php autonome. Déposez les deux fichiers à la racine du nouveau serveur, puis ouvrez votre-domaine/installer.php.', 'lwb-migration' ); ?>
		</p>

		<div class="lwbm-field">
			<label for="lwbm-password"><?php esc_html_e( 'Mot de passe de l’installeur (recommandé)', 'lwb-migration' ); ?></label>
			<div class="lwbm-inline">
				<input type="text" id="lwbm-password" class="regular-text" autocomplete="new-password" spellcheck="false" minlength="8">
				<button type="button" class="button" id="lwbm-generate-password"><?php esc_html_e( 'Générer', 'lwb-migration' ); ?></button>
			</div>
			<p class="description"><?php esc_html_e( 'Exigé à l’ouverture d’installer.php : empêche un tiers de lancer l’installation à votre place. Notez-le ! Laissez vide pour ne pas protéger (déconseillé).', 'lwb-migration' ); ?></p>
		</div>

		<p>
			<button type="button" class="button button-primary button-hero" id="lwbm-export">
				<span class="dashicons dashicons-download" aria-hidden="true"></span>
				<?php esc_html_e( 'Exporter le site', 'lwb-migration' ); ?>
			</button>
		</p>

		<?php require __DIR__ . '/progress.php'; ?>

		<div class="lwbm-result" id="lwbm-export-result" hidden>
			<h3><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'Paquet prêt !', 'lwb-migration' ); ?></h3>
			<p><?php esc_html_e( 'Téléchargez les deux fichiers puis envoyez-les (FTP, gestionnaire de fichiers) à la racine du site de destination.', 'lwb-migration' ); ?></p>
			<p class="lwbm-actions">
				<a class="button button-primary" data-lwbm-dl="archive" href="#"><?php esc_html_e( 'Télécharger l’archive', 'lwb-migration' ); ?></a>
				<a class="button button-primary" data-lwbm-dl="installer" href="#"><?php esc_html_e( 'Télécharger installer.php', 'lwb-migration' ); ?></a>
			</p>
			<p class="lwbm-password-reminder" hidden><?php esc_html_e( 'Mot de passe de l’installeur :', 'lwb-migration' ); ?> <code data-lwbm-password></code></p>
		</div>
	</section>

	<aside class="lwbm-card lwbm-card-side">
		<h2><?php esc_html_e( 'Environnement', 'lwb-migration' ); ?></h2>
		<ul class="lwbm-env">
			<?php foreach ( $lwbm_env as $lwbm_item ) : ?>
				<li class="<?php echo $lwbm_item['ok'] ? 'is-ok' : 'is-warn'; ?>">
					<span><?php echo esc_html( $lwbm_item['label'] ); ?></span>
					<strong><?php echo esc_html( $lwbm_item['value'] ); ?></strong>
				</li>
			<?php endforeach; ?>
		</ul>
		<h2><?php esc_html_e( 'En 3 étapes', 'lwb-migration' ); ?></h2>
		<ol class="lwbm-steps">
			<li><?php esc_html_e( 'Exportez et téléchargez l’archive + installer.php.', 'lwb-migration' ); ?></li>
			<li><?php esc_html_e( 'Envoyez-les à la racine du nouveau serveur (même vide).', 'lwb-migration' ); ?></li>
			<li><?php esc_html_e( 'Ouvrez votre-domaine/installer.php et suivez l’assistant.', 'lwb-migration' ); ?></li>
		</ol>
	</aside>
</div>

<section class="lwbm-card">
	<h2><?php esc_html_e( 'Paquets disponibles', 'lwb-migration' ); ?></h2>
	<table class="widefat striped lwbm-packages">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Date', 'lwb-migration' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Taille', 'lwb-migration' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Contenu', 'lwb-migration' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'lwb-migration' ); ?></th>
			</tr>
		</thead>
		<tbody id="lwbm-packages-body">
			<tr><td colspan="4"><?php esc_html_e( 'Chargement…', 'lwb-migration' ); ?></td></tr>
		</tbody>
	</table>
</section>
