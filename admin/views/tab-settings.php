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
<form id="lwbm-settings" class="lwbm-card" novalidate>
	<h2><?php esc_html_e( 'Archive', 'lwb-migration' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="lwbm-format"><?php esc_html_e( 'Format', 'lwb-migration' ); ?></label></th>
			<td>
				<select id="lwbm-format" name="format">
					<option value="auto" <?php selected( $lwbm_s['format'], 'auto' ); ?>><?php esc_html_e( 'Automatique (recommandé)', 'lwb-migration' ); ?></option>
					<option value="zip" <?php selected( $lwbm_s['format'], 'zip' ); ?>><?php esc_html_e( 'ZIP standard', 'lwb-migration' ); ?></option>
					<option value="lwb" <?php selected( $lwbm_s['format'], 'lwb' ); ?>><?php esc_html_e( 'LWB (repli en PHP pur, sans compression)', 'lwb-migration' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Automatique : ZIP si l’extension zip ou zlib est disponible, sinon LWB. Les deux formats sont lus par installer.php sans aucune extension particulière.', 'lwb-migration' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Compression', 'lwb-migration' ); ?></th>
			<td><label><input type="checkbox" name="compress" value="1" <?php checked( $lwbm_s['compress'] ); ?>> <?php esc_html_e( 'Compresser les fichiers texte (les images, vidéos et archives sont stockées telles quelles)', 'lwb-migration' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><label for="lwbm-keep"><?php esc_html_e( 'Paquets conservés', 'lwb-migration' ); ?></label></th>
			<td><input type="number" id="lwbm-keep" name="keep_packages" min="1" max="50" value="<?php echo esc_attr( $lwbm_s['keep_packages'] ); ?>" class="small-text"> <span class="description"><?php esc_html_e( 'Les plus anciens sont supprimés automatiquement.', 'lwb-migration' ); ?></span></td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Fichiers', 'lwb-migration' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Exclusions par défaut', 'lwb-migration' ); ?></th>
			<td>
				<label><input type="checkbox" name="default_exclude" value="1" <?php checked( $lwbm_s['default_exclude'] ); ?>> <?php esc_html_e( 'Exclure les caches, les sauvegardes d’autres extensions, node_modules, .git et les journaux d’erreurs', 'lwb-migration' ); ?></label>
				<p class="description"><?php esc_html_e( 'Le dossier de Lwb Migration et ses paquets sont toujours exclus.', 'lwb-migration' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="lwbm-dirs"><?php esc_html_e( 'Dossiers exclus', 'lwb-migration' ); ?></label></th>
			<td>
				<textarea id="lwbm-dirs" name="exclude_dirs" rows="5" class="large-text code" placeholder="wp-content/uploads/videos"><?php echo esc_textarea( implode( "\n", $lwbm_s['exclude_dirs'] ) ); ?></textarea>
				<p class="description">
					<?php
					/* translators: %s: chemin de la racine du site. */
					printf( esc_html__( 'Un chemin par ligne, relatif à la racine du site (%s).', 'lwb-migration' ), '<code>' . esc_html( $lwbm_root ) . '</code>' );
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="lwbm-exts"><?php esc_html_e( 'Extensions exclues', 'lwb-migration' ); ?></label></th>
			<td><input type="text" id="lwbm-exts" name="exclude_exts" class="regular-text code" value="<?php echo esc_attr( implode( ', ', $lwbm_s['exclude_exts'] ) ); ?>" placeholder="log, tmp, bak"></td>
		</tr>
		<tr>
			<th scope="row"><label for="lwbm-maxsize"><?php esc_html_e( 'Taille maximale d’un fichier', 'lwb-migration' ); ?></label></th>
			<td><input type="number" id="lwbm-maxsize" name="max_file_size" min="0" value="<?php echo esc_attr( $lwbm_s['max_file_size'] ); ?>" class="small-text"> Mo <span class="description"><?php esc_html_e( '(0 = aucune limite)', 'lwb-migration' ); ?></span></td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Base de données', 'lwb-migration' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Tables exportées', 'lwb-migration' ); ?></th>
			<td>
				<label><input type="checkbox" name="all_tables" value="1" <?php checked( $lwbm_s['all_tables'] ); ?>> <?php esc_html_e( 'Inclure aussi les tables sans le préfixe WordPress', 'lwb-migration' ); ?></label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Tables exclues', 'lwb-migration' ); ?></th>
			<td>
				<div class="lwbm-tables">
					<?php foreach ( $lwbm_tables as $lwbm_t ) : ?>
						<label class="<?php echo $lwbm_t['own'] ? '' : 'is-foreign'; ?>">
							<input type="checkbox" name="exclude_tables[]" value="<?php echo esc_attr( $lwbm_t['name'] ); ?>" <?php checked( in_array( $lwbm_t['name'], $lwbm_s['exclude_tables'], true ) ); ?>>
							<code><?php echo esc_html( $lwbm_t['name'] ); ?></code> <span class="description"><?php echo esc_html( $lwbm_t['size'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="description"><?php esc_html_e( 'Cochez les tables à ne pas migrer (journaux, statistiques volumineuses…). Les structures exclues ne seront pas recréées.', 'lwb-migration' ); ?></p>
			</td>
		</tr>
	</table>
	<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Enregistrer les réglages', 'lwb-migration' ); ?></button></p>
</form>
