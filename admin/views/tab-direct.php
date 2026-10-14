<?php
/**
 * Onglet « Migration directe ».
 *
 * @package LwbMigration
 */

defined( 'ABSPATH' ) || exit;

$lwbm_key  = \LwbMigration\Direct_Transfer::active_key();
$lwbm_http = ! is_ssl() && ! \LwbMigration\Direct_Transfer::allow_http();
?>
<p class="lwbm-intro">
	<?php esc_html_e( 'Transférez le site directement d’un serveur à l’autre, sans rien télécharger sur votre ordinateur. Lwb Migration doit être installée sur les deux sites.', 'lwb-migration' ); ?>
</p>
<div class="lwbm-grid lwbm-grid-2">
	<section class="lwbm-card">
		<h2><span class="lwbm-badge">1</span> <?php esc_html_e( 'Sur le site de DESTINATION', 'lwb-migration' ); ?></h2>
		<p><?php esc_html_e( 'Générez une clé de migration, puis copiez-la. Elle est valable 24 heures et ne sert qu’une fois.', 'lwb-migration' ); ?></p>
		<?php if ( $lwbm_http ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Ce site n’utilise pas HTTPS : la clé sera refusée par le site source. Activez un certificat SSL pour utiliser la migration directe.', 'lwb-migration' ); ?></p></div>
		<?php endif; ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'Attention : tout le contenu de ce site (fichiers et base de données) sera remplacé par celui du site source.', 'lwb-migration' ); ?></p></div>
		<p>
			<button type="button" class="button button-primary" id="lwbm-key-generate"><?php esc_html_e( 'Générer une clé de migration', 'lwb-migration' ); ?></button>
			<button type="button" class="button" id="lwbm-key-revoke" <?php echo $lwbm_key ? '' : 'hidden'; ?>><?php esc_html_e( 'Révoquer la clé', 'lwb-migration' ); ?></button>
		</p>
		<div id="lwbm-key-box" hidden>
			<label for="lwbm-key-output" class="screen-reader-text"><?php esc_html_e( 'Clé de migration', 'lwb-migration' ); ?></label>
			<textarea id="lwbm-key-output" class="large-text code" rows="5" readonly></textarea>
			<p class="lwbm-inline">
				<button type="button" class="button" id="lwbm-key-copy"><?php esc_html_e( 'Copier la clé', 'lwb-migration' ); ?></button>
				<span class="description" id="lwbm-key-expires"></span>
			</p>
		</div>
		<?php if ( $lwbm_key ) : ?>
			<p class="description" id="lwbm-key-active">
				<?php
				/* translators: %s: date d'expiration. */
				printf( esc_html__( 'Une clé est active jusqu’au %s.', 'lwb-migration' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $lwbm_key['expires'] ) ) );
				?>
			</p>
		<?php endif; ?>
	</section>

	<section class="lwbm-card">
		<h2><span class="lwbm-badge">2</span> <?php esc_html_e( 'Sur le site SOURCE (celui-ci, à migrer)', 'lwb-migration' ); ?></h2>
		<p><?php esc_html_e( 'Collez la clé générée sur le site de destination puis lancez la migration.', 'lwb-migration' ); ?></p>
		<label for="lwbm-direct-key"><?php esc_html_e( 'Clé de migration', 'lwb-migration' ); ?></label>
		<textarea id="lwbm-direct-key" class="large-text code" rows="4" spellcheck="false" placeholder="LWB1.…"></textarea>
		<p>
			<button type="button" class="button button-primary button-hero" id="lwbm-direct-start">
				<span class="dashicons dashicons-cloud-upload" aria-hidden="true"></span>
				<?php esc_html_e( 'Lancer la migration directe', 'lwb-migration' ); ?>
			</button>
		</p>
		<?php require __DIR__ . '/progress.php'; ?>
		<div class="lwbm-result" id="lwbm-direct-result" hidden>
			<h3><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'Transfert terminé !', 'lwb-migration' ); ?></h3>
			<p><?php esc_html_e( 'Dernière étape : ouvrez l’installeur sur le site de destination. Il se déroule automatiquement (import de la base, remplacement des URL, finalisation).', 'lwb-migration' ); ?></p>
			<p><a class="button button-primary button-hero" id="lwbm-direct-open" href="#" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Ouvrir l’installeur sur la destination', 'lwb-migration' ); ?></a></p>
		</div>
	</section>
</div>
