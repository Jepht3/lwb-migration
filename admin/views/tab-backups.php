<?php
/**
 * Onglet « Sauvegardes ».
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
$lwbm_tz     = wp_timezone_string();
?>
<div class="lwbm-grid">
	<section class="lwbm-card lwbm-card-main">
		<div class="lwbm-card-head">
			<span class="lwbm-icon lwbm-icon-primary" aria-hidden="true"><span class="dashicons dashicons-backup"></span></span>
			<div>
				<h2><?php esc_html_e( 'Sauvegardes automatiques', 'lwb-migration' ); ?></h2>
				<p><?php esc_html_e( 'Votre site est sauvegardé tout seul, au rythme choisi. Chaque sauvegarde se restaure en un clic, ou s’installe sur un autre serveur avec son installer.php.', 'lwb-migration' ); ?></p>
			</div>
		</div>

		<form id="lwbm-backup-form" class="lwbm-settings lwbm-backup-form" novalidate>
			<div class="lwbm-row">
				<div class="lwbm-row-text">
					<label for="lwbm-b-enabled"><?php esc_html_e( 'Activer les sauvegardes automatiques', 'lwb-migration' ); ?></label>
					<p class="lwbm-hint"><?php esc_html_e( 'Elles s’exécutent en arrière-plan, par petites étapes, sans ralentir le site.', 'lwb-migration' ); ?></p>
				</div>
				<label class="lwbm-switch"><input type="checkbox" id="lwbm-b-enabled" name="enabled" value="1" <?php checked( $lwbm_b['enabled'] ); ?>><span aria-hidden="true"></span></label>
			</div>

			<fieldset class="lwbm-backup-fields" data-lwbm-backup-fields>
				<div class="lwbm-row">
					<div class="lwbm-row-text">
						<label for="lwbm-b-frequency"><?php esc_html_e( 'Fréquence', 'lwb-migration' ); ?></label>
					</div>
					<select id="lwbm-b-frequency" name="frequency" class="lwbm-select">
						<option value="twicedaily" <?php selected( $lwbm_b['frequency'], 'twicedaily' ); ?>><?php esc_html_e( 'Deux fois par jour', 'lwb-migration' ); ?></option>
						<option value="daily" <?php selected( $lwbm_b['frequency'], 'daily' ); ?>><?php esc_html_e( 'Tous les jours', 'lwb-migration' ); ?></option>
						<option value="weekly" <?php selected( $lwbm_b['frequency'], 'weekly' ); ?>><?php esc_html_e( 'Toutes les semaines', 'lwb-migration' ); ?></option>
						<option value="monthly" <?php selected( $lwbm_b['frequency'], 'monthly' ); ?>><?php esc_html_e( 'Tous les mois', 'lwb-migration' ); ?></option>
					</select>
				</div>
				<div class="lwbm-row" data-lwbm-when="weekly">
					<div class="lwbm-row-text">
						<label for="lwbm-b-weekday"><?php esc_html_e( 'Jour de la semaine', 'lwb-migration' ); ?></label>
					</div>
					<select id="lwbm-b-weekday" name="weekday" class="lwbm-select">
						<?php foreach ( $lwbm_days as $lwbm_n => $lwbm_day ) : ?>
							<option value="<?php echo esc_attr( $lwbm_n ); ?>" <?php selected( (int) $lwbm_b['weekday'], $lwbm_n ); ?>><?php echo esc_html( ucfirst( $lwbm_day ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="lwbm-row" data-lwbm-when="monthly">
					<div class="lwbm-row-text">
						<label for="lwbm-b-monthday"><?php esc_html_e( 'Jour du mois', 'lwb-migration' ); ?></label>
						<p class="lwbm-hint"><?php esc_html_e( 'De 1 à 28, pour exister tous les mois.', 'lwb-migration' ); ?></p>
					</div>
					<select id="lwbm-b-monthday" name="monthday" class="lwbm-select">
						<?php for ( $lwbm_d = 1; $lwbm_d <= 28; $lwbm_d++ ) : ?>
							<option value="<?php echo esc_attr( $lwbm_d ); ?>" <?php selected( (int) $lwbm_b['monthday'], $lwbm_d ); ?>><?php echo esc_html( $lwbm_d ); ?></option>
						<?php endfor; ?>
					</select>
				</div>
				<div class="lwbm-row">
					<div class="lwbm-row-text">
						<label for="lwbm-b-hour"><?php esc_html_e( 'Heure', 'lwb-migration' ); ?></label>
						<p class="lwbm-hint">
							<?php
							/* translators: %s: fuseau horaire du site. */
							printf( esc_html__( 'Fuseau horaire du site : %s. Choisissez une heure creuse.', 'lwb-migration' ), esc_html( $lwbm_tz ) );
							?>
						</p>
					</div>
					<select id="lwbm-b-hour" name="hour" class="lwbm-select">
						<?php for ( $lwbm_h = 0; $lwbm_h < 24; $lwbm_h++ ) : ?>
							<option value="<?php echo esc_attr( $lwbm_h ); ?>" <?php selected( (int) $lwbm_b['hour'], $lwbm_h ); ?>><?php echo esc_html( sprintf( '%02d:00', $lwbm_h ) ); ?></option>
						<?php endfor; ?>
					</select>
				</div>
				<div class="lwbm-row">
					<div class="lwbm-row-text">
						<label for="lwbm-b-content"><?php esc_html_e( 'Contenu', 'lwb-migration' ); ?></label>
						<p class="lwbm-hint"><?php esc_html_e( 'La base de données seule est plus rapide et plus légère : idéale pour des sauvegardes fréquentes d’un site dont les fichiers changent peu.', 'lwb-migration' ); ?></p>
					</div>
					<select id="lwbm-b-content" name="content" class="lwbm-select">
						<option value="full" <?php selected( $lwbm_b['content'], 'full' ); ?>><?php esc_html_e( 'Site complet (fichiers + base de données)', 'lwb-migration' ); ?></option>
						<option value="database" <?php selected( $lwbm_b['content'], 'database' ); ?>><?php esc_html_e( 'Base de données uniquement', 'lwb-migration' ); ?></option>
					</select>
				</div>
				<div class="lwbm-row">
					<div class="lwbm-row-text">
						<label for="lwbm-b-keep"><?php esc_html_e( 'Sauvegardes conservées', 'lwb-migration' ); ?></label>
						<p class="lwbm-hint"><?php esc_html_e( 'Au-delà, les plus anciennes sont supprimées automatiquement. Les paquets de migration ont leur propre limite.', 'lwb-migration' ); ?></p>
					</div>
					<input type="number" id="lwbm-b-keep" name="keep" min="1" max="60" value="<?php echo esc_attr( $lwbm_b['keep'] ); ?>" class="lwbm-input lwbm-input-sm">
				</div>
				<div class="lwbm-row">
					<div class="lwbm-row-text">
						<label for="lwbm-b-notify"><?php esc_html_e( 'Notification par e-mail', 'lwb-migration' ); ?></label>
					</div>
					<select id="lwbm-b-notify" name="notify" class="lwbm-select">
						<option value="failure" <?php selected( $lwbm_b['notify'], 'failure' ); ?>><?php esc_html_e( 'Seulement en cas d’échec', 'lwb-migration' ); ?></option>
						<option value="always" <?php selected( $lwbm_b['notify'], 'always' ); ?>><?php esc_html_e( 'Après chaque sauvegarde', 'lwb-migration' ); ?></option>
						<option value="never" <?php selected( $lwbm_b['notify'], 'never' ); ?>><?php esc_html_e( 'Jamais', 'lwb-migration' ); ?></option>
					</select>
				</div>
				<div class="lwbm-row" data-lwbm-when-notify>
					<div class="lwbm-row-text">
						<label for="lwbm-b-email"><?php esc_html_e( 'Adresse de notification', 'lwb-migration' ); ?></label>
						<p class="lwbm-hint"><?php esc_html_e( 'Vide : adresse de l’administrateur du site.', 'lwb-migration' ); ?></p>
					</div>
					<input type="email" id="lwbm-b-email" name="email" class="lwbm-input" value="<?php echo esc_attr( $lwbm_b['email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
				</div>
			</fieldset>

			<div class="lwbm-actions lwbm-backup-actions">
				<button type="submit" class="lwbm-btn lwbm-btn-primary"><span class="dashicons dashicons-saved" aria-hidden="true"></span> <?php esc_html_e( 'Enregistrer', 'lwb-migration' ); ?></button>
				<button type="button" class="lwbm-btn lwbm-btn-soft" id="lwbm-backup-now"><span class="dashicons dashicons-backup" aria-hidden="true"></span> <?php esc_html_e( 'Sauvegarder maintenant', 'lwb-migration' ); ?></button>
			</div>
		</form>

		<?php require __DIR__ . '/progress.php'; ?>
	</section>

	<aside class="lwbm-side">
		<section class="lwbm-card">
			<h2 class="lwbm-card-title"><span class="dashicons dashicons-clock" aria-hidden="true"></span> <?php esc_html_e( 'État', 'lwb-migration' ); ?></h2>
			<ul class="lwbm-env" id="lwbm-backup-state">
				<li><span class="lwbm-env-dot" aria-hidden="true"></span><span class="lwbm-env-label"><?php esc_html_e( 'Chargement…', 'lwb-migration' ); ?></span><strong></strong></li>
			</ul>
		</section>
		<section class="lwbm-card lwbm-card-tip">
			<h2 class="lwbm-card-title"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span> <?php esc_html_e( 'Bon à savoir', 'lwb-migration' ); ?></h2>
			<p><?php esc_html_e( 'Les sauvegardes sont conservées sur ce serveur, dans un dossier protégé. Téléchargez régulièrement une copie : elle vous protège aussi d’une panne de l’hébergement.', 'lwb-migration' ); ?></p>
			<p><?php esc_html_e( 'WordPress déclenche les tâches planifiées lors des visites. Sur un site peu visité, demandez à votre hébergeur une tâche cron vers wp-cron.php pour une heure exacte.', 'lwb-migration' ); ?></p>
		</section>
	</aside>
</div>

<section class="lwbm-card">
	<div class="lwbm-section-head">
		<h2 class="lwbm-card-title"><span class="dashicons dashicons-portfolio" aria-hidden="true"></span> <?php esc_html_e( 'Sauvegardes disponibles', 'lwb-migration' ); ?></h2>
	</div>
	<div class="lwbm-packages" id="lwbm-backups-body" data-lwbm-kind="backup">
		<div class="lwbm-empty"><?php esc_html_e( 'Chargement…', 'lwb-migration' ); ?></div>
	</div>
</section>

<section class="lwbm-card">
	<h2 class="lwbm-card-title"><span class="dashicons dashicons-list-view" aria-hidden="true"></span> <?php esc_html_e( 'Historique', 'lwb-migration' ); ?></h2>
	<ol class="lwbm-history" id="lwbm-backup-history">
		<li class="lwbm-empty"><?php esc_html_e( 'Chargement…', 'lwb-migration' ); ?></li>
	</ol>
</section>
