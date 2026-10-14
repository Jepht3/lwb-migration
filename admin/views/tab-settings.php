<?php
/**
 * Onglet « Paramètres ».
 *
 * @package LwbMigration
 */

defined( 'ABSPATH' ) || exit;

$lwbm_s      = \LwbMigration\Settings::get();
$lwbm_tables = \LwbMigration\Admin::tables();
$lwbm_root   = untrailingslashit( wp_normalize_path( ABSPATH ) );
?>
<form id="lwbm-settings" class="lwbm-settings" novalidate>
	<section class="lwbm-card">
		<h2 class="lwbm-card-title"><span class="dashicons dashicons-media-archive" aria-hidden="true"></span> <?php esc_html_e( 'Archive', 'lwb-migration' ); ?></h2>
		<div class="lwbm-row">
			<div class="lwbm-row-text">
				<label for="lwbm-format"><?php esc_html_e( 'Format', 'lwb-migration' ); ?></label>
				<p class="lwbm-hint"><?php esc_html_e( 'Automatique : ZIP si l’extension zip ou zlib est disponible, sinon LWB. installer.php lit les deux sans extension particulière.', 'lwb-migration' ); ?></p>
			</div>
			<select id="lwbm-format" name="format" class="lwbm-select">
				<option value="auto" <?php selected( $lwbm_s['format'], 'auto' ); ?>><?php esc_html_e( 'Automatique (recommandé)', 'lwb-migration' ); ?></option>
				<option value="zip" <?php selected( $lwbm_s['format'], 'zip' ); ?>><?php esc_html_e( 'ZIP standard', 'lwb-migration' ); ?></option>
				<option value="lwb" <?php selected( $lwbm_s['format'], 'lwb' ); ?>><?php esc_html_e( 'LWB (PHP pur, sans compression)', 'lwb-migration' ); ?></option>
			</select>
		</div>
		<div class="lwbm-row">
			<div class="lwbm-row-text">
				<label for="lwbm-compress"><?php esc_html_e( 'Compression', 'lwb-migration' ); ?></label>
				<p class="lwbm-hint"><?php esc_html_e( 'Compresse les fichiers texte. Images, vidéos et archives sont stockées telles quelles.', 'lwb-migration' ); ?></p>
			</div>
			<label class="lwbm-switch"><input type="checkbox" id="lwbm-compress" name="compress" value="1" <?php checked( $lwbm_s['compress'] ); ?>><span aria-hidden="true"></span></label>
		</div>
		<div class="lwbm-row">
			<div class="lwbm-row-text">
				<label for="lwbm-encrypt"><?php esc_html_e( 'Chiffrer l’archive', 'lwb-migration' ); ?></label>
				<p class="lwbm-hint"><?php esc_html_e( 'AES-256 avec le mot de passe de l’installeur : l’archive (fichiers, wp-config.php, base de données) est illisible sans lui, sur le serveur comme pendant le transfert FTP. La migration directe passe déjà par HTTPS.', 'lwb-migration' ); ?></p>
			</div>
			<label class="lwbm-switch"><input type="checkbox" id="lwbm-encrypt" name="encrypt" value="1" <?php checked( $lwbm_s['encrypt'] ); ?>><span aria-hidden="true"></span></label>
		</div>
		<div class="lwbm-row">
			<div class="lwbm-row-text">
				<label for="lwbm-keep"><?php esc_html_e( 'Paquets conservés', 'lwb-migration' ); ?></label>
				<p class="lwbm-hint"><?php esc_html_e( 'Les plus anciens sont supprimés automatiquement.', 'lwb-migration' ); ?></p>
			</div>
			<input type="number" id="lwbm-keep" name="keep_packages" min="1" max="50" value="<?php echo esc_attr( $lwbm_s['keep_packages'] ); ?>" class="lwbm-input lwbm-input-sm">
		</div>
	</section>

	<section class="lwbm-card">
		<h2 class="lwbm-card-title"><span class="dashicons dashicons-category" aria-hidden="true"></span> <?php esc_html_e( 'Fichiers', 'lwb-migration' ); ?></h2>
		<div class="lwbm-row">
			<div class="lwbm-row-text">
				<label for="lwbm-default-exclude"><?php esc_html_e( 'Exclusions intelligentes', 'lwb-migration' ); ?></label>
				<p class="lwbm-hint"><?php esc_html_e( 'Caches, sauvegardes d’autres extensions, node_modules, .git et journaux d’erreurs. Lwb Migration et ses paquets sont toujours exclus.', 'lwb-migration' ); ?></p>
			</div>
			<label class="lwbm-switch"><input type="checkbox" id="lwbm-default-exclude" name="default_exclude" value="1" <?php checked( $lwbm_s['default_exclude'] ); ?>><span aria-hidden="true"></span></label>
		</div>
		<div class="lwbm-row lwbm-row-stack">
			<div class="lwbm-row-text">
				<label for="lwbm-dirs"><?php esc_html_e( 'Dossiers exclus', 'lwb-migration' ); ?></label>
				<p class="lwbm-hint">
					<?php
					/* translators: %s: chemin de la racine du site. */
					printf( esc_html__( 'Un chemin par ligne, relatif à la racine du site (%s).', 'lwb-migration' ), '<code>' . esc_html( $lwbm_root ) . '</code>' );
					?>
				</p>
			</div>
			<textarea id="lwbm-dirs" name="exclude_dirs" rows="4" class="lwbm-code" placeholder="wp-content/uploads/videos"><?php echo esc_textarea( implode( "\n", $lwbm_s['exclude_dirs'] ) ); ?></textarea>
		</div>
		<div class="lwbm-row">
			<div class="lwbm-row-text">
				<label for="lwbm-exts"><?php esc_html_e( 'Extensions exclues', 'lwb-migration' ); ?></label>
				<p class="lwbm-hint"><?php esc_html_e( 'Séparées par des virgules.', 'lwb-migration' ); ?></p>
			</div>
			<input type="text" id="lwbm-exts" name="exclude_exts" class="lwbm-input" value="<?php echo esc_attr( implode( ', ', $lwbm_s['exclude_exts'] ) ); ?>" placeholder="log, tmp, bak">
		</div>
		<div class="lwbm-row">
			<div class="lwbm-row-text">
				<label for="lwbm-maxsize"><?php esc_html_e( 'Taille maximale d’un fichier', 'lwb-migration' ); ?></label>
				<p class="lwbm-hint"><?php esc_html_e( 'En Mo. 0 = aucune limite.', 'lwb-migration' ); ?></p>
			</div>
			<input type="number" id="lwbm-maxsize" name="max_file_size" min="0" value="<?php echo esc_attr( $lwbm_s['max_file_size'] ); ?>" class="lwbm-input lwbm-input-sm">
		</div>
	</section>

	<section class="lwbm-card">
		<h2 class="lwbm-card-title"><span class="dashicons dashicons-database" aria-hidden="true"></span> <?php esc_html_e( 'Base de données', 'lwb-migration' ); ?></h2>
		<div class="lwbm-row">
			<div class="lwbm-row-text">
				<label for="lwbm-all-tables"><?php esc_html_e( 'Toutes les tables', 'lwb-migration' ); ?></label>
				<p class="lwbm-hint"><?php esc_html_e( 'Inclure aussi les tables sans le préfixe WordPress.', 'lwb-migration' ); ?></p>
			</div>
			<label class="lwbm-switch"><input type="checkbox" id="lwbm-all-tables" name="all_tables" value="1" <?php checked( $lwbm_s['all_tables'] ); ?>><span aria-hidden="true"></span></label>
		</div>
		<div class="lwbm-row lwbm-row-stack">
			<div class="lwbm-row-text">
				<span class="lwbm-label"><?php esc_html_e( 'Tables exclues', 'lwb-migration' ); ?></span>
				<p class="lwbm-hint"><?php esc_html_e( 'Cochez les tables à ne pas migrer (journaux, statistiques volumineuses…).', 'lwb-migration' ); ?></p>
			</div>
			<div class="lwbm-tables">
				<?php foreach ( $lwbm_tables as $lwbm_t ) : ?>
					<label class="lwbm-table-chip <?php echo $lwbm_t['own'] ? '' : 'is-foreign'; ?>">
						<input type="checkbox" name="exclude_tables[]" value="<?php echo esc_attr( $lwbm_t['name'] ); ?>" <?php checked( in_array( $lwbm_t['name'], $lwbm_s['exclude_tables'], true ) ); ?>>
						<span class="lwbm-table-name"><?php echo esc_html( $lwbm_t['name'] ); ?></span>
						<span class="lwbm-table-size"><?php echo esc_html( $lwbm_t['size'] ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<div class="lwbm-savebar">
		<span class="lwbm-hint"><?php esc_html_e( 'Les réglages s’appliquent aux prochains exports.', 'lwb-migration' ); ?></span>
		<button type="submit" class="lwbm-btn lwbm-btn-primary"><span class="dashicons dashicons-saved" aria-hidden="true"></span> <?php esc_html_e( 'Enregistrer les réglages', 'lwb-migration' ); ?></button>
	</div>
</form>
