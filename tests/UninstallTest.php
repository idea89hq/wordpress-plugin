<?php
/**
 * uninstall.php promises to remove "every trace of the plugin". This holds it
 * to that promise structurally, rather than by listing the options we happen to
 * remember.
 *
 * The checkout ladder shipped four persisted options and added none of them to
 * uninstall.php. The one that mattered was idea89_acp_enabled: it gates
 * publishing catalog data to third parties and ships off, so a merchant who
 * enabled it, uninstalled, and later reinstalled would have resumed publishing
 * with no action and no notice, believing a fresh install starts from the
 * shipped default.
 *
 * A test that asserted "these four are listed" would have been written after
 * the fact and would not catch the fifth. This scans the source instead, so the
 * next persisted option that skips cleanup fails here on the commit that adds
 * it.
 *
 * @package Idea89
 */

use PHPUnit\Framework\TestCase;

/** Holds uninstall.php to its own stated contract. */
class UninstallTest extends TestCase {

	/**
	 * Option names read or written by the plugin, mapped to where they appear.
	 *
	 * @return array<string,string>
	 */
	private function persisted_options() {
		$found = array();
		$files = array( IDEA89_PLUGIN_DIR . 'idea89-assistant.php' );

		$dir = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( IDEA89_PLUGIN_DIR . 'includes' )
		);
		foreach ( $dir as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		foreach ( $files as $path ) {
			$source = file_get_contents( $path );
			preg_match_all(
				"/\\b(?:get|update|add)_option\\(\\s*'(idea89_[a-z0-9_]+)'/",
				$source,
				$matches
			);
			foreach ( $matches[1] as $option ) {
				if ( ! isset( $found[ $option ] ) ) {
					$found[ $option ] = str_replace( IDEA89_PLUGIN_DIR, '', $path );
				}
			}
		}

		return $found;
	}

	/**
	 * Every 'idea89_*' string literal named in uninstall.php.
	 *
	 * @return string[]
	 */
	private function cleaned_up_options() {
		$source = file_get_contents( IDEA89_PLUGIN_DIR . 'uninstall.php' );
		preg_match_all( "/'(idea89_[a-z0-9_]+)'/", $source, $matches );

		return $matches[1];
	}

	/** No option the plugin persists may outlive the plugin. */
	public function test_every_persisted_option_is_removed_on_uninstall() {
		$persisted = $this->persisted_options();
		$cleaned   = $this->cleaned_up_options();

		$missing = array();
		foreach ( $persisted as $option => $where ) {
			if ( ! in_array( $option, $cleaned, true ) ) {
				$missing[] = $option . ' (' . $where . ')';
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"These options survive uninstall. Add them to the array in uninstall.php:\n  "
				. implode( "\n  ", $missing )
		);
	}

	/**
	 * Guards the scan itself. If the regex or the directory walk breaks, the
	 * test above passes vacuously with an empty set and stops protecting
	 * anything, which is the failure mode of every source-scanning test.
	 */
	public function test_the_scan_actually_finds_options() {
		$persisted = $this->persisted_options();

		$this->assertGreaterThan(
			20,
			count( $persisted ),
			'The option scan found almost nothing, so it is no longer scanning.'
		);
		$this->assertArrayHasKey( 'idea89_api_key', $persisted );
		$this->assertArrayHasKey( 'idea89_acp_enabled', $persisted );
	}

	/**
	 * ACP is called out on its own because it is the only option here whose
	 * survival exposes catalog data rather than merely restoring a preference.
	 */
	public function test_acp_enable_flag_is_removed_on_uninstall() {
		$this->assertContains(
			'idea89_acp_enabled',
			$this->cleaned_up_options(),
			'idea89_acp_enabled gates publishing catalog data to third parties. '
				. 'Leaving it behind makes a reinstall resume publishing silently.'
		);
	}
}
