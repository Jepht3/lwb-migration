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

$lwbm_icons = array(
	'export'   => 'dashicons-download',
	'direct'   => 'dashicons-cloud-upload',
	'settings' => 'dashicons-admin-generic',
);
?>
<div class="wrap lwbm">
	<header class="lwbm-hero">
		<div class="lwbm-hero-brand">
			<span class="lwbm-logo" aria-hidden="true"><span class="dashicons dashicons-migrate"></span></span>
			<div>
				<h1><?php esc_html_e( 'Lwb Migration', 'lwb-migration' ); ?> <span class="lwbm-version">v<?php echo esc_html( LWB_MIGRATION_VERSION ); ?></span></h1>
				<p><?php esc_html_e( 'Déplacez votre site WordPress vers n’importe quel serveur, en toute sécurité.', 'lwb-migration' ); ?></p>
			</div>
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

	<?php if ( '' !== $error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<div class="lwbm-notice" id="lwbm-notice" role="alert" hidden></div>

	<main class="lwbm-body">
		<?php require __DIR__ . '/tab-' . $tab . '.php'; ?>
	</main>
</div>
