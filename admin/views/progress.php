<?php
/**
 * Bloc de progression partagé.
 *
 * @package LwbMigration
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="lwbm-progress" data-lwbm-progress hidden>
	<div class="lwbm-progress-head">
		<strong data-lwbm-label><?php esc_html_e( 'Préparation…', 'lwb-migration' ); ?></strong>
		<span data-lwbm-percent>0 %</span>
	</div>
	<div class="lwbm-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
		<div class="lwbm-bar-fill" data-lwbm-fill></div>
	</div>
	<p class="lwbm-detail" data-lwbm-detail></p>
	<p class="lwbm-meta"><span data-lwbm-elapsed></span> <span data-lwbm-retry></span></p>
	<pre class="lwbm-log" data-lwbm-log aria-live="polite"></pre>
	<p><button type="button" class="button" data-lwbm-cancel><?php esc_html_e( 'Annuler', 'lwb-migration' ); ?></button></p>
</div>
