<?php
/**
 * Bloc de progression partagé.
 *
 * @package LwbMigration
 *
 * @var bool $lwbm_direct Afficher les étapes de transfert.
 */

defined( 'ABSPATH' ) || exit;

$lwbm_steps = array(
	'scan'     => __( 'Fichiers', 'lwb-migration' ),
	'db'       => __( 'Base de données', 'lwb-migration' ),
	'archive'  => __( 'Archive', 'lwb-migration' ),
	'finalize' => __( 'Installeur', 'lwb-migration' ),
);
if ( ! empty( $lwbm_direct ) ) {
	$lwbm_steps['transfer'] = __( 'Transfert', 'lwb-migration' );
	$lwbm_steps['remote']   = __( 'Destination', 'lwb-migration' );
}
?>
<div class="lwbm-progress" data-lwbm-progress hidden>
	<ol class="lwbm-phases">
		<?php foreach ( $lwbm_steps as $lwbm_key => $lwbm_label ) : ?>
			<li data-lwbm-phase="<?php echo esc_attr( $lwbm_key ); ?>"><span class="lwbm-phase-dot" aria-hidden="true"></span><?php echo esc_html( $lwbm_label ); ?></li>
		<?php endforeach; ?>
	</ol>
	<div class="lwbm-progress-head">
		<div>
			<strong data-lwbm-label><?php esc_html_e( 'Préparation…', 'lwb-migration' ); ?></strong>
			<p class="lwbm-detail" data-lwbm-detail></p>
		</div>
		<span class="lwbm-percent" data-lwbm-percent>0 %</span>
	</div>
	<div class="lwbm-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
		<div class="lwbm-bar-fill" data-lwbm-fill></div>
	</div>
	<div class="lwbm-progress-foot">
		<span class="lwbm-meta"><span class="dashicons dashicons-clock" aria-hidden="true"></span> <span data-lwbm-elapsed><?php esc_html_e( 'Durée : 0 s', 'lwb-migration' ); ?></span></span>
		<span class="lwbm-meta lwbm-retry" data-lwbm-retry></span>
		<button type="button" class="lwbm-btn lwbm-btn-ghost lwbm-btn-sm" data-lwbm-cancel><?php esc_html_e( 'Annuler', 'lwb-migration' ); ?></button>
	</div>
	<details class="lwbm-log-wrap">
		<summary><?php esc_html_e( 'Journal en direct', 'lwb-migration' ); ?></summary>
		<pre class="lwbm-log" data-lwbm-log aria-live="polite"></pre>
	</details>
</div>
