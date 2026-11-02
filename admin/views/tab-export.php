<?php
/**
 * Section « Exporter le site » : un seul fichier à importer sur le nouveau site.
 *
 * @package LwbMigration
 */

defined( 'ABSPATH' ) || exit;

$lwbm_direct  = false;
$lwbm_s       = \LwbMigration\Settings::get();
$lwbm_crypto  = \LwbMigration\Archive_Crypto::available();
$lwbm_db_size = 0;
foreach ( \LwbMigration\Admin::tables() as $lwbm_t ) {
	if ( $lwbm_t['own'] || $lwbm_s['all_tables'] ) {
		$lwbm_db_size += $lwbm_t['bytes'];
	}
}
?>
<div class="lwbm-split">
	<section class="lwbm-card lwbm-split-main">
		<fieldset class="lwbm-choices">
			<legend class="lwbm-step-title"><?php esc_html_e( '1 · Que voulez-vous exporter ?', 'lwb-migration' ); ?></legend>
			<div class="lwbm-choice-grid">
				<label class="lwbm-choice">
					<input type="radio" name="lwbm-content" value="full" checked>
					<span class="lwbm-choice-body">
						<span class="lwbm-choice-title"><?php esc_html_e( 'Site complet', 'lwb-migration' ); ?></span>
						<span class="lwbm-choice-text"><?php esc_html_e( 'Fichiers, extensions, thèmes, médias et base de données.', 'lwb-migration' ); ?></span>
					</span>
				</label>
				<label class="lwbm-choice">
					<input type="radio" name="lwbm-content" value="database">
					<span class="lwbm-choice-body">
						<span class="lwbm-choice-title"><?php esc_html_e( 'Base de données seule', 'lwb-migration' ); ?></span>
						<span class="lwbm-choice-text"><?php esc_html_e( 'Articles, pages, réglages et comptes. Rapide et léger.', 'lwb-migration' ); ?></span>
						<?php if ( $lwbm_db_size > 0 ) : ?>
							<span class="lwbm-choice-meta">
								<?php
								/* translators: %s: taille de la base. */
								printf( esc_html__( '≈ %s', 'lwb-migration' ), esc_html( \LwbMigration\Runtime::format_bytes( $lwbm_db_size ) ) );
								?>
							</span>
						<?php endif; ?>
					</span>
				</label>
			</div>
		</fieldset>

		<fieldset class="lwbm-choices">
			<legend class="lwbm-step-title"><?php esc_html_e( '2 · Protection', 'lwb-migration' ); ?></legend>
			<div class="lwbm-choice-grid">
				<label class="lwbm-choice">
					<input type="radio" name="lwbm-protection" value="auto" checked>
					<span class="lwbm-choice-body">
						<span class="lwbm-choice-title"><?php esc_html_e( 'Mot de passe généré', 'lwb-migration' ); ?></span>
						<span class="lwbm-choice-text"><?php esc_html_e( 'Recommandé. Il s’affiche à la fin de l’export : notez-le.', 'lwb-migration' ); ?></span>
					</span>
				</label>
				<label class="lwbm-choice">
					<input type="radio" name="lwbm-protection" value="custom">
					<span class="lwbm-choice-body">
						<span class="lwbm-choice-title"><?php esc_html_e( 'Choisir mon mot de passe', 'lwb-migration' ); ?></span>
						<span class="lwbm-choice-text"><?php esc_html_e( 'Pour un mot de passe que vous connaissez déjà.', 'lwb-migration' ); ?></span>
					</span>
				</label>
			</div>
			<div class="lwbm-field lwbm-custom-password" id="lwbm-custom-password" hidden>
				<label for="lwbm-password"><?php esc_html_e( 'Votre mot de passe (8 caractères minimum)', 'lwb-migration' ); ?></label>
				<div class="lwbm-input-group">
					<span class="dashicons dashicons-lock" aria-hidden="true"></span>
					<input type="text" id="lwbm-password" autocomplete="new-password" spellcheck="false" minlength="8" placeholder="<?php esc_attr_e( 'Choisissez un mot de passe', 'lwb-migration' ); ?>">
					<button type="button" class="lwbm-btn lwbm-btn-soft" id="lwbm-generate-password"><span class="dashicons dashicons-randomize" aria-hidden="true"></span> <?php esc_html_e( 'Générer', 'lwb-migration' ); ?></button>
				</div>
			</div>
			<?php if ( $lwbm_crypto ) : ?>
				<label class="lwbm-toggle-card">
					<span class="lwbm-toggle-text">
						<span class="lwbm-choice-title"><?php esc_html_e( 'Chiffrer le fichier', 'lwb-migration' ); ?></span>
						<span class="lwbm-choice-text"><?php esc_html_e( 'Illisible sans le mot de passe, sur le serveur comme pendant le transfert.', 'lwb-migration' ); ?></span>
					</span>
					<span class="lwbm-switch"><input type="checkbox" id="lwbm-export-encrypt" value="1" <?php checked( $lwbm_s['encrypt'] ); ?>><span aria-hidden="true"></span></span>
				</label>
			<?php endif; ?>
		</fieldset>

		<button type="button" class="lwbm-btn lwbm-btn-primary lwbm-btn-xl" id="lwbm-export">
			<?php echo \LwbMigration\Admin::icon( 'export', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?>
			<?php esc_html_e( 'Exporter le site', 'lwb-migration' ); ?>
		</button>

		<?php require __DIR__ . '/progress.php'; ?>

		<div class="lwbm-result" id="lwbm-export-result" hidden>
			<span class="lwbm-result-icon" aria-hidden="true"><span class="dashicons dashicons-yes"></span></span>
			<div>
				<h3><?php esc_html_e( 'Votre site est prêt !', 'lwb-migration' ); ?></h3>
				<p><?php esc_html_e( 'Téléchargez ce fichier, puis sur le nouveau site : Lwb Migration › Importer › Restaurer.', 'lwb-migration' ); ?></p>
				<a class="lwbm-btn lwbm-btn-primary lwbm-btn-lg" data-lwbm-dl="archive" href="#"><?php echo \LwbMigration\Admin::icon( 'export', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?> <?php esc_html_e( 'Télécharger le fichier du site', 'lwb-migration' ); ?></a>
				<p class="lwbm-hint lwbm-dl-name" data-lwbm-dl-name></p>
				<p class="lwbm-password-reminder" hidden><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e( 'Mot de passe :', 'lwb-migration' ); ?> <code data-lwbm-password></code></p>
				<details class="lwbm-more">
					<summary><?php esc_html_e( 'Serveur vide, sans WordPress ?', 'lwb-migration' ); ?></summary>
					<p><?php esc_html_e( 'Téléchargez aussi installer.php, déposez les deux fichiers à la racine du serveur (FTP), puis ouvrez votre-domaine/installer.php.', 'lwb-migration' ); ?></p>
					<a class="lwbm-btn lwbm-btn-ghost" data-lwbm-dl="installer" href="#"><span class="dashicons dashicons-media-code" aria-hidden="true"></span> <?php esc_html_e( 'Télécharger installer.php', 'lwb-migration' ); ?></a>
				</details>
			</div>
		</div>
	</section>

	<aside class="lwbm-side">
		<section class="lwbm-card">
			<h2 class="lwbm-card-title"><?php esc_html_e( 'Ensuite', 'lwb-migration' ); ?></h2>
			<ol class="lwbm-steps">
				<li><span>1</span><?php esc_html_e( 'Téléchargez le fichier du site.', 'lwb-migration' ); ?></li>
				<li><span>2</span><?php esc_html_e( 'Sur le nouveau site : installez WordPress et Lwb Migration.', 'lwb-migration' ); ?></li>
				<li><span>3</span><?php esc_html_e( 'Importer → déposez le fichier → Restaurer.', 'lwb-migration' ); ?></li>
			</ol>
		</section>
		<section class="lwbm-card lwbm-card-soft">
			<h2 class="lwbm-card-title"><?php esc_html_e( 'Sans rien télécharger ?', 'lwb-migration' ); ?></h2>
			<p><?php esc_html_e( 'La migration directe envoie le site de serveur à serveur.', 'lwb-migration' ); ?></p>
			<a class="lwbm-link" href="<?php echo esc_url( \LwbMigration\Admin::url( 'direct' ) ); ?>"><?php esc_html_e( 'Choisir la migration directe', 'lwb-migration' ); ?></a>
		</section>
	</aside>
</div>

<section class="lwbm-card">
	<h2 class="lwbm-card-title"><?php esc_html_e( 'Exports disponibles', 'lwb-migration' ); ?></h2>
	<div class="lwbm-packages" id="lwbm-packages-body">
		<div class="lwbm-empty"><?php esc_html_e( 'Chargement…', 'lwb-migration' ); ?></div>
	</div>
</section>
