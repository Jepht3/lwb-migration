<?php
/**
 * Onglet « Migration directe ».
 *
 * @package LwbMigration
 */

defined( 'ABSPATH' ) || exit;

$lwbm_key    = \LwbMigration\Direct_Transfer::active_key();
$lwbm_http   = ! is_ssl() && ! \LwbMigration\Direct_Transfer::allow_http();
$lwbm_direct = true;
?>

<div class="lwbm-grid lwbm-grid-2 lwbm-direct">
	<section class="lwbm-card">
		<div class="lwbm-card-head">
			<span class="lwbm-step-badge">1</span>
			<div>
				<span class="lwbm-eyebrow"><?php esc_html_e( 'Sur le site de destination', 'lwb-migration' ); ?></span>
				<h2><?php esc_html_e( 'Générer une clé', 'lwb-migration' ); ?></h2>
			</div>
		</div>
		<p><?php esc_html_e( 'La clé est valable 24 heures et ne sert qu’une seule fois.', 'lwb-migration' ); ?></p>
		<?php if ( $lwbm_http ) : ?>
			<div class="lwbm-callout lwbm-callout-danger"><span class="dashicons dashicons-lock" aria-hidden="true"></span><p><?php esc_html_e( 'Ce site n’utilise pas HTTPS : la clé sera refusée par le site source. Activez un certificat SSL pour utiliser la migration directe.', 'lwb-migration' ); ?></p></div>
		<?php endif; ?>
		<div class="lwbm-callout lwbm-callout-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span><p><?php esc_html_e( 'Tout le contenu de ce site (fichiers et base de données) sera remplacé par celui du site source.', 'lwb-migration' ); ?></p></div>
		<div class="lwbm-actions">
			<button type="button" class="lwbm-btn lwbm-btn-primary lwbm-btn-lg" id="lwbm-key-generate"><?php echo \LwbMigration\Admin::icon( 'key', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?> <?php esc_html_e( 'Générer une clé de migration', 'lwb-migration' ); ?></button>
			<button type="button" class="lwbm-btn lwbm-btn-ghost" id="lwbm-key-revoke" <?php echo $lwbm_key ? '' : 'hidden'; ?>><?php esc_html_e( 'Révoquer la clé', 'lwb-migration' ); ?></button>
		</div>
		<div class="lwbm-keybox" id="lwbm-key-box" hidden>
			<label for="lwbm-key-output" class="screen-reader-text"><?php esc_html_e( 'Clé de migration', 'lwb-migration' ); ?></label>
			<textarea id="lwbm-key-output" class="lwbm-code" rows="5" readonly></textarea>
			<div class="lwbm-keybox-foot">
				<span class="lwbm-hint" id="lwbm-key-expires"></span>
				<button type="button" class="lwbm-btn lwbm-btn-soft" id="lwbm-key-copy"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span> <?php esc_html_e( 'Copier la clé', 'lwb-migration' ); ?></button>
			</div>
		</div>
		<?php if ( $lwbm_key ) : ?>
			<p class="lwbm-hint" id="lwbm-key-active">
				<span class="lwbm-pill lwbm-pill-ok"><?php esc_html_e( 'Clé active', 'lwb-migration' ); ?></span>
				<?php
				/* translators: %s: date d'expiration. */
				printf( esc_html__( 'jusqu’au %s', 'lwb-migration' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $lwbm_key['expires'] ) ) );
				?>
			</p>
		<?php endif; ?>
	</section>

	<section class="lwbm-card">
		<div class="lwbm-card-head">
			<span class="lwbm-step-badge">2</span>
			<div>
				<span class="lwbm-eyebrow"><?php esc_html_e( 'Sur le site source (celui-ci)', 'lwb-migration' ); ?></span>
				<h2><?php esc_html_e( 'Coller la clé et lancer', 'lwb-migration' ); ?></h2>
			</div>
		</div>
		<label for="lwbm-direct-key" class="lwbm-label"><?php esc_html_e( 'Clé de migration', 'lwb-migration' ); ?></label>
		<textarea id="lwbm-direct-key" class="lwbm-code" rows="5" spellcheck="false" placeholder="LWB1.…"></textarea>
		<details class="lwbm-advanced" id="lwbm-direct-advanced">
			<summary><?php esc_html_e( 'Options avancées : pare-feu, migration avant le changement de DNS', 'lwb-migration' ); ?></summary>
			<div class="lwbm-field">
				<span class="lwbm-label" id="lwbm-direct-mode-label"><?php esc_html_e( 'Mode de transfert', 'lwb-migration' ); ?></span>
				<div class="lwbm-chipset" role="radiogroup" aria-labelledby="lwbm-direct-mode-label">
					<label class="lwbm-chip-radio"><input type="radio" name="lwbm-direct-mode" value="auto" checked><span><?php esc_html_e( 'Automatique (recommandé)', 'lwb-migration' ); ?></span></label>
					<label class="lwbm-chip-radio"><input type="radio" name="lwbm-direct-mode" value="push"><span><?php esc_html_e( 'Envoi par ce site', 'lwb-migration' ); ?></span></label>
					<label class="lwbm-chip-radio"><input type="radio" name="lwbm-direct-mode" value="pull"><span><?php esc_html_e( 'Téléchargement par la destination', 'lwb-migration' ); ?></span></label>
				</div>
				<p class="lwbm-hint"><?php esc_html_e( 'Si le pare-feu de l’hébergeur de destination bloque les envois, la destination télécharge elle-même l’archive grâce à un lien signé, temporaire et reprenable. En automatique, la bascule se fait toute seule.', 'lwb-migration' ); ?></p>
			</div>
			<div class="lwbm-field">
				<label for="lwbm-direct-via"><?php esc_html_e( 'Adresse du nouveau serveur (avant le changement de DNS)', 'lwb-migration' ); ?></label>
				<input type="text" id="lwbm-direct-via" class="lwbm-input lwbm-input-wide" spellcheck="false" autocomplete="off" placeholder="203.0.113.10 · https://serveur.hebergeur.fr/~compte">
				<p class="lwbm-hint"><?php esc_html_e( 'Le domaine pointe encore vers l’ancien serveur ? Indiquez l’adresse IP du nouveau serveur (connexion vérifiée sur le vrai nom de domaine) ou son URL temporaire. Ce site installera alors lui-même le site sur le nouveau serveur : il ne restera qu’à changer les DNS.', 'lwb-migration' ); ?></p>
			</div>
			<div class="lwbm-field">
				<label for="lwbm-direct-final"><?php esc_html_e( 'Adresse définitive du site (facultatif)', 'lwb-migration' ); ?></label>
				<input type="url" id="lwbm-direct-final" class="lwbm-input lwbm-input-wide" spellcheck="false" autocomplete="off" placeholder="https://www.mon-site.fr">
				<p class="lwbm-hint"><?php esc_html_e( 'À renseigner si le WordPress de destination a été installé avec une adresse provisoire : le site sera installé avec cette adresse. Laissez vide pour garder celle de la destination.', 'lwb-migration' ); ?></p>
			</div>
		</details>
		<button type="button" class="lwbm-btn lwbm-btn-primary lwbm-btn-xl" id="lwbm-direct-start">
			<?php echo \LwbMigration\Admin::icon( 'direct', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?>
			<?php esc_html_e( 'Lancer la migration directe', 'lwb-migration' ); ?>
		</button>
		<?php require __DIR__ . '/progress.php'; ?>
		<div class="lwbm-result" id="lwbm-direct-result" hidden>
			<span class="lwbm-result-icon" aria-hidden="true"><span class="dashicons dashicons-yes"></span></span>
			<div>
				<h3><?php esc_html_e( 'Transfert terminé !', 'lwb-migration' ); ?></h3>
				<p><?php esc_html_e( 'Dernière étape : ouvrez l’installeur sur le site de destination. Il se déroule automatiquement.', 'lwb-migration' ); ?></p>
				<a class="lwbm-btn lwbm-btn-primary" id="lwbm-direct-open" href="#" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-external" aria-hidden="true"></span> <?php esc_html_e( 'Ouvrir l’installeur sur la destination', 'lwb-migration' ); ?></a>
			</div>
		</div>
		<div class="lwbm-result" id="lwbm-direct-installed" hidden>
			<span class="lwbm-result-icon" aria-hidden="true"><span class="dashicons dashicons-yes"></span></span>
			<div>
				<h3><?php esc_html_e( 'Site installé sur le nouveau serveur !', 'lwb-migration' ); ?></h3>
				<p>
					<?php esc_html_e( 'Dernière étape : faites pointer le domaine vers le nouveau serveur (DNS). Le site répondra à l’adresse', 'lwb-migration' ); ?>
					<strong data-lwbm-site></strong>.
				</p>
				<p class="lwbm-hint" data-lwbm-hosts-wrap hidden>
					<?php esc_html_e( 'Pour le vérifier dès maintenant, ajoutez cette ligne au fichier hosts de votre ordinateur :', 'lwb-migration' ); ?>
					<code data-lwbm-hosts></code>
				</p>
			</div>
		</div>
	</section>
</div>

<section class="lwbm-card lwbm-migrations">
	<h2 class="lwbm-card-title"><?php esc_html_e( 'Historique des migrations directes', 'lwb-migration' ); ?></h2>
	<p class="lwbm-hint"><?php esc_html_e( 'Chaque migration est inscrite dès son démarrage. Le fichier du site est d’abord créé ici : tant qu’il est conservé, vous pouvez le télécharger pour l’importer à la main.', 'lwb-migration' ); ?></p>
	<div class="lwbm-packages" id="lwbm-history-body" data-lwbm-history-type="direct">
		<?php
		$lwbm_history_type = 'direct';
		require __DIR__ . '/history.php';
		?>
	</div>
</section>
