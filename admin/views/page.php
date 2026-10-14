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
?>
<div class="wrap lwbm">
	<h1 class="lwbm-title">
		<span class="dashicons dashicons-migrate" aria-hidden="true"></span>
		<?php esc_html_e( 'Lwb Migration', 'lwb-migration' ); ?>
		<span class="lwbm-version"><?php echo esc_html( LWB_MIGRATION_VERSION ); ?></span>
	</h1>

	<nav class="nav-tab-wrapper lwbm-tabs" aria-label="<?php esc_attr_e( 'Sections', 'lwb-migration' ); ?>">
		<?php foreach ( $tabs as $lwbm_slug => $lwbm_label ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', $lwbm_slug, admin_url( 'admin.php?page=' . \LwbMigration\Admin::SLUG ) ) ); ?>"
				class="nav-tab <?php echo $tab === $lwbm_slug ? 'nav-tab-active' : ''; ?>"
				<?php echo $tab === $lwbm_slug ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( $lwbm_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php if ( '' !== $error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<div class="lwbm-notice" id="lwbm-notice" role="alert" hidden></div>

	<?php require __DIR__ . '/tab-' . $tab . '.php'; ?>
</div>
