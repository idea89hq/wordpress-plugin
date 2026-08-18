<?php
/**
 * Pushes posts and selected custom post types to /v1/catalog/documents.
 *
 * Documents are chunked and embedded server-side and retrieved by cosine
 * similarity per turn. That is what makes a blog with hundreds of posts
 * answerable, where the prompt-injected content lane caps out at twelve items.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Document sync.
 */
class Idea89_Document_Syncer {

	const BATCH_SIZE = 50;
	const MAX_POSTS  = 1000;

	/**
	 * Post types never offered for document sync.
	 *
	 * Products belong to the catalogue lane; indexing them here as well would
	 * duplicate the entire catalogue into the retrieval corpus and let stale
	 * copies compete with live product data.
	 *
	 * @var string[]
	 */
	private static $excluded = array(
		'product',
		'product_variation',
		'shop_order',
		'shop_coupon',
		'attachment',
		'revision',
		'nav_menu_item',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_navigation',
		'scheduled-action',
	);

	/**
	 * Configuration reader.
	 *
	 * @var Idea89_Config
	 */
	private $config;

	/**
	 * API client.
	 *
	 * @var Idea89_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Idea89_Config $config Configuration reader.
	 * @param Idea89_Client $client API client.
	 */
	public function __construct( Idea89_Config $config, Idea89_Client $client ) {
		$this->config = $config;
		$this->client = $client;
	}

	/**
	 * Logs when WP_DEBUG is on.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'IDEA89: ' . $message );
		}
	}

	/**
	 * Filters a list of public post types down to those we may index.
	 *
	 * @param string[] $public_types Registered public post types.
	 * @return string[]
	 */
	public static function available_post_types( array $public_types ) {
		return array_values( array_diff( $public_types, self::$excluded ) );
	}

	/**
	 * The post types the merchant has chosen, defaulting to posts only.
	 *
	 * @return string[]
	 */
	public function synced_post_types() {
		$available = self::available_post_types( array_values( (array) get_post_types( array( 'public' => true ) ) ) );
		$saved     = get_option( 'idea89_sync_post_types', false );

		if ( ! is_array( $saved ) ) {
			return in_array( 'post', $available, true ) ? array( 'post' ) : array();
		}

		// Intersect rather than trust: a stale or tampered option must never
		// re-admit an excluded type.
		return array_values( array_intersect( $saved, $available ) );
	}

	/**
	 * Whether a post may be indexed.
	 *
	 * @param object $post Post object.
	 * @return bool
	 */
	public static function should_index( $post ) {
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return false;
		}
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		if ( ! empty( $post->post_password ) ) {
			return false;
		}
		if ( '' === trim( (string) $post->post_content ) ) {
			return false;
		}
		// Respect an explicit noindex from Yoast or Rank Math: content the
		// merchant hid from search engines should not be quoted by the
		// assistant to any anonymous shopper. The check itself lives on
		// Idea89_Content_Syncer so the content and FAQ lanes enforce exactly the
		// same rule from exactly the same code — it used to live here alone,
		// and the other three lanes quietly did not have it.
		if ( Idea89_Content_Syncer::is_noindexed( $post->ID ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Renders a post's content through `the_content`, falling back to the raw
	 * content if a third-party shortcode/block callback throws.
	 *
	 * Catches Throwable, not Exception: in PHP 7+ a malformed callback raises
	 * Error/TypeError, which does not extend Exception. Without this, one
	 * misbehaving filter on one post would fatal the whole scheduler job —
	 * this runs from an Action Scheduler handler, where nothing may throw.
	 * Same pattern as Idea89_Faq_Syncer::render_content() and
	 * Idea89_Content_Syncer::pages().
	 *
	 * @param object $post Post object.
	 * @return string
	 */
	private function render_content( $post ) {
		$rendered = $post->post_content;
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, applied to render post content; not a hook this plugin defines.
			$rendered = apply_filters( 'the_content', $rendered );
		} catch ( Throwable $e ) {
			$this->log( 'the_content filter failed for post ' . $post->ID . ': ' . $e->getMessage() );
		}
		return $rendered;
	}

	/**
	 * Serialises one post into the document shape.
	 *
	 * Title and url go through the same safe_title()/safe_url() the content
	 * syncer uses: a blank title or a get_permalink() failure (it returns
	 * false, not a string) must not 400 the whole batch of up to 50 documents.
	 *
	 * @param object $post Post object.
	 * @return array
	 */
	private function serialize( $post ) {
		$document = array(
			'doc_type'    => (string) $post->post_type,
			'external_id' => (string) $post->ID,
			'title'       => Idea89_Content_Syncer::safe_title( $post->post_title, 'Untitled' ),
			'body'        => Idea89_Content_Syncer::extract_body( $this->render_content( $post ) ),
		);

		$url = Idea89_Content_Syncer::safe_url( get_permalink( $post->ID ) );
		if ( null !== $url ) {
			$document['url'] = $url;
		}

		return $document;
	}

	/**
	 * Indexes every eligible post of every selected type.
	 *
	 * @return bool
	 */
	public function sync_all() {
		if ( ! $this->config->is_configured() ) {
			$this->log( 'sync_all skipped — no API key configured' );
			return false;
		}

		$types = $this->synced_post_types();
		if ( empty( $types ) ) {
			return true;
		}

		$ok = true;

		foreach ( $types as $post_type ) {
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => self::MAX_POSTS,
					'has_password'   => false,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			if ( ! is_array( $posts ) || empty( $posts ) ) {
				continue;
			}

			$documents = array();
			foreach ( $posts as $post ) {
				if ( ! self::should_index( $post ) ) {
					continue;
				}
				$document = $this->serialize( $post );
				if ( '' === $document['body'] ) {
					continue;
				}
				$documents[] = $document;
			}

			foreach ( array_chunk( $documents, self::BATCH_SIZE ) as $batch ) {
				if ( ! $this->client->index_documents( $batch ) ) {
					$ok = false;
					$this->log( 'index_documents batch rejected for post type ' . $post_type );
				}
			}
		}

		return $ok;
	}

	/**
	 * Indexes a single post, or removes it when it is no longer eligible.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function sync_post( $post_id ) {
		if ( ! $this->config->is_configured() ) {
			return false;
		}

		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return false;
		}

		if ( ! in_array( $post->post_type, $this->synced_post_types(), true ) ) {
			return true;
		}

		// An unpublished, password-protected, trashed or noindexed post must
		// be withdrawn, not merely left un-updated — deleted or hidden text
		// would otherwise keep surfacing in shopper answers forever.
		if ( ! self::should_index( $post ) ) {
			return $this->client->delete_documents( $post->post_type, array( (string) $post->ID ) );
		}

		$document = $this->serialize( $post );
		if ( '' === $document['body'] ) {
			// The rendered body came out empty even though should_index()
			// passed (e.g. content that is only shortcodes a plugin no
			// longer resolves) — same withdrawal, not a silent no-op.
			return $this->client->delete_documents( $post->post_type, array( (string) $post->ID ) );
		}

		return $this->client->index_documents( array( $document ) );
	}

	/**
	 * Removes a post from the index.
	 *
	 * The caller is expected to have captured the post type before the post
	 * row was actually removed (see Idea89_Hooks::on_post_deleted()) — once a
	 * force-delete has run, get_post_type() here would find nothing.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function delete_post( $post_id ) {
		if ( ! $this->config->is_configured() ) {
			return false;
		}

		$post_type = get_post_type( (int) $post_id );
		if ( ! $post_type ) {
			return false;
		}

		return $this->client->delete_documents( $post_type, array( (string) (int) $post_id ) );
	}
}
