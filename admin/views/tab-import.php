<?php
/**
 * Section « Importer un site » : un seul fichier, envoyé depuis le navigateur (sans FTP),
 * puis restauré sur ce site.
 *
 * @package LwbMigration
 */

defined( 'ABSPATH' ) || exit;

$lwbm_import = true;
$lwbm_chunk  = \LwbMigration\Upload_Import::chunk_size();
?>
<div class="lwbm-split lwbm-import">
	<section class="lwbm-card lwbm-split-main">
		<label class="lwbm-dropzone" id="lwbm-dropzone" for="lwbm-import-file">
			<input type="file" id="lwbm-import-file" class="lwbm-file" accept=".zip,.lwb,.lwbenc,application/zip,application/octet-stream">
			<span class="lwbm-dropzone-icon"><?php echo \LwbMigration\Admin::icon( 'upload', 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?></span>
			<span class="lwbm-dropzone-title"><?php esc_html_e( 'Glissez le fichier de votre site ici', 'lwb-migration' ); ?></span>
			<span class="lwbm-dropzone-text"><?php esc_html_e( 'ou cliquez pour le choisir · fichier exporté par Lwb Migration (.zip, .lwb ou .lwbenc)', 'lwb-migration' ); ?></span>
			<span class="lwbm-chip">
				<?php
				/* translators: %s: taille d'un morceau. */
				printf( esc_html__( 'Aucune limite de taille : envoi par morceaux de %s, avec reprise', 'lwb-migration' ), esc_html( \LwbMigration\Runtime::format_bytes( $lwbm_chunk ) ) );
				?>
			</span>
		</label>

		<div class="lwbm-picked" id="lwbm-picked" hidden>
			<span class="lwbm-picked-icon"><?php echo \LwbMigration\Admin::icon( 'file', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?></span>
			<span class="lwbm-picked-main">
				<strong data-lwbm-picked-name></strong>
				<span data-lwbm-picked-size></span>
			</span>
			<button type="button" class="lwbm-btn lwbm-btn-ghost" id="lwbm-import-change"><?php esc_html_e( 'Changer', 'lwb-migration' ); ?></button>
		</div>

		<div class="lwbm-callout lwbm-callout-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span><p><?php esc_html_e( 'Ce site sera entièrement remplacé (fichiers et base de données). L’état actuel est mis de côté juste avant : vous pourrez revenir en arrière depuis l’installeur.', 'lwb-migration' ); ?></p></div>

		<div class="lwbm-field" id="lwbm-import-password-field" hidden>
			<label for="lwbm-import-password"><?php esc_html_e( 'Mot de passe du fichier', 'lwb-migration' ); ?></label>
			<input type="password" id="lwbm-import-password" class="lwbm-input lwbm-input-wide" autocomplete="off" placeholder="<?php esc_attr_e( 'Affiché à la fin de l’export', 'lwb-migration' ); ?>">
		</div>

		<button type="button" class="lwbm-btn lwbm-btn-primary lwbm-btn-xl" id="lwbm-import-start" disabled>
			<?php echo \LwbMigration\Admin::icon( 'restore', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?>
			<?php esc_html_e( 'Restaurer ce site', 'lwb-migration' ); ?>
		</button>
		<p class="lwbm-hint lwbm-center"><?php esc_html_e( 'Gardez cette page ouverte pendant l’envoi. L’installation démarre ensuite automatiquement.', 'lwb-migration' ); ?></p>

		<?php require __DIR__ . '/progress.php'; ?>
		<div class="lwbm-result" id="lwbm-import-result" hidden>
			<span class="lwbm-result-icon" aria-hidden="true"><span class="dashicons dashicons-yes"></span></span>
			<div>
				<h3><?php esc_html_e( 'Fichier reçu !', 'lwb-migration' ); ?></h3>
				<p><?php esc_html_e( 'Ouverture de l’installeur… Si rien ne se passe, cliquez sur le bouton.', 'lwb-migration' ); ?></p>
				<a class="lwbm-btn lwbm-btn-primary" id="lwbm-import-open" href="#"><span class="dashicons dashicons-external" aria-hidden="true"></span> <?php esc_html_e( 'Ouvrir l’installeur', 'lwb-migration' ); ?></a>
			</div>
		</div>
	</section>

	<aside class="lwbm-side">
		<section class="lwbm-card">
			<h2 class="lwbm-card-title"><?php esc_html_e( 'Comment ça marche', 'lwb-migration' ); ?></h2>
			<ol class="lwbm-steps">
				<li><span>1</span><?php esc_html_e( 'Sur l’ancien site : Exporter → un seul fichier.', 'lwb-migration' ); ?></li>
				<li><span>2</span><?php esc_html_e( 'Ici : WordPress et Lwb Migration installés.', 'lwb-migration' ); ?></li>
				<li><span>3</span><?php esc_html_e( 'Déposez le fichier → Restaurer.', 'lwb-migration' ); ?></li>
			</ol>
		</section>
		<section class="lwbm-card lwbm-card-soft">
			<h2 class="lwbm-card-title"><?php esc_html_e( 'Serveur vide, sans WordPress ?', 'lwb-migration' ); ?></h2>
			<p><?php esc_html_e( 'Sur l’ancien site, téléchargez aussi installer.php (option à la fin de l’export) et déposez les deux fichiers par FTP.', 'lwb-migration' ); ?></p>
		</section>
	</aside>
</div>
