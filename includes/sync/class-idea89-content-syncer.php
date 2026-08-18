<?php
/**
 * Syncs categories, pages and store info to /v1/catalog/content.
 *
 * These three types are prompt-injected rather than retrieved, and the prompt
 * builder caps pages at twelve. That is fine for a handful of policy pages,
 * which is what belongs here. Blog posts and other high-volume content go
 * through the document lane instead, where they are embedded and retrieved.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Content sync.
 */
class Idea89_Content_Syncer {

	const MAX_BODY  = 10000;
	const MAX_TITLE = 500;
	const MAX_ITEMS = 500;

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
	 * Truncates to a character count, not a byte count.
	 *
	 * The single truncation helper for the whole plugin. Every lane — catalogue,
	 * content, FAQ, document — routes its clamping through here, because the
	 * failure mode is shared and silent: PHP's substr() cuts by byte, so for
	 * multi-byte UTF-8 content (accented characters, curly quotes, currency
	 * symbols) a byte cut can land inside a character and leave an invalid UTF-8
	 * tail. wp_json_encode() then returns false — not an error, not an exception —
	 * and Idea89_Client::post() skips the HTTP call entirely. One clipped review
	 * excerpt would silently drop all 100 products in the batch.
	 *
	 * mb_substr() cuts on character boundaries, matching the API's char_length()
	 * CHECK constraints and Zod's .max(), which both count characters.
	 *
	 * mbstring is effectively always available: it is a native extension in
	 * virtually every WordPress hosting environment, and WordPress itself
	 * polyfills mb_substr()/mb_strlen() in wp-includes/compat.php when it is not.
	 * The function_exists() guard is cheap insurance, not an expected path.
	 *
	 * @param string $text      Candidate text.
	 * @param int    $max_chars Maximum character count.
	 * @return string
	 */
	public static function truncate( $text, $max_chars ) {
		$text      = (string) $text;
		$max_chars = (int) $max_chars;

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text, 'UTF-8' ) > $max_chars ? mb_substr( $text, 0, $max_chars, 'UTF-8' ) : $text;
		}

		return strlen( $text ) > $max_chars ? substr( $text, 0, $max_chars ) : $text;
	}

	/**
	 * True when the merchant has told search engines not to index this post.
	 *
	 * The single noindex check for the whole plugin. Content a merchant
	 * deliberately hid from search engines must not be quoted back to shoppers by
	 * the assistant, and that rule has to hold on every lane that reads post
	 * bodies — documents, pages in the content lane, and both FAQ sources. It
	 * lives here, next to extract_body(), because every one of those lanes
	 * already calls into this class to turn a post into text.
	 *
	 * Yoast stores a discrete flag. Rank Math stores robots directives in
	 * `rank_math_robots` as an array (e.g. array('noindex')); older and other
	 * versions have been seen using a discrete flag as well, so both shapes are
	 * checked. A missed noindex silently publishes content the merchant hid; the
	 * cost of being thorough is a couple of meta reads per post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_noindexed( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true;
		}

		$rank_math = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( is_array( $rank_math ) && in_array( 'noindex', $rank_math, true ) ) {
			return true;
		}

		if ( '1' === (string) get_post_meta( $post_id, 'rank_math_robots_noindex', true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Turns raw post content into plain text within the API's length limit.
	 *
	 * @param string $raw_content Raw post content.
	 * @return string
	 */
	public static function extract_body( $raw_content ) {
		$text = strip_shortcodes( (string) $raw_content );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( (string) $text );

		return self::truncate( $text, self::MAX_BODY );
	}

	/**
	 * Clamps a title to something the API will accept.
	 *
	 * The API requires a non-empty title (1-500 chars) and validates a batch
	 * in one pass, so one empty or oversized title would 400 every item in
	 * the request, not just its own. A blank title is realistic — a merchant
	 * can save a page with the title field cleared — so it gets a generated
	 * fallback rather than taking the whole sync down with it.
	 *
	 * @param string $title    Candidate title.
	 * @param string $fallback Used when $title is empty after trimming.
	 * @return string
	 */
	public static function safe_title( $title, $fallback ) {
		$title = trim( (string) $title );
		if ( '' === $title ) {
			$title = $fallback;
		}
		return self::truncate( $title, self::MAX_TITLE );
	}

	/**
	 * Reduces a permalink-ish value to a string the API will accept, or null.
	 *
	 * `get_term_link()` returns a WP_Error on failure and `get_permalink()`
	 * can return false; forwarding either through wp_json_encode() would ship a
	 * malformed `url` field that 400s the whole batch. Anything that is not
	 * a non-empty string is dropped rather than forwarded — the item is still
	 * sent, just without a link, which matches "an omitted fact is correct."
	 *
	 * Being a string is not enough. The API validates with Zod's .url(), which
	 * needs a scheme and a host, so a protocol-relative "//cdn.example/x.jpg" —
	 * routinely produced by CDN and image-offload plugins filtering permalinks
	 * and attachment URLs — passes a bare string check here and then 400s the
	 * whole batch at the API. An http/https scheme plus a host is required
	 * instead; anything else drops the key.
	 *
	 * The check is a regex rather than wp_parse_url() so this stays usable from
	 * every lane without pulling in another WordPress dependency, and so a
	 * scheme like javascript: or data: can never reach the payload.
	 *
	 * @param mixed $value Candidate URL.
	 * @return string|null
	 */
	public static function safe_url( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		// Scheme must be http/https, and something host-shaped must follow it.
		if ( ! preg_match( '#^https?://[^\s/?\#]+#i', $value ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * The external_id a page is stored under in the content lane.
	 *
	 * One function so the syncer and the withdrawal hooks cannot disagree about
	 * the key — a delete that names the wrong id silently does nothing.
	 *
	 * @param int $post_id Page ID.
	 * @return string
	 */
	public static function page_external_id( $post_id ) {
		return 'page_' . (int) $post_id;
	}

	/**
	 * Whether a page may be sent to the content lane.
	 *
	 * Both the syncer and the withdrawal hooks ask this one question, so a page
	 * can never be eligible enough to sync but not eligible enough to keep (or
	 * the reverse). This is the content-lane twin of
	 * Idea89_Document_Syncer::should_index().
	 *
	 * @param object $post Post object.
	 * @return bool
	 */
	public static function page_is_eligible( $post ) {
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return false;
		}
		if ( 'page' !== $post->post_type ) {
			return false;
		}
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		if ( ! empty( $post->post_password ) ) {
			return false;
		}
		if ( self::is_noindexed( $post->ID ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Builds the single store_info item from whatever facts are available.
	 *
	 * Only facts that actually exist are included: inventing a contact address
	 * the merchant never set would put a wrong answer in the assistant's mouth.
	 *
	 * @param array $facts Keys: name, currency, email, url.
	 * @return array
	 */
	public static function build_store_info( array $facts ) {
		$parts = array();

		if ( ! empty( $facts['name'] ) ) {
			$parts[] = sprintf( 'Store name: %s.', $facts['name'] );
		}
		if ( ! empty( $facts['currency'] ) ) {
			$parts[] = sprintf( 'Prices are shown in %s.', $facts['currency'] );
		}
		if ( ! empty( $facts['email'] ) ) {
			$parts[] = sprintf( 'Contact email: %s.', $facts['email'] );
		}
		if ( ! empty( $facts['url'] ) ) {
			$parts[] = sprintf( 'Website: %s.', $facts['url'] );
		}

		$item = array(
			'type'        => 'store_info',
			'external_id' => 'store',
			'title'       => self::safe_title( isset( $facts['name'] ) ? $facts['name'] : '', 'Store' ),
			'body'        => implode( ' ', $parts ),
		);

		$url = self::safe_url( isset( $facts['url'] ) ? $facts['url'] : null );
		if ( null !== $url ) {
			$item['url'] = $url;
		}

		return $item;
	}

	/**
	 * Sends categories, pages and store info.
	 *
	 * @return bool
	 */
	public function sync_all() {
		if ( ! $this->config->is_configured() ) {
			$this->log( 'sync_all skipped — no API key configured' );
			return false;
		}

		$items = array();

		if ( get_option( 'idea89_sync_categories', true ) ) {
			$items = array_merge( $items, $this->categories() );
		}
		if ( get_option( 'idea89_sync_pages', true ) ) {
			$items = array_merge( $items, $this->pages() );
		}
		if ( get_option( 'idea89_sync_store_info', true ) ) {
			$items[] = self::build_store_info(
				array(
					'name'     => get_bloginfo( 'name' ),
					'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
					'email'    => get_option( 'admin_email', '' ),
					'url'      => home_url(),
				)
			);
		}

		if ( empty( $items ) ) {
			return true;
		}

		return $this->client->sync_content( array_slice( $items, 0, self::MAX_ITEMS ) );
	}

	/**
	 * Withdraws one content item that no longer belongs in the store.
	 *
	 * The content lane has no per-item upsert — items are pushed as one batch on
	 * a full sync — so this deliberately has no "re-add" twin. A page that
	 * becomes eligible again is picked up by the next content sync. A page that
	 * becomes INELIGIBLE cannot wait for that: chat.ts prompt-injects every
	 * store_content row on every turn with no publication check, so until the
	 * row is gone the assistant keeps quoting a page the merchant took down.
	 *
	 * @param string $external_id Content external id, e.g. page_12.
	 * @param string $type        Content type.
	 * @return bool
	 */
	public function delete_item( $external_id, $type = 'cms_page' ) {
		if ( ! $this->config->is_configured() ) {
			return false;
		}

		$external_id = (string) $external_id;
		if ( '' === $external_id ) {
			return false;
		}

		return $this->client->delete_content( (string) $type, array( $external_id ) );
	}

	/**
	 * Product categories as content items.
	 *
	 * @return array
	 */
	private function categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$items = array();
		foreach ( $terms as $term ) {
			$item = array(
				'type'        => 'category',
				'external_id' => 'cat_' . $term->term_id,
				'title'       => self::safe_title( $term->name, 'Category' ),
				'body'        => self::extract_body( $term->description ),
			);

			$url = self::safe_url( get_term_link( $term ) );
			if ( null !== $url ) {
				$item['url'] = $url;
			}

			$items[] = $item;
		}
		return $items;
	}

	/**
	 * Published pages as content items.
	 *
	 * Password-protected and private pages are excluded: content that is not
	 * public must not reach the assistant, which would happily quote it to
	 * any shopper. `post_status => 'publish'` already excludes private pages
	 * (their status is 'private', not 'publish'); `has_password => false`
	 * excludes password-protected ones explicitly. Pages the merchant marked
	 * noindex are excluded for the same reason, via the shared is_noindexed().
	 *
	 * @return array
	 */
	private function pages() {
		$pages = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'posts_per_page'   => 100,
				'has_password'     => false,
				'suppress_filters' => false,
			)
		);

		if ( ! is_array( $pages ) ) {
			return array();
		}

		$items = array();
		foreach ( $pages as $page ) {
			// The query already filters on status and password; asking the shared
			// predicate anyway is what stops this lane and the withdrawal hooks
			// drifting apart, and it is what applies the noindex rule.
			if ( ! self::page_is_eligible( $page ) ) {
				continue;
			}

			$rendered = $page->post_content;
			try {
				// Expands blocks and shortcodes to what a shopper actually sees,
				// same as the theme would render. A shortcode/block callback from
				// an unrelated plugin throwing must not take the whole batch down
				// with it, so fall back to the raw content instead of losing the
				// page entirely.
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, applied to render post content; not a hook this plugin defines.
				$rendered = apply_filters( 'the_content', $rendered );
			} catch ( Throwable $e ) {
				$this->log( 'the_content filter failed for page ' . $page->ID . ': ' . $e->getMessage() );
			}

			$body = self::extract_body( $rendered );
			if ( '' === $body ) {
				continue;
			}

			$item = array(
				'type'        => 'cms_page',
				'external_id' => self::page_external_id( $page->ID ),
				'title'       => self::safe_title( $page->post_title, 'Page' ),
				'body'        => $body,
			);

			$url = self::safe_url( get_permalink( $page->ID ) );
			if ( null !== $url ) {
				$item['url'] = $url;
			}

			$items[] = $item;
		}
		return $items;
	}
}
