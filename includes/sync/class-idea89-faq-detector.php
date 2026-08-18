<?php
/**
 * Finds FAQ question and answer pairs in rendered WordPress content.
 *
 * WordPress has no native FAQ type, so detection tries three sources in
 * priority order:
 *
 *   1. schema.org FAQPage JSON-LD — what Yoast and Rank Math FAQ blocks emit.
 *      Already structured as pairs, so it is both the most reliable source and
 *      the most common one.
 *   2. details/summary and accordion blocks.
 *   3. Known FAQ custom post types, handled by the syncer.
 *
 * Everything here is pure string processing so it can be tested without
 * WordPress. This is the component most likely to meet markup we did not
 * anticipate, so it fails closed: anything it cannot parse confidently yields
 * no pairs rather than a garbled one. A missing FAQ is invisible to a
 * shopper; a mangled one is the assistant confidently quoting something the
 * merchant never wrote.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * FAQ extraction.
 */
class Idea89_Faq_Detector {

	/**
	 * Matches the API's `store_faqs` CHECK constraint (migration 0007):
	 * question <= 500 chars, answer <= 2000 chars. A longer value would 400/500
	 * the whole batch, not just this item.
	 */
	const MAX_QUESTION = 500;
	const MAX_ANSWER   = 2000;

	/**
	 * Post type slugs used by common FAQ plugins.
	 *
	 * @return string[]
	 */
	public static function known_faq_post_types() {
		return array( 'ufaq', 'faq', 'faqs', 'helpie_faq', 'sp_faq', 'epkb_post_type_1' );
	}

	/**
	 * Converts a raw HTML fragment to trimmed, single-spaced plain text.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function text( $html ) {
		$text = wp_strip_all_tags( (string) $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// A decoded &nbsp; becomes U+00A0, which PCRE's \s does not treat as
		// whitespace without the unicode modifier. Left alone it survives the
		// collapse and trim below and can leave a stray gap-less join or a
		// leading/trailing character that looks like a formatting bug.
		$text = str_replace( "\xC2\xA0", ' ', $text );
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * Truncates to a character count, not a byte count.
	 *
	 * The implementation and its reasoning now live on
	 * Idea89_Content_Syncer::truncate(), which is the plugin's one truncation
	 * helper. This lane solved the byte-cut problem first; the other three did
	 * not, so it was lifted out rather than copied a fourth time. Kept as a
	 * named method here because the detector reads better calling truncate()
	 * than a fully-qualified static on every line.
	 *
	 * @param string $text      Candidate text.
	 * @param int    $max_chars Maximum character count.
	 * @return string
	 */
	private static function truncate( $text, $max_chars ) {
		return Idea89_Content_Syncer::truncate( $text, $max_chars );
	}

	/**
	 * Walks a decoded JSON-LD structure and collects every FAQPage entity.
	 *
	 * Recurses unconditionally because FAQPage is commonly nested inside a
	 * graph wrapper (Yoast's "at-graph" property) rather than sitting at the
	 * top level, and a page can legitimately carry more than one FAQPage.
	 * json_decode()'s own depth limit (512 by default) already bounds how deep
	 * this recursion can go — a maliciously deep JSON-LD payload fails to
	 * decode at all and never reaches this method.
	 *
	 * @param mixed $node  Decoded JSON node.
	 * @param array $pairs Accumulator, passed by reference.
	 * @return void
	 */
	private static function collect_faq_nodes( $node, array &$pairs ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		$type        = isset( $node['@type'] ) ? $node['@type'] : null;
		$is_faq_page = ( 'FAQPage' === $type )
			|| ( is_array( $type ) && in_array( 'FAQPage', $type, true ) );

		if ( $is_faq_page && isset( $node['mainEntity'] ) && is_array( $node['mainEntity'] ) ) {
			$questions = $node['mainEntity'];

			// schema.org allows mainEntity to be a single Thing as well as a
			// list of them. A single Question decodes to an associative array
			// with its own 'name'/'@type' keys; without this check the foreach
			// below would iterate over those keys as if each were a separate
			// question.
			if ( isset( $questions['name'] ) || isset( $questions['@type'] ) ) {
				$questions = array( $questions );
			}

			foreach ( $questions as $question ) {
				if ( ! is_array( $question ) || empty( $question['name'] ) ) {
					continue;
				}

				$answer = '';
				if ( isset( $question['acceptedAnswer'] ) ) {
					if ( is_array( $question['acceptedAnswer'] ) && isset( $question['acceptedAnswer']['text'] ) ) {
						$answer = $question['acceptedAnswer']['text'];
					} elseif ( is_string( $question['acceptedAnswer'] ) ) {
						$answer = $question['acceptedAnswer'];
					}
				}

				$pairs[] = array(
					'question' => self::text( $question['name'] ),
					'answer'   => self::text( $answer ),
				);
			}
		}

		foreach ( $node as $child ) {
			if ( is_array( $child ) ) {
				self::collect_faq_nodes( $child, $pairs );
			}
		}
	}

