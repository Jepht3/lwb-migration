<?php
/**
 * Gabarit principal de la page d'administration.
 *
 * L'accueil présente les actions sous forme de grandes tuiles ; chaque section s'ouvre
 * sur son propre écran, avec un retour à l'accueil (plus de barre d'onglets).
 *
 * @package LwbMigration
 *
 * @var string $tab   Section courante (home pour l'accueil).
 * @var array  $tabs  Sections : slug => title, lead.
 * @var string $error Erreur de stockage éventuelle.
 */

defined( 'ABSPATH' ) || exit;

$lwbm_update = \LwbMigration\Updater::status();
?>
<div class="wrap lwbm lwbm-tab-<?php echo esc_attr( $tab ); ?>">
	<header class="lwbm-top">
		<?php if ( 'home' !== $tab ) : ?>
			<a class="lwbm-back" href="<?php echo esc_url( \LwbMigration\Admin::url() ); ?>"><?php echo \LwbMigration\Admin::icon( 'back', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?> <?php esc_html_e( 'Accueil', 'lwb-migration' ); ?></a>
		<?php endif; ?>
		<a class="lwbm-brand" href="<?php echo esc_url( \LwbMigration\Admin::url() ); ?>">
			<img class="lwbm-logo" src="<?php echo esc_url( LWB_MIGRATION_URL . 'assets/icon.svg' ); ?>" alt="" width="48" height="48">
			<span class="lwbm-brand-text">
				<span class="lwbm-brand-name"><?php esc_html_e( 'Lwb Migration', 'lwb-migration' ); ?> <span class="lwbm-version">v<?php echo esc_html( LWB_MIGRATION_VERSION ); ?></span></span>
				<span class="lwbm-brand-sub"><?php esc_html_e( 'Migrations et sauvegardes WordPress', 'lwb-migration' ); ?></span>
			</span>
		</a>
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
					<?php echo '' !== $lwbm_update['latest'] ? esc_html__( 'À jour', 'lwb-migration' ) : esc_html__( 'Mises à jour automatiques', 'lwb-migration' ); ?>
				</span>
				<button type="button" class="lwbm-update-btn" data-lwbm-check-update><?php esc_html_e( 'Vérifier', 'lwb-migration' ); ?></button>
			<?php endif; ?>
		</div>
	</header>
	<?php // Les notices des autres extensions s'affichent ici, sous l'en-tête, et non à l'intérieur. ?>
	<hr class="wp-header-end">

	<?php if ( '' !== $error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<?php if ( 'home' === $tab ) : ?>
		<section class="lwbm-intro">
			<h1><?php esc_html_e( 'Que voulez-vous faire ?', 'lwb-migration' ); ?></h1>
			<p><?php esc_html_e( 'Choisissez une action. Tout se fait par petites étapes, sans rien configurer.', 'lwb-migration' ); ?></p>
		</section>
	<?php else : ?>
		<section class="lwbm-intro">
			<h1><?php echo esc_html( $tabs[ $tab ]['title'] ); ?></h1>
			<p><?php echo esc_html( $tabs[ $tab ]['lead'] ); ?></p>
		</section>
	<?php endif; ?>

	<div class="lwbm-notice" id="lwbm-notice" role="alert" hidden></div>

	<main class="lwbm-body">
		<?php require __DIR__ . '/tab-' . $tab . '.php'; ?>
	</main>
</div>
