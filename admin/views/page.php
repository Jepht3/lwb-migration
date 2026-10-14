<?php
/**
 * Gabarit principal de la page d'administration.
 *
 * @package LwbMigration
 *
 * @var string $tab   Onglet courant.
 * @var array  $tabs  Onglets.
 * @var string $error Erreur de stockage éventuelle.
 */

defined( 'ABSPATH' ) || exit;

$lwbm_update = \LwbMigration\Updater::status();
$lwbm_icons  = array(
	'export'   => 'dashicons-download',
	'direct'   => 'dashicons-cloud-upload',
	'settings' => 'dashicons-admin-generic',
);
?>
<div class="wrap lwbm">
	<header class="lwbm-hero">
		<div class="lwbm-hero-brand">
			<span class="lwbm-logo" aria-hidden="true"><img src="<?php echo esc_url( LWB_MIGRATION_URL . 'assets/icon.svg' ); ?>" alt="" width="56" height="56"></span>
			<div>
				<h1><?php esc_html_e( 'Lwb Migration', 'lwb-migration' ); ?> <span class="lwbm-version">v<?php echo esc_html( LWB_MIGRATION_VERSION ); ?></span></h1>
				<p><?php esc_html_e( 'Déplacez votre site WordPress vers n’importe quel serveur, en toute sécurité.', 'lwb-migration' ); ?></p>
			</div>
		</div>
		<div class="lwbm-update" id="lwbm-update" aria-live="polite">
			<?php if ( $lwbm_update['has_update'] ) : ?>
				<span class="lwbm-update-state is-new"><span class="dashicons dashicons-update" aria-hidden="true"></span>
					<?php
					/* translators: %s: numéro de version. */
					printf( esc_html__( 'Version %s disponible', 'lwb-migration' ), esc_html( $lwbm_update['latest'] ) );
					?>
				</span>
				<?php if ( $lwbm_update['update_url'] ) : ?>
					<a class="lwbm-update-btn" href="<?php echo esc_url( $lwbm_update['update_url'] ); ?>"><?php esc_html_e( 'Mettre à jour', 'lwb-migration' ); ?></a>
				<?php endif; ?>
			<?php else : ?>
				<span class="lwbm-update-state" data-lwbm-update-state><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<?php echo $lwbm_update['known'] ? esc_html__( 'À jour', 'lwb-migration' ) : esc_html__( 'Mises à jour automatiques', 'lwb-migration' ); ?>
				</span>
				<button type="button" class="lwbm-update-btn" data-lwbm-check-update><?php esc_html_e( 'Vérifier', 'lwb-migration' ); ?></button>
			<?php endif; ?>
		</div>
		<nav class="lwbm-tabs" aria-label="<?php esc_attr_e( 'Sections', 'lwb-migration' ); ?>">
			<?php foreach ( $tabs as $lwbm_slug => $lwbm_label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', $lwbm_slug, admin_url( 'admin.php?page=' . \LwbMigration\Admin::SLUG ) ) ); ?>"
					class="lwbm-tab <?php echo $tab === $lwbm_slug ? 'is-active' : ''; ?>"
					<?php echo $tab === $lwbm_slug ? 'aria-current="page"' : ''; ?>>
					<span class="dashicons <?php echo esc_attr( $lwbm_icons[ $lwbm_slug ] ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $lwbm_label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
	</header>
	<?php // Les notices des autres extensions s'affichent ici, sous l'en-tête, et non à l'intérieur. ?>
	<hr class="wp-header-end">

	<?php if ( '' !== $error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<div class="lwbm-notice" id="lwbm-notice" role="alert" hidden></div>

	<main class="lwbm-body">
		<?php require __DIR__ . '/tab-' . $tab . '.php'; ?>
	</main>
</div>
