<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

// The detector's truncate() delegates to the plugin's one truncation helper.
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-content-syncer.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-faq-detector.php';

class FaqDetectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		// Brain Monkey does not stub this automatically, and the brief's own
		// test bodies call it directly to build fixture HTML.
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -- Brief's tests -------------------------------------------------

	public function test_extracts_schema_org_faq_page_json_ld() {
		$html = '<html><head><script type="application/ld+json">' . wp_json_encode(
			array(
				'@context'   => 'https://schema.org',
				'@type'      => 'FAQPage',
				'mainEntity' => array(
					array(
						'@type'          => 'Question',
						'name'           => 'How long does delivery take?',
						'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Two to three working days.' ),
					),
					array(
						'@type'          => 'Question',
						'name'           => 'Can I return an item?',
						'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Yes, within 30 days.' ),
					),
				),
			)
		) . '</script></head><body></body></html>';

		$out = Idea89_Faq_Detector::from_json_ld( $html );

		$this->assertCount( 2, $out );
		$this->assertSame( 'How long does delivery take?', $out[0]['question'] );
		$this->assertSame( 'Two to three working days.', $out[0]['answer'] );
		$this->assertSame( 'Can I return an item?', $out[1]['question'] );
	}

	public function test_json_ld_inside_a_graph_wrapper_is_found() {
		// Yoast emits FAQPage nested under @graph rather than at the top level.
		$html = '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => array(
					array( '@type' => 'WebPage' ),
					array(
						'@type'      => 'FAQPage',
						'mainEntity' => array(
							array(
								'@type'          => 'Question',
								'name'           => 'Do you ship abroad?',
								'acceptedAnswer' => array( 'text' => 'We ship across the EU.' ),
							),
						),
					),
				),
			)
		) . '</script>';

		$out = Idea89_Faq_Detector::from_json_ld( $html );

		$this->assertCount( 1, $out );
		$this->assertSame( 'Do you ship abroad?', $out[0]['question'] );
	}

	public function test_json_ld_answer_html_is_stripped() {
		$html = '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@type'      => 'FAQPage',
				'mainEntity' => array(
					array(
						'name'           => 'Q?',
						'acceptedAnswer' => array( 'text' => '<p>Yes <strong>really</strong>.</p>' ),
					),
				),
			)
		) . '</script>';

		$out = Idea89_Faq_Detector::from_json_ld( $html );

		$this->assertSame( 'Yes really.', $out[0]['answer'] );
	}

	public function test_malformed_json_ld_is_ignored_not_fatal() {
		$this->assertSame( array(), Idea89_Faq_Detector::from_json_ld( '<script type="application/ld+json">{ not json</script>' ) );
	}

	public function test_non_faq_json_ld_is_ignored() {
		$html = '<script type="application/ld+json">' . wp_json_encode(
			array( '@type' => 'Product', 'name' => 'A spade' )
		) . '</script>';

		$this->assertSame( array(), Idea89_Faq_Detector::from_json_ld( $html ) );
	}

	public function test_extracts_details_and_summary_blocks() {
		$html = '<div class="wp-block-details">'
			. '<details><summary>What are your opening hours?</summary><p>Nine to five, Monday to Friday.</p></details>'
			. '<details><summary>Do you offer gift wrap?</summary><p>Yes, at checkout.</p></details>'
			. '</div>';

		$out = Idea89_Faq_Detector::from_blocks( $html );

		$this->assertCount( 2, $out );
		$this->assertSame( 'What are your opening hours?', $out[0]['question'] );
		$this->assertSame( 'Nine to five, Monday to Friday.', $out[0]['answer'] );
	}

	public function test_details_without_a_summary_is_skipped() {
		$out = Idea89_Faq_Detector::from_blocks( '<details><p>An answer with no question.</p></details>' );
		$this->assertSame( array(), $out );
	}

	public function test_details_with_an_empty_answer_is_skipped() {
		$out = Idea89_Faq_Detector::from_blocks( '<details><summary>A question?</summary></details>' );
		$this->assertSame( array(), $out );
	}

	public function test_detect_in_html_prefers_json_ld_and_deduplicates() {
		$json_ld = '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@type'      => 'FAQPage',
				'mainEntity' => array(
					array( 'name' => 'Shared question?', 'acceptedAnswer' => array( 'text' => 'From JSON-LD.' ) ),
				),
			)
		) . '</script>';
		$blocks = '<details><summary>Shared question?</summary><p>From a block.</p></details>';

		$out = Idea89_Faq_Detector::detect_in_html( $json_ld . $blocks );

		$this->assertCount( 1, $out );
		$this->assertSame( 'From JSON-LD.', $out[0]['answer'] );
	}

	public function test_normalise_trims_drops_empties_and_truncates() {
		$out = Idea89_Faq_Detector::normalise(
			array(
				array( 'question' => '  Padded?  ', 'answer' => '  Padded answer.  ' ),
				array( 'question' => '', 'answer' => 'No question.' ),
				array( 'question' => 'No answer?', 'answer' => '' ),
				array( 'question' => str_repeat( 'q', 900 ), 'answer' => str_repeat( 'a', 9000 ) ),
			)
		);

		$this->assertCount( 2, $out );
		$this->assertSame( 'Padded?', $out[0]['question'] );
		$this->assertSame( 'Padded answer.', $out[0]['answer'] );
		$this->assertLessThanOrEqual( 500, strlen( $out[1]['question'] ) );
		$this->assertLessThanOrEqual( 5000, strlen( $out[1]['answer'] ) );
	}

	public function test_normalise_deduplicates_case_insensitively() {
		// The API upserts on lower(trim(question)); sending both would make the
		// second silently overwrite the first.
		$out = Idea89_Faq_Detector::normalise(
			array(
				array( 'question' => 'Do you deliver?', 'answer' => 'First.' ),
				array( 'question' => 'do you DELIVER?', 'answer' => 'Second.' ),
			)
		);

		$this->assertCount( 1, $out );
		$this->assertSame( 'First.', $out[0]['answer'] );
	}

	public function test_known_faq_post_types_covers_the_common_plugins() {
		$types = Idea89_Faq_Detector::known_faq_post_types();

		$this->assertContains( 'ufaq', $types );
		$this->assertContains( 'faq', $types );
		$this->assertContains( 'helpie_faq', $types );
	}

	// -- Adversarial cases beyond the brief ------------------------------
	//
	// Real WordPress markup: nested accordions, HTML inside a <summary>,
	// multiple/nested JSON-LD, incomplete schema.org data, and HTML entities.

	public function test_nested_details_do_not_leak_into_each_others_answer() {
		// A sub-accordion inside an FAQ answer (e.g. troubleshooting steps
		// nested inside a "why won't it turn on" FAQ). A naive non-greedy
		// <details>(.*?)</details> regex matches the outer block only up to
		// the *inner* closing tag, blending the inner Q&A into the outer
		// answer and losing the text after the nested block closes. Neither
		// pair may borrow the other's text.
		$html = '<details><summary>Outer Q?</summary><p>Outer answer intro.</p>'
			. '<details><summary>Inner Q?</summary><p>Inner answer.</p></details>'
			. '<p>More outer answer.</p></details>';

		$out = Idea89_Faq_Detector::from_blocks( $html );

		$this->assertCount( 2, $out );

		$by_question = array();
		foreach ( $out as $pair ) {
			$by_question[ $pair['question'] ] = $pair['answer'];
		}

		$this->assertSame( 'Outer answer intro. More outer answer.', $by_question['Outer Q?'] );
		$this->assertSame( 'Inner answer.', $by_question['Inner Q?'] );
	}

	public function test_unbalanced_nested_details_drops_only_the_unclosed_block() {
		// The outer <details> here is never closed, so its own boundary
		// cannot be trusted and it must not be guessed at. A properly closed
		// block nested inside it is still safe to extract on its own.
		$html = '<details><summary>Outer Q?</summary>outer text'
			. '<details><summary>Inner Q?</summary>inner text</details>';

		$out = Idea89_Faq_Detector::from_blocks( $html );

		$this->assertCount( 1, $out );
		$this->assertSame( 'Inner Q?', $out[0]['question'] );
		$this->assertSame( 'inner text', $out[0]['answer'] );
	}

	public function test_summary_containing_html_tags_is_stripped_to_plain_text() {
		$html = '<details><summary>Do you ship to <strong>Northern Ireland</strong>?</summary>'
			. '<p>Yes, standard rates apply.</p></details>';

		$out = Idea89_Faq_Detector::from_blocks( $html );

		$this->assertCount( 1, $out );
		$this->assertSame( 'Do you ship to Northern Ireland?', $out[0]['question'] );
	}

	public function test_html_entities_in_question_and_answer_are_decoded() {
		$html = '<details><summary>What&#8217;s your return policy? Q&amp;A</summary>'
			. '<p>Thirty&nbsp;days, no questions asked.</p></details>';

		$out = Idea89_Faq_Detector::from_blocks( $html );

		$this->assertCount( 1, $out );
		$this->assertSame( 'What’s your return policy? Q&A', $out[0]['question'] );
		$this->assertSame( 'Thirty days, no questions asked.', $out[0]['answer'] );
	}

	public function test_multiple_json_ld_script_blocks_on_one_page_are_both_collected() {
		// Yoast's WebPage/breadcrumb graph and a separate FAQ block plugin
		// commonly emit their own independent <script> tags on the same page.
		$faq_one = '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@type'      => 'FAQPage',
				'mainEntity' => array(
					array( 'name' => 'Is delivery free?', 'acceptedAnswer' => array( 'text' => 'Over £50.' ) ),
				),
			)
		) . '</script>';
		$faq_two = '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@type'      => 'FAQPage',
				'mainEntity' => array(
					array( 'name' => 'Do you gift wrap?', 'acceptedAnswer' => array( 'text' => 'Yes, free.' ) ),
				),
			)
		) . '</script>';

		$out = Idea89_Faq_Detector::from_json_ld( $faq_one . $faq_two );

		$this->assertCount( 2, $out );
		$questions = array_column( $out, 'question' );
		$this->assertContains( 'Is delivery free?', $questions );
		$this->assertContains( 'Do you gift wrap?', $questions );
	}

	public function test_json_ld_graph_with_multiple_faq_pages_are_all_collected() {
		$html = '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@graph' => array(
					array(
						'@type'      => 'FAQPage',
						'mainEntity' => array(
							array( 'name' => 'Shipping Q?', 'acceptedAnswer' => array( 'text' => 'Shipping A.' ) ),
						),
					),
					array( '@type' => 'WebPage' ),
					array(
						'@type'      => 'FAQPage',
						'mainEntity' => array(
							array( 'name' => 'Returns Q?', 'acceptedAnswer' => array( 'text' => 'Returns A.' ) ),
						),
					),
				),
			)
		) . '</script>';

		$out = Idea89_Faq_Detector::from_json_ld( $html );

		$this->assertCount( 2, $out );
		$questions = array_column( $out, 'question' );
		$this->assertContains( 'Shipping Q?', $questions );
		$this->assertContains( 'Returns Q?', $questions );
	}

	public function test_json_ld_question_with_no_accepted_answer_is_skipped_but_siblings_survive() {
		$html = '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@type'      => 'FAQPage',
				'mainEntity' => array(
					array( 'name' => 'No answer here?' ),
					array( 'name' => 'This one has an answer?', 'acceptedAnswer' => array( 'text' => 'Yes.' ) ),
				),
			)
		) . '</script>';

		$out = Idea89_Faq_Detector::from_json_ld( $html );

		$this->assertCount( 1, $out );
		$this->assertSame( 'This one has an answer?', $out[0]['question'] );
	}

	public function test_json_ld_accepted_answer_as_plain_string_is_extracted() {
		// Not every plugin nests acceptedAnswer as {'@type':'Answer','text':...} —
		// some emit the answer as a bare string.
		$html = '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@type'      => 'FAQPage',
				'mainEntity' => array(
					array( 'name' => 'Do you take returns?', 'acceptedAnswer' => 'Within 14 days.' ),
				),
			)
		) . '</script>';

		$out = Idea89_Faq_Detector::from_json_ld( $html );

		$this->assertCount( 1, $out );
		$this->assertSame( 'Within 14 days.', $out[0]['answer'] );
	}

	public function test_json_ld_single_question_object_as_main_entity_is_extracted() {
		// schema.org allows mainEntity to be a single Thing rather than a
		// list; simpler FAQ plugins that only ever emit one question per page
		// take advantage of that.
		$html = '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@type'      => 'FAQPage',
				'mainEntity' => array(
					'@type'          => 'Question',
					'name'           => 'Only one question here?',
					'acceptedAnswer' => array( 'text' => 'Just this one.' ),
				),
			)
		) . '</script>';

		$out = Idea89_Faq_Detector::from_json_ld( $html );

		$this->assertCount( 1, $out );
		$this->assertSame( 'Only one question here?', $out[0]['question'] );
	}

	// -- Fix round 1: '>' inside a quoted attribute value ----------------
	//
	// Only '<' must be escaped in an HTML attribute value; a literal '>' is
	// legal inside a quoted attribute. A bare [^>]* tag-boundary match ends
	// at that '>' instead of the tag's real closing '>', producing a
	// garbled question rather than dropping the block — the one failure
	// mode this class must never have.

	public function test_summary_attribute_with_double_quoted_gt_does_not_garble_the_question() {
		$html = '<details><summary data-x="a>b">Q?</summary>Answer text.</details>';

		$out = Idea89_Faq_Detector::from_blocks( $html );

		$this->assertCount( 1, $out );
		$this->assertSame( 'Q?', $out[0]['question'] );
		$this->assertSame( 'Answer text.', $out[0]['answer'] );
	}

	public function test_summary_attribute_with_single_quoted_gt_does_not_garble_the_question() {
		$html = "<details><summary data-x='a>b'>Q?</summary>Answer text.</details>";

		$out = Idea89_Faq_Detector::from_blocks( $html );

		$this->assertCount( 1, $out );
		$this->assertSame( 'Q?', $out[0]['question'] );
		$this->assertSame( 'Answer text.', $out[0]['answer'] );
	}

	public function test_details_attribute_with_gt_does_not_garble_the_question() {
		$html = '<details data-x="a>b"><summary>Q?</summary>Answer text.</details>';

		$out = Idea89_Faq_Detector::from_blocks( $html );

		$this->assertCount( 1, $out );
		$this->assertSame( 'Q?', $out[0]['question'] );
		$this->assertSame( 'Answer text.', $out[0]['answer'] );
	}

	public function test_unbalanced_quote_in_attribute_fails_closed() {
		// The opening double quote in data-x="a>b never finds a matching
		// close, so the tag's real end cannot be located with confidence.
		// No pair, not a guess.
		$html = '<details><summary data-x="a>b>Q?</summary>Answer text.</details>';

		$out = Idea89_Faq_Detector::from_blocks( $html );

		$this->assertSame( array(), $out );
	}
}