	/**
	 * Extracts pairs from schema.org FAQPage JSON-LD.
	 *
	 * @param string $html Rendered HTML.
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function from_json_ld( $html ) {
		$pairs = array();

		if ( ! preg_match_all(
			'#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
			(string) $html,
			$matches
		) ) {
			return $pairs;
		}

		foreach ( $matches[1] as $raw ) {
			$decoded = json_decode( trim( $raw ), true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				continue;
			}
			self::collect_faq_nodes( $decoded, $pairs );
		}

		return self::normalise( $pairs );
	}

	/**
	 * Extracts a single {question, answer} pair from one <details> element's
	 * own content (i.e. with any nested <details> already excluded — see
	 * collect_details_pairs()).
	 *
	 * @param string $own_html Inner HTML of a <details>, minus any nested block.
	 * @param array  $pairs    Accumulator, passed by reference.
	 * @return void
	 */
	private static function extract_pair_from_own_html( $own_html, array &$pairs ) {
		// A tag ends at the first '>' that is not inside a quoted attribute
		// value — only '<' must be escaped in an HTML attribute, so a bare
		// [^>]* here would stop at the '>' inside e.g. <summary data-x="a>b">
		// and capture 'b">Q?' as the question instead of 'Q?'. Proven against
		// a battery of quoted/unbalanced cases before use (see task report).
		if ( ! preg_match( "#<summary(?:\\s(?:[^>\"']|\"[^\"]*\"|'[^']*')*)?>(.*?)</summary>#is", $own_html, $summary ) ) {
			return;
		}

		$question = self::text( $summary[1] );
		$answer   = self::text( str_replace( $summary[0], '', $own_html ) );

		if ( '' === $question || '' === $answer ) {
			return;
		}

		$pairs[] = array(
			'question' => $question,
			'answer'   => $answer,
		);
	}

