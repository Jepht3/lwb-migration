<?php
/**
 * Accueil : les actions en grandes tuiles, l'état du serveur et l'activité récente.
 *
 * @package LwbMigration
 *
 * @var array $tabs Sections : slug => title, lead.
 */

defined( 'ABSPATH' ) || exit;

$lwbm_home = \LwbMigration\Admin::home();
$lwbm_env  = \LwbMigration\Admin::environment();
?>
<nav class="lwbm-tiles" aria-label="<?php esc_attr_e( 'Actions', 'lwb-migration' ); ?>">
	<?php foreach ( $lwbm_home['tiles'] as $lwbm_slug => $lwbm_tile ) : ?>
		<a class="lwbm-tile lwbm-tile-<?php echo esc_attr( $lwbm_slug ); ?><?php echo 'export' === $lwbm_slug ? ' is-featured' : ''; ?>" href="<?php echo esc_url( \LwbMigration\Admin::url( $lwbm_slug ) ); ?>">
			<span class="lwbm-tile-top">
				<span class="lwbm-tile-icon"><?php echo \LwbMigration\Admin::icon( $lwbm_slug, 30 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?></span>
				<span class="lwbm-tile-title"><?php echo esc_html( $tabs[ $lwbm_slug ]['title'] ); ?></span>
				<span class="lwbm-tile-text"><?php echo esc_html( $lwbm_tile['text'] ); ?></span>
			</span>
			<span class="lwbm-tile-foot">
				<span class="lwbm-tile-status">
					<span class="lwbm-tile-meta<?php echo '' !== $lwbm_tile['tone'] ? ' is-' . esc_attr( $lwbm_tile['tone'] ) : ''; ?>"><?php echo esc_html( $lwbm_tile['meta'] ); ?></span>
					<?php if ( ! empty( $lwbm_tile['sub'] ) ) : ?>
						<span class="lwbm-tile-sub"><?php echo esc_html( $lwbm_tile['sub'] ); ?></span>
					<?php endif; ?>
				</span>
				<span class="lwbm-tile-go"><?php echo \LwbMigration\Admin::icon( 'arrow', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?></span>
			</span>
		</a>
	<?php endforeach; ?>

	<section class="lwbm-tile lwbm-tile-server" aria-labelledby="lwbm-server-title">
		<span class="lwbm-tile-top">
			<span class="lwbm-eyebrow" id="lwbm-server-title"><?php esc_html_e( 'Votre serveur', 'lwb-migration' ); ?></span>
			<span class="lwbm-chips">
				<?php foreach ( $lwbm_env as $lwbm_item ) : ?>
					<?php
					// Pastilles courtes : « PHP 8.3 », « MariaDB 10.11 »… (détail complet en infobulle).
					$lwbm_short = $lwbm_item['value'];
					if ( preg_match( '/^(\d+\.\d+)\.\d+-(MariaDB)/i', $lwbm_short, $lwbm_m ) ) {
						$lwbm_short = $lwbm_m[2] . ' ' . $lwbm_m[1];
					} elseif ( preg_match( '/^(\d+\.\d+)/', $lwbm_short, $lwbm_m ) && false !== stripos( $lwbm_item['label'], 'PHP' ) && false === stripos( $lwbm_item['label'], 'mém' ) ) {
						$lwbm_short = 'PHP ' . $lwbm_m[1];
					} elseif ( preg_match( '/^(\d+\.\d+)/', $lwbm_short, $lwbm_m ) && false !== stripos( $lwbm_item['label'], 'données' ) ) {
						$lwbm_short = 'MySQL ' . $lwbm_m[1];
					} elseif ( preg_match( '/^([\d.,]+\s*s)\b/u', $lwbm_short, $lwbm_m ) ) {
						/* translators: %s: durée d'une étape. */
						$lwbm_short = sprintf( __( 'Étapes de %s', 'lwb-migration' ), $lwbm_m[1] );
					} elseif ( false !== stripos( $lwbm_item['label'], 'mémoire' ) ) {
						/* translators: %s: mémoire PHP. */
						$lwbm_short = sprintf( __( 'Mémoire %s', 'lwb-migration' ), $lwbm_short );
					}
					?>
					<span class="lwbm-chip<?php echo $lwbm_item['ok'] ? '' : ' is-warn'; ?>" title="<?php echo esc_attr( $lwbm_item['label'] . ' : ' . $lwbm_item['value'] ); ?>"><?php echo esc_html( $lwbm_short ); ?></span>
				<?php endforeach; ?>
			</span>
		</span>
		<span class="lwbm-tile-text"><?php esc_html_e( 'Traitement par petites étapes avec reprise automatique : aucune configuration nécessaire, même sur un petit hébergement.', 'lwb-migration' ); ?></span>
	</section>
</nav>

<section class="lwbm-card lwbm-activity">
	<h2 class="lwbm-card-title"><?php esc_html_e( 'Activité récente', 'lwb-migration' ); ?></h2>
	<?php if ( empty( $lwbm_home['activity'] ) ) : ?>
		<p class="lwbm-empty"><?php esc_html_e( 'Rien pour le moment. Exportez le site ou activez les sauvegardes automatiques pour commencer.', 'lwb-migration' ); ?></p>
	<?php else : ?>
		<ul class="lwbm-activity-list">
			<?php foreach ( $lwbm_home['activity'] as $lwbm_a ) : ?>
				<li>
					<span class="lwbm-activity-icon<?php echo '' !== $lwbm_a['tone'] ? ' is-' . esc_attr( $lwbm_a['tone'] ) : ''; ?>"><?php echo \LwbMigration\Admin::icon( $lwbm_a['icon'], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?></span>
					<span class="lwbm-activity-main">
						<a href="<?php echo esc_url( $lwbm_a['url'] ); ?>"><?php echo esc_html( $lwbm_a['title'] ); ?></a>
						<span><?php echo esc_html( $lwbm_a['meta'] ); ?></span>
					</span>
					<span class="lwbm-activity-date"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $lwbm_a['time'] ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
