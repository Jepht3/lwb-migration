<?php
/**
 * Onglet « Importer » : envoi d'une archive depuis le navigateur, sans FTP.
 *
 * @package LwbMigration
 */

defined( 'ABSPATH' ) || exit;

$lwbm_import = true;
$lwbm_chunk  = \LwbMigration\Upload_Import::chunk_size();
?>
<section class="lwbm-banner">
	<span class="lwbm-icon lwbm-icon-primary" aria-hidden="true"><span class="dashicons dashicons-upload"></span></span>
	<div>
		<h2><?php esc_html_e( 'Importer une archive, sans FTP', 'lwb-migration' ); ?></h2>
		<p><?php esc_html_e( 'Envoyez ici l’archive créée par l’onglet « Exporter » du site source : elle est transmise par petits morceaux, compatibles avec les limites de ce serveur, puis l’installation démarre comme en migration directe.', 'lwb-migration' ); ?></p>
	</div>
</section>

<div class="lwbm-grid lwbm-grid-2 lwbm-import">
	<section class="lwbm-card">
		<div class="lwbm-card-head">
			<span class="lwbm-step-badge">1</span>
			<div>
				<span class="lwbm-eyebrow"><?php esc_html_e( 'Sur ce site (destination)', 'lwb-migration' ); ?></span>
				<h2><?php esc_html_e( 'Choisir l’archive', 'lwb-migration' ); ?></h2>
			</div>
		</div>
		<div class="lwbm-callout lwbm-callout-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span><p><?php esc_html_e( 'Tout le contenu de ce site (fichiers et base de données) sera remplacé par celui de l’archive. L’ancien site est mis de côté et peut être restauré depuis l’installeur.', 'lwb-migration' ); ?></p></div>
		<div class="lwbm-field">
			<label for="lwbm-import-file"><?php esc_html_e( 'Archive (archive-….zip, .lwb ou .lwbenc)', 'lwb-migration' ); ?></label>
			<input type="file" id="lwbm-import-file" class="lwbm-file" accept=".zip,.lwb,.lwbenc,application/zip,application/octet-stream">
		</div>
		<div class="lwbm-field">
			<label for="lwbm-import-password"><?php esc_html_e( 'Mot de passe de l’installeur (archive chiffrée)', 'lwb-migration' ); ?></label>
			<input type="password" id="lwbm-import-password" class="lwbm-input lwbm-input-wide" autocomplete="off">
			<p class="lwbm-hint"><?php esc_html_e( 'Affiché à la fin de l’export sur le site source. Inutile pour une archive non chiffrée.', 'lwb-migration' ); ?></p>
		</div>
		<p class="lwbm-hint">
			<?php
			printf(
				/* translators: %s: taille d'un morceau. */
				esc_html__( 'Envoi par morceaux de %s (limites PHP de ce serveur). Envoi interrompu ? Choisissez à nouveau le même fichier : il reprendra là où il s’était arrêté.', 'lwb-migration' ),
				esc_html( \LwbMigration\Runtime::format_bytes( $lwbm_chunk ) )
			);
			?>
		</p>
	</section>

	<section class="lwbm-card">
		<div class="lwbm-card-head">
			<span class="lwbm-step-badge">2</span>
			<div>
				<span class="lwbm-eyebrow"><?php esc_html_e( 'Envoi puis installation', 'lwb-migration' ); ?></span>
				<h2><?php esc_html_e( 'Importer et installer', 'lwb-migration' ); ?></h2>
			</div>
		</div>
		<p><?php esc_html_e( 'Gardez cette page ouverte pendant l’envoi. L’installeur s’ouvre ensuite automatiquement et utilise la base de données de ce site.', 'lwb-migration' ); ?></p>
		<button type="button" class="lwbm-btn lwbm-btn-primary lwbm-btn-lg" id="lwbm-import-start">
			<span class="dashicons dashicons-upload" aria-hidden="true"></span>
			<?php esc_html_e( 'Importer et installer', 'lwb-migration' ); ?>
		</button>
		<?php require __DIR__ . '/progress.php'; ?>
		<div class="lwbm-result" id="lwbm-import-result" hidden>
			<span class="lwbm-result-icon" aria-hidden="true"><span class="dashicons dashicons-yes"></span></span>
			<div>
				<h3><?php esc_html_e( 'Archive importée !', 'lwb-migration' ); ?></h3>
				<p><?php esc_html_e( 'Ouverture de l’installeur… Si rien ne se passe, cliquez sur le bouton.', 'lwb-migration' ); ?></p>
				<a class="lwbm-btn lwbm-btn-primary" id="lwbm-import-open" href="#"><span class="dashicons dashicons-external" aria-hidden="true"></span> <?php esc_html_e( 'Ouvrir l’installeur', 'lwb-migration' ); ?></a>
			</div>
		</div>
	</section>
</div>
