<?php
/**
 * Liste de l'historique (migrations directes, exports). Rendue au chargement de la page,
 * puis rafraîchie en AJAX à la fin d'une opération.
 *
 * @package LwbMigration
 *
 * @var string $lwbm_history_type direct|export|'' (toutes).
 */

defined( 'ABSPATH' ) || exit;

$lwbm_entries = \LwbMigration\History::entries( isset( $lwbm_history_type ) ? $lwbm_history_type : '' );
$lwbm_fmt     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$lwbm_tones   = array(
	'ok'          => 'lwbm-pill-ok',
	'running'     => 'lwbm-pill-info',
	'error'       => 'lwbm-pill-err',
	'cancelled'   => '',
	'interrupted' => 'lwbm-pill-warn',
);

if ( empty( $lwbm_entries ) ) : ?>
	<div class="lwbm-empty"><?php esc_html_e( 'Aucune migration pour le moment. Chaque migration directe sera inscrite ici dès son démarrage, avec son résultat et les fichiers à télécharger.', 'lwb-migration' ); ?></div>
	<?php
	return;
endif;

foreach ( $lwbm_entries as $lwbm_e ) :
	$lwbm_dl   = array(
		'action'  => 'lwb_migration_download',
		'package' => $lwbm_e['package'],
		'nonce'   => wp_create_nonce( \LwbMigration\Security::NONCE ),
	);
	$lwbm_dest = '' !== $lwbm_e['final_url'] ? $lwbm_e['final_url'] : $lwbm_e['destination'];
	$lwbm_mins = (int) round( $lwbm_e['duration'] / 60 );
	?>
	<article class="lwbm-package lwbm-mig-item is-<?php echo esc_attr( $lwbm_e['status'] ); ?>" data-lwbm-history="<?php echo esc_attr( $lwbm_e['id'] ); ?>">
		<span class="lwbm-package-icon lwbm-mig-icon"><?php echo \LwbMigration\Admin::icon( 'direct' === $lwbm_e['type'] ? 'direct' : 'export', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?></span>
		<div class="lwbm-package-main">
			<div class="lwbm-package-title">
				<span class="lwbm-mig-dest">
					<?php
					if ( 'direct' === $lwbm_e['type'] ) {
						/* translators: %s: adresse du site de destination. */
						echo esc_html( sprintf( __( 'Vers %s', 'lwb-migration' ), '' !== $lwbm_dest ? preg_replace( '#^https?://#', '', $lwbm_dest ) : __( 'site inconnu', 'lwb-migration' ) ) );
					} else {
						esc_html_e( 'Export du site', 'lwb-migration' );
					}
					?>
				</span>
				<span class="lwbm-pill <?php echo esc_attr( isset( $lwbm_tones[ $lwbm_e['status'] ] ) ? $lwbm_tones[ $lwbm_e['status'] ] : '' ); ?>"><?php echo esc_html( \LwbMigration\History::status_label( $lwbm_e['status'] ) ); ?></span>
				<?php if ( 'ok' === $lwbm_e['status'] && $lwbm_e['installed'] ) : ?>
					<span class="lwbm-pill lwbm-pill-ok"><?php esc_html_e( 'site installé', 'lwb-migration' ); ?></span>
				<?php endif; ?>
				<?php if ( $lwbm_e['warnings'] > 0 ) : ?>
					<span class="lwbm-pill lwbm-pill-warn">
						<?php
						/* translators: %d: nombre d'avertissements. */
						echo esc_html( sprintf( _n( '%d avertissement', '%d avertissements', $lwbm_e['warnings'], 'lwb-migration' ), $lwbm_e['warnings'] ) );
						?>
					</span>
				<?php endif; ?>
			</div>
			<div class="lwbm-package-meta">
				<span><?php echo esc_html( wp_date( $lwbm_fmt, (int) $lwbm_e['started'] ) ); ?></span>
				<?php if ( 'direct' === $lwbm_e['type'] && '' !== \LwbMigration\History::mode_label( $lwbm_e['mode'] ) ) : ?>
					<span><?php echo esc_html( \LwbMigration\History::mode_label( $lwbm_e['mode'] ) ); ?></span>
				<?php endif; ?>
				<?php if ( $lwbm_e['size'] > 0 ) : ?>
					<span><?php echo esc_html( \LwbMigration\Runtime::format_bytes( (int) $lwbm_e['size'] ) ); ?></span>
				<?php endif; ?>
				<?php if ( 'running' !== $lwbm_e['status'] && $lwbm_e['duration'] > 0 ) : ?>
					<span>
						<?php
						echo esc_html(
							$lwbm_mins < 1
								? __( 'moins d’une minute', 'lwb-migration' )
								/* translators: %d: durée en minutes. */
								: sprintf( _n( '%d minute', '%d minutes', $lwbm_mins, 'lwb-migration' ), $lwbm_mins )
						);
						?>
					</span>
				<?php endif; ?>
			</div>
			<?php if ( '' !== $lwbm_e['message'] && in_array( $lwbm_e['status'], array( 'error', 'interrupted' ), true ) ) : ?>
				<p class="lwbm-mig-message"><?php echo esc_html( $lwbm_e['message'] ); ?></p>
			<?php elseif ( 'interrupted' === $lwbm_e['status'] ) : ?>
				<p class="lwbm-mig-message"><?php esc_html_e( 'Plus de progression : la page a sans doute été fermée pendant l’opération. Relancez-la, elle repart de zéro.', 'lwb-migration' ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $lwbm_e['log'] && ! isset( $lwbm_e['files']['log'] ) ) : ?>
				<details class="lwbm-package-password">
					<summary><?php esc_html_e( 'Voir le journal', 'lwb-migration' ); ?></summary>
					<pre class="lwbm-mig-log"><?php echo esc_html( $lwbm_e['log'] ); ?></pre>
				</details>
			<?php endif; ?>
		</div>
		<div class="lwbm-package-actions">
			<?php if ( 'ok' === $lwbm_e['status'] && '' !== $lwbm_e['installer_url'] && ! $lwbm_e['installed'] ) : ?>
				<a class="lwbm-btn lwbm-btn-primary lwbm-btn-sm" href="<?php echo esc_url( $lwbm_e['installer_url'] ); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-external" aria-hidden="true"></span> <?php esc_html_e( 'Ouvrir l’installeur', 'lwb-migration' ); ?></a>
			<?php elseif ( 'ok' === $lwbm_e['status'] && $lwbm_e['installed'] && '' !== $lwbm_dest ) : ?>
				<a class="lwbm-btn lwbm-btn-primary lwbm-btn-sm" href="<?php echo esc_url( $lwbm_dest ); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-external" aria-hidden="true"></span> <?php esc_html_e( 'Voir le site', 'lwb-migration' ); ?></a>
			<?php endif; ?>
			<?php if ( isset( $lwbm_e['files']['archive'] ) ) : ?>
				<a class="lwbm-btn lwbm-btn-soft lwbm-btn-sm" href="<?php echo esc_url( add_query_arg( array_merge( $lwbm_dl, array( 'file' => 'archive' ) ), admin_url( 'admin-ajax.php' ) ) ); ?>" title="<?php echo esc_attr( $lwbm_e['files']['archive'] ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Fichier du site', 'lwb-migration' ); ?></a>
			<?php endif; ?>
			<?php if ( isset( $lwbm_e['files']['installer'] ) ) : ?>
				<a class="lwbm-btn lwbm-btn-ghost lwbm-btn-sm" href="<?php echo esc_url( add_query_arg( array_merge( $lwbm_dl, array( 'file' => 'installer' ) ), admin_url( 'admin-ajax.php' ) ) ); ?>"><span class="dashicons dashicons-media-code" aria-hidden="true"></span> installer.php</a>
			<?php endif; ?>
			<?php if ( isset( $lwbm_e['files']['log'] ) ) : ?>
				<a class="lwbm-btn lwbm-btn-ghost lwbm-btn-sm" href="<?php echo esc_url( add_query_arg( array_merge( $lwbm_dl, array( 'file' => 'log' ) ), admin_url( 'admin-ajax.php' ) ) ); ?>"><span class="dashicons dashicons-media-text" aria-hidden="true"></span> <?php esc_html_e( 'Journal', 'lwb-migration' ); ?></a>
			<?php endif; ?>
			<?php if ( 'running' !== $lwbm_e['status'] ) : ?>
				<button type="button" class="lwbm-btn lwbm-btn-danger lwbm-btn-sm lwbm-btn-icon" data-lwbm-history-delete="<?php echo esc_attr( $lwbm_e['id'] ); ?>" title="<?php esc_attr_e( 'Retirer de l’historique', 'lwb-migration' ); ?>" aria-label="<?php esc_attr_e( 'Retirer de l’historique', 'lwb-migration' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
			<?php endif; ?>
		</div>
	</article>
<?php endforeach; ?>
