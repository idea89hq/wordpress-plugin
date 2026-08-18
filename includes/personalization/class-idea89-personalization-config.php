<?php
/**
 * Typed accessors for the personalization settings.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the personalization options.
 */
class Idea89_Personalization_Config {

	/**
	 * Whether the merchant has switched personalization on.
	 *
	 * Off by default: it shares a secret with the IDEA89 dashboard and opens
	 * a server-to-server endpoint, so it must be a deliberate choice.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) get_option( 'idea89_personalization_enabled', false );
	}

	/**
	 * The shared signing secret, or '' when unset.
	 *
	 * @return string
	 */
	public function get_signing_secret() {
		return trim( (string) get_option( 'idea89_personalization_secret', '' ) );
	}

	/**
	 * Whether both the toggle and the secret are in place.
	 *
	 * A toggle with no secret cannot sign anything, so treat it as off rather
	 * than minting tokens nobody can verify.
	 *
	 * @return bool
	 */
	public function is_usable() {
		return $this->is_enabled() && '' !== $this->get_signing_secret();
	}
}
