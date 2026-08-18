<?php
/**
 * Store-finder page template.
 *
 * Rendered inside the active theme so the page carries the merchant's own
 * header and footer. The interactive map and list are the widget's locator
 * bundle mounting into .idea89-locator-host; everything here is the frame
 * around it plus the counts search engines need in the HTML.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

$idea89_view  = idea89_locator_page()->view_data();
$idea89_text  = $idea89_view['text'];
$idea89_stats = $idea89_view['stats'];

get_header();
?>
<div class="idea89-storefinder-page" data-layout="<?php echo esc_attr( $idea89_view['layout'] ); ?>"
	<?php if ( '' !== (string) $idea89_view['brand_color'] ) : ?>
	style="--sf-accent: <?php echo esc_attr( $idea89_view['brand_color'] ); ?>;"
	<?php endif; ?>
>
	<header class="sf-hero">
		<div class="sf-hero-inner">
			<span class="sf-hero-eyebrow"><?php echo esc_html( $idea89_text['eyebrow'] ); ?></span>
			<h1 class="sf-hero-title"><?php echo esc_html( $idea89_text['h1'] ); ?></h1>
			<p class="sf-hero-subtitle"><?php echo esc_html( $idea89_text['subhead'] ); ?></p>

			<?php if ( $idea89_stats['stores'] > 0 ) : ?>
			<div class="sf-hero-stats" role="list">
				<div class="sf-stat" role="listitem">
					<span class="sf-stat-num"><?php echo esc_html( number_format_i18n( $idea89_stats['stores'] ) ); ?></span>
					<span class="sf-stat-label"><?php echo esc_html( _n( 'Store', 'Stores', $idea89_stats['stores'], 'idea89-ai-shopping-assistant' ) ); ?></span>
				</div>
				<?php if ( $idea89_stats['cities'] > 0 ) : ?>
				<div class="sf-stat" role="listitem">
					<span class="sf-stat-num"><?php echo esc_html( number_format_i18n( $idea89_stats['cities'] ) ); ?></span>
					<span class="sf-stat-label"><?php echo esc_html( _n( 'City', 'Cities', $idea89_stats['cities'], 'idea89-ai-shopping-assistant' ) ); ?></span>
				</div>
				<?php endif; ?>
				<?php if ( $idea89_stats['countries'] > 0 ) : ?>
				<div class="sf-stat" role="listitem">
					<span class="sf-stat-num"><?php echo esc_html( number_format_i18n( $idea89_stats['countries'] ) ); ?></span>
					<span class="sf-stat-label"><?php echo esc_html( _n( 'Country', 'Countries', $idea89_stats['countries'], 'idea89-ai-shopping-assistant' ) ); ?></span>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
	</header>

	<section class="sf-locator-section" aria-label="<?php esc_attr_e( 'Store map and list', 'idea89-ai-shopping-assistant' ); ?>">
		<div class="idea89-locator-host">
			<idea89-locator
				mode="standalone"
				api-base="<?php echo esc_attr( $idea89_view['api_base'] ); ?>"
				api-key="<?php echo esc_attr( $idea89_view['api_key'] ); ?>"
				map-provider="<?php echo esc_attr( $idea89_view['map_provider'] ); ?>"
				<?php if ( ! empty( $idea89_view['map_key'] ) ) : ?>
				map-key="<?php echo esc_attr( $idea89_view['map_key'] ); ?>"
				<?php endif; ?>
				<?php if ( ! empty( $idea89_view['country'] ) ) : ?>
				default-country-code="<?php echo esc_attr( $idea89_view['country'] ); ?>"
				<?php endif; ?>
				nearest-results-count="<?php echo esc_attr( (string) $idea89_view['count'] ); ?>"
				default-radius-km="100"
			>
				<?php
				/*
				 * Light-DOM fallback. The web component replaces its own
				 * children once it upgrades, so this is only ever seen when
				 * the bundle does not run: a blocked request, a CSP rule, an
				 * ad blocker, an old browser. Without it the merchant gets a
				 * tall blank block and no idea why.
				 */
				?>
				<noscript>
					<p class="sf-fallback"><?php esc_html_e( 'The interactive map needs JavaScript. Our store list is below.', 'idea89-ai-shopping-assistant' ); ?></p>
				</noscript>
				<?php if ( ! empty( $idea89_view['locations'] ) ) : ?>
				<ul class="sf-fallback-list">
					<?php foreach ( $idea89_view['locations'] as $idea89_loc ) : ?>
						<?php
						$idea89_addr  = isset( $idea89_loc['address'] ) && is_array( $idea89_loc['address'] ) ? $idea89_loc['address'] : array();
						$idea89_parts = array_filter(
							array(
								isset( $idea89_addr['line_1'] ) ? $idea89_addr['line_1'] : '',
								isset( $idea89_addr['city'] ) ? $idea89_addr['city'] : '',
								isset( $idea89_addr['postcode'] ) ? $idea89_addr['postcode'] : '',
							)
						);
						?>
					<li>
						<strong><?php echo esc_html( isset( $idea89_loc['name'] ) ? $idea89_loc['name'] : '' ); ?></strong>
						<?php if ( ! empty( $idea89_parts ) ) : ?>
						<span><?php echo esc_html( implode( ', ', $idea89_parts ) ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $idea89_loc['phone'] ) ) : ?>
						<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', (string) $idea89_loc['phone'] ) ); ?>"><?php echo esc_html( $idea89_loc['phone'] ); ?></a>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
			</idea89-locator>
		</div>
	</section>

	<section class="sf-help" aria-labelledby="sf-help-title">
		<div class="sf-help-inner">
			<span class="sf-help-eyebrow"><?php esc_html_e( 'Need a hand?', 'idea89-ai-shopping-assistant' ); ?></span>
			<h2 class="sf-help-title" id="sf-help-title"><?php echo esc_html( $idea89_text['help_head'] ); ?></h2>
			<p class="sf-help-subtitle"><?php echo esc_html( $idea89_text['help_body'] ); ?></p>
			<?php if ( '' !== $idea89_text['cta_url'] && '' !== $idea89_text['cta_label'] ) : ?>
			<div class="sf-help-actions">
				<a class="sf-help-btn" href="<?php echo esc_url( $idea89_text['cta_url'] ); ?>">
					<span><?php echo esc_html( $idea89_text['cta_label'] ); ?></span>
				</a>
			</div>
			<?php endif; ?>
		</div>
	</section>
</div>
<?php
get_footer();