	/**
	 * Walks <details> elements with explicit nesting-depth tracking and
	 * collects a pair for each one.
	 *
	 * A single non-greedy `<details>(.*?)</details>` regex — the obvious first
	 * attempt — matches an outer <details> only as far as the *first*
	 * `</details>` it finds, which for a nested accordion is the inner one's.
	 * That blends the inner question and answer into the outer's answer and
	 * truncates the outer answer at the nesting point: a garbled pair, exactly
	 * what this detector is meant to never produce. This walks the token
	 * stream instead, pushing a fresh buffer on every <details> open and
	 * popping (and extracting) it on the matching close, so each block's "own"
	 * text never includes anything that belonged to a block nested inside it.
	 *
	 * An unclosed <details> — including one left open because a nested block
	 * inside it never closed either — has no trustworthy boundary, so it is
	 * simply left on the stack and never turned into a pair.
	 *
	 * @param string $html  HTML fragment.
	 * @param array  $pairs Accumulator, passed by reference.
	 * @return void
	 */
	private static function collect_details_pairs( $html, array &$pairs ) {
		// Delimiter requires the character after "details" to be '>' or
		// whitespace, not just a word boundary, so a custom element like
		// <details-menu> is not mistaken for the native element. Within an
		// attribute list, a '>' only ends the tag when it is not inside a
		// "..." or '...' quoted value — only '<' must be escaped in an HTML
		// attribute value, so <details data-x="a>b"> is legal markup and a
		// bare [^>]* would end the tag at the wrong '>'. Proven against a
		// battery of quoted/unbalanced/nested cases before use (see task
		// report — a first attempt at this pattern, escaped by hand, silently
		// swapped one alternative's quote character and was caught only by
		// running it, not by reading it).
		$tokens = preg_split(
			"/(<details(?:\\s(?:[^>\"']|\"[^\"]*\"|'[^']*')*)?>|<\\/details\\s*>)/i",
			(string) $html,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		if ( ! is_array( $tokens ) ) {
			return;
		}

		$stack = array();

		foreach ( $tokens as $token ) {
			if ( '' === $token ) {
				continue;
			}

			if ( 0 === stripos( $token, '</details' ) ) {
				if ( empty( $stack ) ) {
					// A stray closing tag with nothing open — ignore it rather
					// than guess what it was meant to close.
					continue;
				}

				$own = array_pop( $stack );
				self::extract_pair_from_own_html( $own, $pairs );

				if ( ! empty( $stack ) ) {
					// Leave a gap where the nested block was, so discarding it
					// from the parent's own text does not glue the text
					// before and after it into one run-on word.
					$stack[ count( $stack ) - 1 ] .= ' ';
				}
				continue;
			}

			if ( 0 === stripos( $token, '<details' ) ) {
				$stack[] = '';
				continue;
			}

			if ( ! empty( $stack ) ) {
				$stack[ count( $stack ) - 1 ] .= $token;
			}
		}

		// Anything left on the stack is an unclosed <details> — dropped, not
		// guessed at.
	}

	/**
	 * Extracts pairs from details/summary and accordion markup.
	 *
	 * Deliberately scoped to the native <details>/<summary> element, which is
	 * what WordPress core's Details block and most FAQ block plugins render.
	 * Theme-authored "accordion" markup built from plain divs varies too much
	 * (class names, data attributes, JS toggle conventions all differ per
	 * theme) to detect reliably without risking a garbled pair, so it is out
	 * of scope on purpose — fail closed rather than guess at a pattern.
	 *
	 * @param string $html Rendered HTML.
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function from_blocks( $html ) {
		$pairs = array();
		self::collect_details_pairs( (string) $html, $pairs );
		return self::normalise( $pairs );
	}

	/**
	 * Runs every HTML detector, JSON-LD first.
	 *
	 * @param string $html Rendered HTML.
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function detect_in_html( $html ) {
		// JSON-LD is listed first so normalise()'s first-wins dedupe keeps the
		// structured answer over a scraped one when both describe the same question.
		return self::normalise(
			array_merge( self::from_json_ld( $html ), self::from_blocks( $html ) )
		);
	}

	/**
	 * Trims, drops incomplete pairs, truncates to the API limits, and
	 * deduplicates case-insensitively on the question.
	 *
	 * Dedupe key: `strtolower( trim( $question ) )` (mb_strtolower() where
	 * available, for correct case-folding of accented characters). The API
	 * upserts on Postgres `lower(trim(question))`, and Postgres's bare TRIM()
	 * strips only the space character, where PHP's trim() also strips tabs,
	 * newlines, carriage returns and NUL. Those two are not the same function
	 * in general — but every pair reaching this method has already been
	 * through text() above, which collapses all whitespace runs (tabs,
	 * newlines, repeated spaces) to a single space and then trims. That means
	 * the only whitespace character that can appear anywhere in $question by
	 * the time it gets here is the space character, so PHP trim() and Postgres
	 * TRIM() strip exactly the same thing on this input and the dedupe key
	 * matches the server's. (A caller that feeds normalise() raw, un-normalised
	 * text directly — bypassing from_json_ld()/from_blocks() — would not get
	 * that guarantee for free.)
	 *
	 * @param array $pairs Raw pairs.
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function normalise( array $pairs ) {
		$seen = array();
		$out  = array();

		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}

			$question = trim( (string) ( isset( $pair['question'] ) ? $pair['question'] : '' ) );
			$answer   = trim( (string) ( isset( $pair['answer'] ) ? $pair['answer'] : '' ) );

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$fingerprint = function_exists( 'mb_strtolower' ) ? mb_strtolower( $question, 'UTF-8' ) : strtolower( $question );
			if ( isset( $seen[ $fingerprint ] ) ) {
				continue;
			}
			$seen[ $fingerprint ] = true;

			$out[] = array(
				'question' => self::truncate( $question, self::MAX_QUESTION ),
				'answer'   => self::truncate( $answer, self::MAX_ANSWER ),
			);
		}

		return $out;
	}
}
