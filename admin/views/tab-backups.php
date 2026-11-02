<?php
/**
 * Section « Sauvegardes ».
 *
 * @package LwbMigration
 */

defined( 'ABSPATH' ) || exit;

$lwbm_b      = \LwbMigration\Backups::settings();
$lwbm_direct = false;
$lwbm_days   = array(
	1 => __( 'lundi', 'lwb-migration' ),
	2 => __( 'mardi', 'lwb-migration' ),
	3 => __( 'mercredi', 'lwb-migration' ),
	4 => __( 'jeudi', 'lwb-migration' ),
	5 => __( 'vendredi', 'lwb-migration' ),
	6 => __( 'samedi', 'lwb-migration' ),
	0 => __( 'dimanche', 'lwb-migration' ),
);
$lwbm_freqs  = array(
	'twicedaily' => array( __( '2× par jour', 'lwb-migration' ), __( 'Sites très actifs', 'lwb-migration' ) ),
	'daily'      => array( __( 'Chaque jour', 'lwb-migration' ), __( 'Recommandé', 'lwb-migration' ) ),
	'weekly'     => array( __( 'Chaque semaine', 'lwb-migration' ), __( 'Sites peu modifiés', 'lwb-migration' ) ),
	'monthly'    => array( __( 'Chaque mois', 'lwb-migration' ), __( 'Sites vitrines', 'lwb-migration' ) ),
);
$lwbm_tz     = wp_timezone_string();
?>
<div class="lwbm-split">
	<section class="lwbm-card lwbm-split-main">
		<form id="lwbm-backup-form" class="lwbm-backup-form" novalidate>
			<label class="lwbm-toggle-card lwbm-toggle-card-lg" id="lwbm-b-enabled-card">
				<span class="lwbm-toggle-icon"><?php echo \LwbMigration\Admin::icon( 'backups', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?></span>
				<span class="lwbm-toggle-text">
					<span class="lwbm-choice-title"><?php esc_html_e( 'Sauvegardes automatiques', 'lwb-migration' ); ?></span>
					<span class="lwbm-choice-text"><?php esc_html_e( 'En arrière-plan, par petites étapes, sans ralentir le site.', 'lwb-migration' ); ?></span>
				</span>
				<span class="lwbm-switch"><input type="checkbox" id="lwbm-b-enabled" name="enabled" value="1" <?php checked( $lwbm_b['enabled'] ); ?>><span aria-hidden="true"></span></span>
			</label>

			<div class="lwbm-backup-fields" data-lwbm-backup-fields>
				<fieldset class="lwbm-choices">
					<legend class="lwbm-step-title"><?php esc_html_e( 'Fréquence', 'lwb-migration' ); ?></legend>
					<div class="lwbm-choice-grid lwbm-choice-grid-4">
						<?php foreach ( $lwbm_freqs as $lwbm_v => $lwbm_f ) : ?>
							<label class="lwbm-choice lwbm-choice-sm">
								<input type="radio" name="frequency" value="<?php echo esc_attr( $lwbm_v ); ?>" <?php checked( $lwbm_b['frequency'], $lwbm_v ); ?>>
								<span class="lwbm-choice-body">
									<span class="lwbm-choice-title"><?php echo esc_html( $lwbm_f[0] ); ?></span>
									<span class="lwbm-choice-text"><?php echo esc_html( $lwbm_f[1] ); ?></span>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<fieldset class="lwbm-choices" data-lwbm-when="weekly">
					<legend class="lwbm-step-title"><?php esc_html_e( 'Jour', 'lwb-migration' ); ?></legend>
					<div class="lwbm-chipset">
						<?php foreach ( $lwbm_days as $lwbm_n => $lwbm_day ) : ?>
							<label class="lwbm-chip-radio"><input type="radio" name="weekday" value="<?php echo esc_attr( $lwbm_n ); ?>" <?php checked( (int) $lwbm_b['weekday'], $lwbm_n ); ?>><span><?php echo esc_html( ucfirst( $lwbm_day ) ); ?></span></label>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<div class="lwbm-inline-fields">
					<div class="lwbm-field" data-lwbm-when="monthly">
						<label for="lwbm-b-monthday"><?php esc_html_e( 'Jour du mois', 'lwb-migration' ); ?></label>
						<select id="lwbm-b-monthday" name="monthday" class="lwbm-select">
							<?php for ( $lwbm_d = 1; $lwbm_d <= 28; $lwbm_d++ ) : ?>
								<option value="<?php echo esc_attr( $lwbm_d ); ?>" <?php selected( (int) $lwbm_b['monthday'], $lwbm_d ); ?>><?php echo esc_html( $lwbm_d ); ?></option>
							<?php endfor; ?>
						</select>
					</div>
					<div class="lwbm-field">
						<label for="lwbm-b-hour"><?php esc_html_e( 'Heure', 'lwb-migration' ); ?></label>
						<select id="lwbm-b-hour" name="hour" class="lwbm-select">
							<?php for ( $lwbm_h = 0; $lwbm_h < 24; $lwbm_h++ ) : ?>
								<option value="<?php echo esc_attr( $lwbm_h ); ?>" <?php selected( (int) $lwbm_b['hour'], $lwbm_h ); ?>><?php echo esc_html( sprintf( '%02d:00', $lwbm_h ) ); ?></option>
							<?php endfor; ?>
						</select>
						<p class="lwbm-hint">
							<?php
							/* translators: %s: fuseau horaire du site. */
							printf( esc_html__( 'Fuseau du site : %s. Choisissez une heure creuse.', 'lwb-migration' ), esc_html( $lwbm_tz ) );
							?>
						</p>
					</div>
				</div>

				<fieldset class="lwbm-choices">
					<legend class="lwbm-step-title"><?php esc_html_e( 'Contenu', 'lwb-migration' ); ?></legend>
					<div class="lwbm-choice-grid">
						<label class="lwbm-choice lwbm-choice-sm">
							<input type="radio" name="content" value="full" <?php checked( $lwbm_b['content'], 'full' ); ?>>
							<span class="lwbm-choice-body">
								<span class="lwbm-choice-title"><?php esc_html_e( 'Site complet', 'lwb-migration' ); ?></span>
								<span class="lwbm-choice-text"><?php esc_html_e( 'Fichiers et base de données.', 'lwb-migration' ); ?></span>
							</span>
						</label>
						<label class="lwbm-choice lwbm-choice-sm">
							<input type="radio" name="content" value="database" <?php checked( $lwbm_b['content'], 'database' ); ?>>
							<span class="lwbm-choice-body">
								<span class="lwbm-choice-title"><?php esc_html_e( 'Base de données seule', 'lwb-migration' ); ?></span>
								<span class="lwbm-choice-text"><?php esc_html_e( 'Plus rapide et plus léger, idéal pour des sauvegardes fréquentes.', 'lwb-migration' ); ?></span>
							</span>
						</label>
					</div>
				</fieldset>

				<div class="lwbm-choice-grid">
					<div class="lwbm-mini-card">
						<label class="lwbm-step-title" for="lwbm-b-keep"><?php esc_html_e( 'Sauvegardes conservées', 'lwb-migration' ); ?></label>
						<div class="lwbm-stepper">
							<button type="button" class="lwbm-stepper-btn" data-lwbm-step="-1" aria-label="<?php esc_attr_e( 'Une de moins', 'lwb-migration' ); ?>">−</button>
							<input type="number" id="lwbm-b-keep" name="keep" min="1" max="60" value="<?php echo esc_attr( $lwbm_b['keep'] ); ?>">
							<button type="button" class="lwbm-stepper-btn" data-lwbm-step="1" aria-label="<?php esc_attr_e( 'Une de plus', 'lwb-migration' ); ?>">+</button>
						</div>
						<p class="lwbm-hint"><?php esc_html_e( 'Les plus anciennes sont supprimées automatiquement.', 'lwb-migration' ); ?></p>
					</div>
					<div class="lwbm-mini-card">
						<span class="lwbm-step-title" id="lwbm-b-notify-label"><?php esc_html_e( 'Me prévenir par e-mail', 'lwb-migration' ); ?></span>
						<div class="lwbm-chipset" role="radiogroup" aria-labelledby="lwbm-b-notify-label">
							<label class="lwbm-chip-radio"><input type="radio" name="notify" value="failure" <?php checked( $lwbm_b['notify'], 'failure' ); ?>><span><?php esc_html_e( 'En cas d’échec', 'lwb-migration' ); ?></span></label>
							<label class="lwbm-chip-radio"><input type="radio" name="notify" value="always" <?php checked( $lwbm_b['notify'], 'always' ); ?>><span><?php esc_html_e( 'Toujours', 'lwb-migration' ); ?></span></label>
							<label class="lwbm-chip-radio"><input type="radio" name="notify" value="never" <?php checked( $lwbm_b['notify'], 'never' ); ?>><span><?php esc_html_e( 'Jamais', 'lwb-migration' ); ?></span></label>
						</div>
						<div class="lwbm-field" data-lwbm-when-notify>
							<label for="lwbm-b-email" class="screen-reader-text"><?php esc_html_e( 'Adresse de notification', 'lwb-migration' ); ?></label>
							<input type="email" id="lwbm-b-email" name="email" class="lwbm-input lwbm-input-wide" value="<?php echo esc_attr( $lwbm_b['email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
						</div>
					</div>
				</div>
			</div>

			<div class="lwbm-actions lwbm-backup-actions">
				<button type="submit" class="lwbm-btn lwbm-btn-primary lwbm-btn-xl"><?php echo \LwbMigration\Admin::icon( 'check', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?> <?php esc_html_e( 'Enregistrer', 'lwb-migration' ); ?></button>
				<button type="button" class="lwbm-btn lwbm-btn-outline lwbm-btn-xl" id="lwbm-backup-now"><?php echo \LwbMigration\Admin::icon( 'restore', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?> <?php esc_html_e( 'Sauvegarder maintenant', 'lwb-migration' ); ?></button>
			</div>
		</form>

		<?php require __DIR__ . '/progress.php'; ?>
	</section>

	<aside class="lwbm-side">
		<section class="lwbm-card">
			<h2 class="lwbm-card-title"><?php esc_html_e( 'État', 'lwb-migration' ); ?></h2>
			<ul class="lwbm-env" id="lwbm-backup-state">
				<li><span class="lwbm-env-dot" aria-hidden="true"></span><span class="lwbm-env-label"><?php esc_html_e( 'Chargement…', 'lwb-migration' ); ?></span><strong></strong></li>
			</ul>
		</section>
		<section class="lwbm-card lwbm-card-soft">
			<h2 class="lwbm-card-title"><?php esc_html_e( 'Bon à savoir', 'lwb-migration' ); ?></h2>
			<p><?php esc_html_e( 'Les sauvegardes sont conservées sur ce serveur, dans un dossier protégé. Téléchargez régulièrement une copie : elle vous protège aussi d’une panne de l’hébergement.', 'lwb-migration' ); ?></p>
			<p><?php esc_html_e( 'WordPress déclenche les tâches planifiées lors des visites. Sur un site peu visité, demandez à votre hébergeur une tâche cron vers wp-cron.php pour une heure exacte.', 'lwb-migration' ); ?></p>
		</section>
	</aside>
</div>

<section class="lwbm-card">
	<h2 class="lwbm-card-title"><?php esc_html_e( 'Sauvegardes disponibles', 'lwb-migration' ); ?></h2>
	<div class="lwbm-packages" id="lwbm-backups-body" data-lwbm-kind="backup">
		<div class="lwbm-empty"><?php esc_html_e( 'Chargement…', 'lwb-migration' ); ?></div>
	</div>
</section>

<section class="lwbm-card">
	<h2 class="lwbm-card-title"><?php esc_html_e( 'Historique', 'lwb-migration' ); ?></h2>
	<ol class="lwbm-history" id="lwbm-backup-history">
		<li class="lwbm-empty"><?php esc_html_e( 'Chargement…', 'lwb-migration' ); ?></li>
	</ol>
</section>
