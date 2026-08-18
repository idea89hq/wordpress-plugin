<?php
/**
 * Admin settings screen, built on the WordPress Settings API.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the IDEA89 admin menu and its settings.
 */
class Idea89_Admin_Settings {

	const PAGE_SLUG    = 'idea89-assistant';
	const OPTION_GROUP = 'idea89_settings';

	/**
	 * Wires the menu and settings registration.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the top-level IDEA89 menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'IDEA89 Assistant', 'idea89-ai-shopping-assistant' ),
			__( 'IDEA89', 'idea89-ai-shopping-assistant' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-format-chat',
			56
		);
	}

	/**
	 * Loads the admin script only on our own screen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'idea89-admin',
			IDEA89_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			IDEA89_VERSION
		);

		wp_enqueue_script(
			'idea89-admin',
			IDEA89_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			IDEA89_VERSION,
			true
		);

		wp_localize_script(
			'idea89-admin',
			'idea89Admin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'idea89_admin' ),
				'testing'   => __( 'Testing…', 'idea89-ai-shopping-assistant' ),
				'syncing'   => __( 'Starting sync…', 'idea89-ai-shopping-assistant' ),
				'testLabel' => __( 'Test connection', 'idea89-ai-shopping-assistant' ),
				'syncLabel' => __( 'Sync now', 'idea89-ai-shopping-assistant' ),
				'failed'    => __( 'Request failed. Check your connection and try again.', 'idea89-ai-shopping-assistant' ),
			)
		);
	}

	/**
	 * Normalises the API URL. Only http and https are accepted; anything else
	 * would be fetched server-side by the client.
	 *
	 * @param string $value Raw input.
	 * @return string
	 */
	public static function sanitize_api_url( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$scheme = wp_parse_url( $value, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		return rtrim( esc_url_raw( $value ), '/' );
	}

	/**
	 * Accepts a six-digit hex colour and nothing else. The value is
	 * interpolated into a storefront data attribute.
	 *
	 * @param string $value Raw input.
	 * @return string
	 */
	public static function sanitize_brand_color( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? $value : '';
	}

	/**
	 * Constrains the widget position to the allow-list.
	 *
	 * @param string $value Raw input.
	 * @return string
	 */
	public static function sanitize_position( $value ) {
		$value = trim( (string) $value );
		return in_array( $value, array( 'bottom-right', 'bottom-left' ), true ) ? $value : 'bottom-right';
	}

	/**
	 * Constrains the submitted post types to those actually selectable.
	 *
	 * Intersecting against available_post_types() rather than trusting the
	 * submission means a tampered form can never re-admit products.
	 *
	 * @param mixed $value Raw submission.
	 * @return string[]
	 */
	public static function sanitize_post_types( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$available = Idea89_Document_Syncer::available_post_types(
			array_values( (array) get_post_types( array( 'public' => true ) ) )
		);

		$clean = array_map( 'sanitize_key', $value );

		return array_values( array_intersect( $clean, $available ) );
	}

	/**
	 * Registers every option with its sanitiser.
	 *
	 * @return void
	 */
	public function register_settings() {
		$options = array(
			'idea89_enabled'         => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'idea89_api_key'         => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				// Kept out of the autoloaded options cache: Idea89_Config's
				// docblock states the key lives in wp_options with autoload
				// disabled, and this is where that option is first created.
				'autoload'          => false,
			),
			'idea89_api_url'         => array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_api_url' ),
			),
			'idea89_assistant_name'  => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'idea89_store_context'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'idea89_widget_position' => array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_position' ),
			),
			'idea89_brand_color'     => array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_brand_color' ),
			),
			// Default true: a fresh install should sync categories, pages, store
			// info and FAQs alongside products, not just products. The syncers
			// themselves already read these with get_option( $name, true ) — this
			// 'default' is what makes render_field()'s checkbox display agree
			// with that, which matters because it is the ONLY thing standing
			// between a fresh install and the unchecked-checkbox bug: without a
			// registered default, get_option( $name ) (no explicit fallback)
			// returns WordPress's own false, the checkbox renders unchecked, and
			// the very first settings save — e.g. just to paste in the API key —
			// would persist that false and turn content sync off for good.
			'idea89_sync_categories' => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			),
			'idea89_sync_pages'      => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			),
			'idea89_sync_store_info' => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			),
			'idea89_sync_faqs'       => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			),
			'idea89_sync_post_types' => array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_post_types' ),
			),
		);

		foreach ( $options as $name => $args ) {
			// autoload is left at WordPress's default for these small values,
			// except the API key, which is registered with autoload off above.
			register_setting( self::OPTION_GROUP, $name, $args );
		}

		add_settings_section(
			'idea89_general',
			__( 'Connection', 'idea89-ai-shopping-assistant' ),
			array( $this, 'render_general_intro' ),
			self::PAGE_SLUG
		);

		$this->add_field( 'idea89_enabled', __( 'Enable assistant', 'idea89-ai-shopping-assistant' ), 'checkbox', 'idea89_general' );
		$this->add_field( 'idea89_api_key', __( 'API key', 'idea89-ai-shopping-assistant' ), 'password', 'idea89_general' );
		$this->add_field( 'idea89_api_url', __( 'API URL', 'idea89-ai-shopping-assistant' ), 'text', 'idea89_general' );

		add_settings_section(
			'idea89_appearance',
			__( 'Appearance', 'idea89-ai-shopping-assistant' ),
			'__return_false',
			self::PAGE_SLUG
		);

		$this->add_field( 'idea89_assistant_name', __( 'Assistant name', 'idea89-ai-shopping-assistant' ), 'text', 'idea89_appearance' );
		$this->add_field( 'idea89_widget_position', __( 'Position', 'idea89-ai-shopping-assistant' ), 'position', 'idea89_appearance' );
		$this->add_field( 'idea89_brand_color', __( 'Brand colour', 'idea89-ai-shopping-assistant' ), 'text', 'idea89_appearance' );
		$this->add_field( 'idea89_store_context', __( 'Store context', 'idea89-ai-shopping-assistant' ), 'textarea', 'idea89_appearance' );

		add_settings_section(
			'idea89_content',
			__( 'Content sync', 'idea89-ai-shopping-assistant' ),
			array( $this, 'render_content_intro' ),
			self::PAGE_SLUG
		);

		$this->add_field( 'idea89_sync_categories', __( 'Product categories', 'idea89-ai-shopping-assistant' ), 'checkbox', 'idea89_content' );
		$this->add_field( 'idea89_sync_pages', __( 'Pages', 'idea89-ai-shopping-assistant' ), 'checkbox', 'idea89_content' );
		$this->add_field( 'idea89_sync_store_info', __( 'Store details', 'idea89-ai-shopping-assistant' ), 'checkbox', 'idea89_content' );
		$this->add_field( 'idea89_sync_faqs', __( 'FAQs', 'idea89-ai-shopping-assistant' ), 'checkbox', 'idea89_content' );

		add_settings_field(
			'idea89_sync_post_types',
			__( 'Posts and other content', 'idea89-ai-shopping-assistant' ),
			array( $this, 'render_post_types_field' ),
			self::PAGE_SLUG,
			'idea89_content'
		);
	}

	/**
	 * Intro copy for the content section: what the assistant reads, an honest
	 * note on what unchecking a type does (and does not) do, and what FAQ
	 * detection found.
	 *
	 * @return void
	 */
	public function render_content_intro() {
		echo '<p>' . esc_html__( 'Choose what the assistant can read. Posts and custom content are indexed and searched, so a large blog is fine.', 'idea89-ai-shopping-assistant' ) . '</p>';

		// Honesty requirement: a post type (or category/page/store-info sync)
		// switched off here is not retroactively pruned from the index —
		// Idea89_Document_Syncer::synced_post_types() and
		// Idea89_Content_Syncer::sync_all() both simply stop *sending* that
		// type; nothing yet calls delete_documents() for content that drops
		// out of the selection. The merchant needs to know that before they
		// assume unchecking a box makes the assistant forget something.
		echo '<p class="description">' . esc_html__( 'Turning a content type off only stops future syncing. Documents already indexed from that type are not automatically removed, and can keep being quoted to shoppers until that support ships.', 'idea89-ai-shopping-assistant' ) . '</p>';

		$sources = idea89_faq_syncer()->detect_sources();

		if ( empty( $sources['post_types'] ) && empty( $sources['html_pages'] ) ) {
			// Honesty requirement: FAQ detection is deliberately narrow — FAQ
			// plugin post types, schema.org FAQPage JSON-LD, and native
			// <details> blocks only (see Idea89_Faq_Detector). It does not
			// guess at theme-specific accordion markup built from plain divs,
			// because a wrong guess produces a mangled FAQ, which is worse
			// than none. A merchant whose FAQs live only in such an accordion
			// must be told plainly what was checked, not left assuming the
			// store simply has no FAQs.
			echo '<p><em>' . esc_html__( 'No FAQs detected yet. Detection checks FAQ plugin post types, FAQ schema markup (as emitted by Yoast and Rank Math), and native accordion (details/summary) blocks. It does not read theme-specific accordion markup built from plain divs, so a page whose FAQs live only in a theme accordion will show nothing here even though the FAQs exist. If that sounds like your site, add an FAQ block or a small FAQ plugin so the assistant can find them.', 'idea89-ai-shopping-assistant' ) . '</em></p>';
			return;
		}

		echo '<p><strong>' . esc_html__( 'FAQ sources detected:', 'idea89-ai-shopping-assistant' ) . '</strong> ';

		$labels = array();
		foreach ( $sources['post_types'] as $type ) {
			$labels[] = sprintf(
				/* translators: %s: post type slug */
				__( '%s post type', 'idea89-ai-shopping-assistant' ),
				$type
			);
		}
		if ( ! empty( $sources['html_pages'] ) ) {
			$labels[] = sprintf(
				/* translators: %d: number of pages */
				_n( '%d page with FAQ markup', '%d pages with FAQ markup', count( $sources['html_pages'] ), 'idea89-ai-shopping-assistant' ),
				count( $sources['html_pages'] )
			);
		}

		echo esc_html( implode( ', ', $labels ) ) . '</p>';

		// Same honesty point as above, phrased for the "we found something"
		// case: detection succeeding here is not proof that every FAQ on the
		// site was found, only that these particular sources were.
		echo '<p class="description">' . esc_html__( 'This does not include theme-specific accordion markup built from plain divs.', 'idea89-ai-shopping-assistant' ) . '</p>';
	}

	/**
	 * Renders the post-type checkbox list.
	 *
	 * @return void
	 */
	public function render_post_types_field() {
		$available = Idea89_Document_Syncer::available_post_types(
			array_values( (array) get_post_types( array( 'public' => true ) ) )
		);
		$selected  = idea89_document_syncer()->synced_post_types();

		if ( empty( $available ) ) {
			echo '<p>' . esc_html__( 'No eligible content types found.', 'idea89-ai-shopping-assistant' ) . '</p>';
			return;
		}

		// Same fix as the checkbox fields above (see render_field()), applied
		// to an array field: a hidden idea89_sync_post_types[] entry ahead of
		// the real checkboxes guarantees the key is present in $_POST even
		// when every box is unchecked. Without it, deselecting every type
		// would omit idea89_sync_post_types from the submission entirely, the
		// Settings API would never call sanitize_post_types() or
		// update_option(), and a merchant could never clear the selection
		// down to none.
		echo '<input type="hidden" name="idea89_sync_post_types[]" value="" />';

		foreach ( $available as $type ) {
			$object = get_post_type_object( $type );
			$label  = ( $object && isset( $object->labels->name ) ) ? $object->labels->name : $type;

			printf(
				'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="idea89_sync_post_types[]" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( $type ),
				checked( in_array( $type, $selected, true ), true, false ),
				esc_html( $label )
			);
		}

		echo '<p class="description">' . esc_html__( 'Products are always synced separately and are not listed here.', 'idea89-ai-shopping-assistant' ) . '</p>';
	}

	/**
	 * The fallback get_option() should use for a given option when the row
	 * does not exist yet, keyed by name so every reader agrees.
	 *
	 * Categories, pages, store info and FAQs default ON: a fresh install
	 * should sync everything alongside products, not just products —
	 * Idea89_Content_Syncer::sync_all() and Idea89_Faq_Syncer::sync_all()
	 * already read them as get_option( $name, true ). Before this, render_field()
	 * read the same four options with get_option( $name, '' ) — an empty-string
	 * default that renders the checkbox unchecked on a fresh install even
	 * though the syncers themselves would have treated the toggle as on. The
	 * first time a merchant saved the settings screen for any reason (e.g.
	 * just to paste in the API key), that wrong unchecked display got written
	 * back as an explicit "0" via the hidden-field trick in the checkbox
	 * branch below, permanently disabling content sync. Every other option
	 * keeps the previous empty-string default.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	private static function default_value( $name ) {
		$on_by_default = array(
			'idea89_sync_categories',
			'idea89_sync_pages',
			'idea89_sync_store_info',
			'idea89_sync_faqs',
		);
		return in_array( $name, $on_by_default, true ) ? true : '';
	}

	/**
	 * Registers one settings field.
	 *
	 * @param string $name    Option name.
	 * @param string $label   Field label.
	 * @param string $type    Field renderer type.
	 * @param string $section Section id.
	 * @return void
	 */
	private function add_field( $name, $label, $type, $section ) {
		add_settings_field(
			$name,
			$label,
			array( $this, 'render_field' ),
			self::PAGE_SLUG,
			$section,
			array(
				'name' => $name,
				'type' => $type,
			)
		);
	}

	/**
	 * Intro copy for the connection section.
	 *
	 * @return void
	 */
	public function render_general_intro() {
		echo '<p>' . esc_html__( 'Paste the API key from your IDEA89 dashboard. No data leaves this site until a key is saved.', 'idea89-ai-shopping-assistant' ) . '</p>';
	}

	/**
	 * Renders one field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_field( $args ) {
		$name  = $args['name'];
		$type  = $args['type'];
		$value = get_option( $name, self::default_value( $name ) );

		switch ( $type ) {
			case 'checkbox':
				// A hidden "0" ahead of the checkbox ensures the option name is
				// always present in $_POST. The WordPress Settings API only
				// calls update_option() — and therefore the sanitize callback —
				// for keys that are present in $_POST, so an unchecked box
				// (which browsers omit entirely) would otherwise leave the
				// previously saved value untouched instead of turning off.
				printf(
					'<input type="hidden" name="%1$s" value="0" />',
					esc_attr( $name )
				);
				printf(
					'<input type="checkbox" name="%1$s" value="1" %2$s />',
					esc_attr( $name ),
					checked( (bool) $value, true, false )
				);
				break;

			case 'textarea':
				printf(
					'<textarea name="%1$s" rows="5" class="large-text">%2$s</textarea>',
					esc_attr( $name ),
					esc_textarea( (string) $value )
				);
				break;

			case 'position':
				echo '<select name="' . esc_attr( $name ) . '">';
				foreach ( array(
					'bottom-right' => __( 'Bottom right', 'idea89-ai-shopping-assistant' ),
					'bottom-left'  => __( 'Bottom left', 'idea89-ai-shopping-assistant' ),
				) as $key => $label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $key ),
						selected( $value, $key, false ),
						esc_html( $label )
					);
				}
				echo '</select>';
				break;

			case 'password':
			case 'text':
			default:
				printf(
					'<input type="%1$s" name="%2$s" value="%3$s" class="regular-text" autocomplete="off" />',
					'password' === $type ? 'password' : 'text',
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;
		}
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'IDEA89 Assistant', 'idea89-ai-shopping-assistant' ); ?></h1>
			<?php $this->render_brand_strip(); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<h2><?php echo esc_html__( 'Actions', 'idea89-ai-shopping-assistant' ); ?></h2>
			<p>
				<button type="button" class="button" id="idea89-test-connection">
					<?php echo esc_html__( 'Test connection', 'idea89-ai-shopping-assistant' ); ?>
				</button>
				<button type="button" class="button button-primary" id="idea89-sync-now">
					<?php echo esc_html__( 'Sync now', 'idea89-ai-shopping-assistant' ); ?>
				</button>
			</p>
			<div id="idea89-action-result" role="status" aria-live="polite"></div>
		</div>
		<?php
	}

	/**
	 * The lightbulb mark, inlined so the strip renders without an asset
	 * round-trip and survives any admin theme.
	 *
	 * @return string
	 */
	private function brand_mark() {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="38" height="38"'
			. ' fill="none" stroke="#0b6b47" stroke-width="1.5" stroke-linecap="round"'
			. ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. '<g><line x1="12" y1="1" x2="12" y2="3"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>'
			. '<line x1="1" y1="12" x2="3" y2="12"/><line x1="19.78" y1="4.22" x2="18.36" y2="5.64"/>'
			. '<line x1="21" y1="12" x2="23" y2="12"/></g>'
			. '<path d="M9 21h6M10 17h4M12 3a6 6 0 0 0-4 10.5V16a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-2.5A6 6 0 0 0 12 3z"/>'
			. '<rect x="9" y="17" width="6" height="2" rx="1" fill="#4a5552" stroke="none"/>'
			. '</svg>';
	}

	/**
	 * SVG tags and attributes permitted in the brand mark.
	 *
	 * The mark is a constant we author, but it still goes through wp_kses so
	 * the escaping is real rather than a suppressed warning.
	 *
	 * @return array
	 */
	private function brand_mark_allowed_html() {
		$shared = array(
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
		);

		return array(
			'svg'  => array_merge(
				$shared,
				array(
					'xmlns'       => true,
					'viewbox'     => true,
					'width'       => true,
					'height'      => true,
					'aria-hidden' => true,
					'focusable'   => true,
				)
			),
			'g'    => $shared,
			'path' => array_merge( $shared, array( 'd' => true ) ),
			'line' => array_merge(
				$shared,
				array(
					'x1' => true,
					'y1' => true,
					'x2' => true,
					'y2' => true,
				)
			),
			'rect' => array_merge(
				$shared,
				array(
					'x'      => true,
					'y'      => true,
					'width'  => true,
					'height' => true,
					'rx'     => true,
				)
			),
		);
	}

	/**
	 * Vendor brand strip shown above the settings.
	 *
	 * Mirrors the strip the Magento 2 module renders above its configuration
	 * section, so a merchant running both products sees one identity rather
	 * than two unrelated plugins.
	 *
	 * @return void
	 */
	private function render_brand_strip() {
		$links = array(
			array(
				'url'      => 'https://idea89.com/docs',
				'label'    => __( 'Documentation', 'idea89-ai-shopping-assistant' ),
				'external' => true,
			),
			array(
				'url'      => 'https://idea89.com',
				'label'    => __( 'Website', 'idea89-ai-shopping-assistant' ),
				'external' => true,
			),
			array(
				'url'      => 'mailto:support@idea89.com',
				'label'    => __( 'Support', 'idea89-ai-shopping-assistant' ),
				'external' => false,
			),
			array(
				'url'      => 'https://app.idea89.com',
				'label'    => __( 'Open dashboard', 'idea89-ai-shopping-assistant' ),
				'external' => true,
			),
		);
		?>
		<div class="idea89-brand">
			<a class="idea89-brand__mark"
				href="https://idea89.com"
				target="_blank"
				rel="noopener noreferrer"
				title="<?php echo esc_attr__( 'Visit idea89.com', 'idea89-ai-shopping-assistant' ); ?>">
				<?php echo wp_kses( $this->brand_mark(), $this->brand_mark_allowed_html() ); ?>
			</a>
			<div class="idea89-brand__body">
				<div class="idea89-brand__title">
					<?php echo esc_html__( 'IDEA89 — AI Shopping Assistant', 'idea89-ai-shopping-assistant' ); ?>
					<span class="idea89-brand__version">
						<?php
						printf(
							/* translators: %s: plugin version number */
							esc_html__( 'v%s', 'idea89-ai-shopping-assistant' ),
							esc_html( IDEA89_VERSION )
						);
						?>
					</span>
				</div>
				<div class="idea89-brand__tagline">
					<?php echo esc_html__( 'Your storefront, now fluent in shopper.', 'idea89-ai-shopping-assistant' ); ?>
				</div>
				<div class="idea89-brand__links">
					<?php foreach ( $links as $i => $link ) : ?>
						<?php if ( $i > 0 ) : ?>
							<span class="idea89-brand__sep">&middot;</span>
						<?php endif; ?>
						<a href="<?php echo esc_url( $link['url'] ); ?>"
							<?php echo $link['external'] ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
							<?php
							echo esc_html( $link['label'] );
							echo $link['external'] ? ' &#8599;' : '';
							?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}
}
