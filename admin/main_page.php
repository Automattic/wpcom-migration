<header class="wpcom-migration-header">
	<div class="wpcom-migration-header__wpcom-logo">
		<span class="dashicons dashicons-wordpress-alt"></span>
	</div>

	<div class="wpcom-migration-header__blogvault-logo">
		Powered by <img src="<?php echo esc_url( plugins_url('../assets/img/blogvault-logo.png', __FILE__ ) ); ?>" alt="blogvault-logo">
	</div>
</header>

<div class="wpcom-migration-container">
	<main class="wpcom-migration-content">
		<h1>Migrate your site to WordPress.com</h1>
		<p>Get ready for better speed, security, and support. Just drop your email below to get started and we'll keep you updated throughout the migration process.</p>

		<form class="wpcom-migration-form" action="<?php echo esc_url($this->bvinfo->appUrl()); ?>/migration/migrate" method="post" name="signup">
			<div class="wpcom-migration-section">
				<div class="wpcom-migration-input-group">
					<label for="wpcom-migration-email">Email address</label>
					<input type="email" placeholder="Enter your email address for updates" id="wpcom-migration-email" name="email" required>
				</div>

				<div class="wpcom-migration-input-group wpcom-migration-input-group--checkbox">
					<label>
						<input type="checkbox" id="wpcom-migration-terms" name="consent" required value="1">
						<span class="checkmark"></span>
						I agree to BlogVault's&nbsp;
						<a href="https://blogvault.net/tos/" target="_blank" rel="noreferrer">Terms & Conditions</a>
						&nbsp;and&nbsp;
						<a href="https://blogvault.net/privacy/" target="_blank" rel="noreferrer">Privacy Policy</a>
					</label>
				</div>
			</div>

			<div class="wpcom-migration-section">
				<input type="hidden" name="bvsrc" value="wpplugin" />
				<input type="hidden" name="migrate" value="automattic" />
				<?php echo $this->siteInfoTags(); ?>
				<button type="submit" id="migratesubmit">Continue</button>
			</div>

			<?php if ( defined( 'IS_ATOMIC' ) && IS_ATOMIC && defined( 'ATOMIC_CLIENT_ID' )  && '2' === ATOMIC_CLIENT_ID ) : ?>
				<div class="wpcom-migration-key-section">
					<h3>Migration in progress?</h3>
					<p>Grab your key here.</p>

					<div class="wpcom-migration-input-group">
						<label for="wpcom-migration-email">Migration Key</label>
						<div class="wpcom-migration-key-input-wrapper">
							<div class="wpcom-migration-key-input">
								<input type="password" id="wpcom-migration-key" value="<?php echo esc_attr( $this->bvinfo->getConnectionKey() ); ?>" readonly>
								<span id="wpcom-toggle-key-visibility" class="dashicons dashicons-hidden"></span>
							</div>
							<button type="button" id="wpcom-copy-key" class="secondary" onclick="copyToClipboard()">Copy Key</button>
						</div>
					</div>
				</div>
			<?php endif; ?>
		</form>
	</main>

	<aside class="wpcom-migration-sidebar">
		<div class="wpcom-migration-sidebar__inner">
			<h3>Let us migrate your site for free</h3>
			<p>Sit back and our experts will migrate your site for you. You'll get 50% off your first year, and you'll be up and running in no more than 2 business days.</p>

			<div class="wpcom-migration-cta">
				<a class="wpcom-migration-cta-link" href="https://wordpress.com/move/" target="_blank" rel="noreferrer">
					Get your Free migration
				</a>
				<span class="dashicons dashicons-external"></span>
			</div>

			<div class="wpcom-migration-testimonial">
				<div class="wpcom-migration-testimonial__text">Loved by our customers</div>
				<img class="wpcom-migration-testimonial__image" src="<?php echo esc_url( plugins_url('../assets/img/testimonial.png', __FILE__ ) ); ?>" alt="testimonial" />
			</div>
		</div>
	</aside>
</div>

<script>
	function copyToClipboard() {
		var copyText = document.getElementById("wpcom-migration-key");
		var copyButton = document.getElementById("wpcom-copy-key");
		var toggleIcon = document.getElementById("wpcom-toggle-key-visibility");
		copyText.type = 'text';
		copyText.select();
		document.execCommand("copy");
		copyText.blur();
		copyText.type = 'password';
		copyButton.textContent = 'Copied!';
		toggleIcon.classList.remove('dashicons-visibility');
		toggleIcon.classList.add('dashicons-hidden');
		setTimeout(() => copyButton.textContent = 'Copy Key', 2000);
	}
	document.getElementById('wpcom-toggle-key-visibility').addEventListener('click', function() {
		var keyField = document.getElementById("wpcom-migration-key");
		if (keyField.type === "password") {
			keyField.type = "text";
			this.classList.remove('dashicons-hidden');
			this.classList.add('dashicons-visibility');
		} else {
			keyField.type = "password";
			this.classList.remove('dashicons-visibility');
			this.classList.add('dashicons-hidden');
		}
	});
</script>