<?php
/**
 * Pushes detected FAQs to /v1/catalog/faqs.
 *
 * FAQs get their own lane rather than travelling as content because store_faqs
 * carries a per-row embedding and is retrieved by cosine similarity, and
 * because it feeds the policy fallthrough that stops the assistant claiming it
 * has no shipping details when the store plainly does.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * FAQ sync.
 */
class Idea89_Faq_Syncer {

	const BATCH_SIZE         = 100;
	const MAX_PAGES_SCANNED  = 25;
	const MAX_POSTS_PER_TYPE = 200;

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
	 * FAQ detector.
	 *
	 * @var Idea89_Faq_Detector
	 */
	private $detector;

	/**
	 * Constructor.
	 *
	 * @param Idea89_Config       $config   Configuration reader.
	 * @param Idea89_Client       $client   API client.
	 * @param Idea89_Faq_Detector $detector FAQ detector.
	 */
	public function __construct( Idea89_Config $config, Idea89_Client $client, Idea89_Faq_Detector $detector ) {
		$this->config   = $config;
		$this->client   = $client;
		$this->detector = $detector;
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
	 * Renders a post's content through `the_content`, falling back to the raw
	 * content if a third-party shortcode/block callback throws.
	 *
	 * Catches Throwable, not Exception: in PHP 7+ a malformed callback raises
	 * Error/TypeError, which does not extend Exception. Without this, one
	 * misbehaving filter on one FAQ post or page would fatal the whole job —
	 * this runs from an Action Scheduler handler, where nothing may throw.
	 * Same pattern as Idea89_Content_Syncer::pages().
	 *
	 * @param WP_Post $post Post or page being rendered.
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
	 * Splits pairs into API-sized batches.
	 *
	 * @param array $pairs Pairs.
	 * @param int   $size  Batch size.
	 * @return array
	 */
	public static function chunk_pairs( array $pairs, $size ) {
		if ( empty( $pairs ) ) {
			return array();
		}
		return array_chunk( $pairs, max( 1, (int) $size ) );
	}

	/**
	 * Reports which FAQ sources exist on this site.
	 *
	 * Rendered in the settings screen so the merchant can correct a wrong guess
	 * rather than having detection silently decide for them.
	 *
	 * @return array{post_types: string[], html_pages: int[]}
	 */
	public function detect_sources() {
		$post_types = array();
		foreach ( Idea89_Faq_Detector::known_faq_post_types() as $type ) {
			if ( post_type_exists( $type ) ) {
				$post_types[] = $type;
			}
		}

		$html_pages = array();
		$candidates = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_PAGES_SCANNED,
				's'              => 'faq',
				'fields'         => 'ids',
			)
		);
		if ( is_array( $candidates ) ) {
			$html_pages = array_map( 'intval', $candidates );
		}

		return array(
			'post_types' => $post_types,
			'html_pages' => $html_pages,
		);
	}

	/**
	 * Detects and sends every FAQ the site exposes.
	 *
	 * Upsert only. Withdrawal is deferred — see the note at the end of this
	 * method for why it cannot be done safely yet.
	 *
	 * @return bool
	 */
	public function sync_all() {
		if ( ! $this->config->is_configured() ) {
			return false;
		}
		if ( ! get_option( 'idea89_sync_faqs', true ) ) {
			return true;
		}

		$pairs   = array();
		$sources = $this->detect_sources();

		// Whether this run actually saw everything. Reported below, and the
		// precondition any future prune will need: pruning against a partial
		// list deletes FAQs that really do still exist upstream.
		$complete = true;

		// 1. FAQ custom post types: title is the question, content the answer.
		foreach ( $sources['post_types'] as $post_type ) {
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => self::MAX_POSTS_PER_TYPE,
					'has_password'   => false,
				)
			);
			if ( ! is_array( $posts ) ) {
				// The query failed. We do not know what we did not see.
				$complete = false;
				continue;
			}
			if ( count( $posts ) >= self::MAX_POSTS_PER_TYPE ) {
				// Hit the page size, so there may be more beyond it.
				$complete = false;
			}
			foreach ( $posts as $post ) {
				if ( Idea89_Content_Syncer::is_noindexed( $post->ID ) ) {
					continue;
				}
				$pairs[] = array(
					'question' => $post->post_title,
					'answer'   => Idea89_Content_Syncer::extract_body( $this->render_content( $post ) ),
				);
			}
		}

		// 2. JSON-LD and accordion markup on pages that look like FAQs.
		if ( count( $sources['html_pages'] ) >= self::MAX_PAGES_SCANNED ) {
			// detect_sources() caps its search at MAX_PAGES_SCANNED, so a site at
			// the cap may have FAQ pages this run never looked at.
			$complete = false;
		}
		foreach ( $sources['html_pages'] as $page_id ) {
			$post = get_post( $page_id );
			if ( ! $post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
				continue;
			}
			if ( Idea89_Content_Syncer::is_noindexed( $post->ID ) ) {
				continue;
			}
			$rendered = $this->render_content( $post );
			$pairs    = array_merge( $pairs, Idea89_Faq_Detector::detect_in_html( $rendered ) );
		}

		$pairs = Idea89_Faq_Detector::normalise( $pairs );
		if ( empty( $pairs ) ) {
			return true;
		}

		if ( ! $complete ) {
			// Worth saying out loud on its own account: a site with more than
			// MAX_POSTS_PER_TYPE FAQ posts, or more FAQ-ish pages than
			// MAX_PAGES_SCANNED, is silently syncing only part of its FAQs and
			// the merchant has no other way to find that out. This is also the
			// signal the deferred prune below will need, so it stays derived
			// rather than being rediscovered later.
			$this->log( 'faq enumeration was incomplete — some FAQs were not seen this run' );
		}

		$ok = true;
		foreach ( self::chunk_pairs( $pairs, self::BATCH_SIZE ) as $batch ) {
			if ( ! $this->client->sync_faqs( $batch ) ) {
				$ok = false;
			}
		}

		// FAQ withdrawal is DEFERRED, deliberately, and this is where it would go.
		//
		// An FAQ post that is deleted, unpublished or marked noindex is still
		// answered from indefinitely, because /v1/catalog/faqs is upsert-only.
		// The obvious fix — send the complete current set and have the API
		// delete everything else for the store — cannot be used, because this
		// plugin is not the only writer to store_faqs. Four things write there:
		// this lane, the merchant's manual add and bulk import in the dashboard,
		// and the self-serve onboarding scraper. The table is keyed on the
		// normalised question with no record of who authored a row, so "delete
		// everything not in my list" means "delete every FAQ the merchant typed
		// by hand and every FAQ onboarding scraped", which exact-string matching
		// guarantees will almost never coincide with a WordPress FAQ title.
		//
		// That is unrecoverable data loss triggered by simply installing the
		// plugin: idea89_sync_faqs defaults to on, and the daily reconcile runs
		// about an hour after activation. A stale FAQ is a visible, fixable
		// wrong answer; a deleted one the merchant wrote is gone.
		//
		// Idea89_Client::prune_faqs() and POST /v1/catalog/faqs/prune are both
		// implemented, tested and correct. They stay unused until store_faqs can
		// tell plugin-authored rows from merchant- and onboarding-authored ones
		// (a `source` column), at which point the prune is scoped to this
		// plugin's own rows and this becomes safe to call.
		return $ok;
	}
}
