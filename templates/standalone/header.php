<?php
/**
 * Standalone section — document head and header.
 *
 * Deliberately NOT get_header(): the section bypasses the active theme, so the
 * document is opened here. wp_head() still runs, because plugins that matter —
 * analytics, consent, the payment gateway's scripts — hook it, and a section that
 * silently drops them would be a compliance problem rather than a styling choice.
 *
 * The header is one element with two quite different layouts. On mobile the logo
 * is centred with the link and the account button pinned to the edges; on desktop
 * it is a sticky three-column grid and the account becomes a labelled button. That
 * is the design's own structure, not a simplification of it — and the logo stays
 * centred at both sizes, which is the detail an ordinary responsive header loses.
 *
 * Override by copying to `nera-strikeawin/header.php` in a theme.
 *
 * @package Nera_Strikeawin
 * @var string $saw_title Page title.
 * @var bool   $saw_inner True on a screen below the list, which hides this bar on
 *                        mobile because the screen supplies its own header there.
 */

defined( 'ABSPATH' ) || exit;

$saw_title = isset( $saw_title ) ? $saw_title : get_bloginfo( 'name' );
$saw_inner = isset( $saw_inner ) ? (bool) $saw_inner : false;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<?php
	/*
	 * Figtree 400-800. The prototype's face, and it carries Cyrillic — which is
	 * not incidental on a site where most players read Russian.
	 */
	?>
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'saw-app' ); ?>>
<div class="saw-shell">

	<header class="saw-header<?php echo $saw_inner ? ' saw-header--inner' : ''; ?>">
		<?php
		$saw_logo      = Nera_SAW_Standalone_Fields::shell( 'saw_logo' );
		$saw_logo_text = Nera_SAW_Standalone_Fields::shell( 'saw_logo_text' );
		$saw_link_text = Nera_SAW_Standalone_Fields::shell( 'saw_header_link' );
		$saw_account   = Nera_SAW_Router::account_url();
		?>
		<a class="saw-header__link" href="<?php echo esc_url( Nera_SAW_Router::url( 'how-it-works' ) ); ?>"><?php echo esc_html( $saw_link_text ); ?></a>

		<a class="saw-header__logo<?php echo is_array( $saw_logo ) ? ' saw-header__logo--image' : ''; ?>" href="<?php echo esc_url( Nera_SAW_Router::url() ); ?>">
			<?php
			if ( is_array( $saw_logo ) && ! empty( $saw_logo['url'] ) ) {
				printf(
					'<img src="%1$s" alt="%2$s">',
					esc_url( $saw_logo['url'] ),
					esc_attr( ! empty( $saw_logo['alt'] ) ? $saw_logo['alt'] : get_bloginfo( 'name' ) )
				);
			} else {
				// wp_kses_post, not esc_html: the field deliberately allows <em> so a
				// word can take the accent colour, as the design does with "a".
				echo wp_kses_post( $saw_logo_text );
			}
			?>
		</a>

		<div class="saw-header__tools">
			<?php
			// Renders nothing on a site with fewer than two languages.
			Nera_SAW_Router::part( 'language-toggle.php' );
			?>
			<?php
			/*
			 * The walkthrough button shares its address with the button on How it
			 * works: there is one walkthrough, so there is one place to say where it
			 * lives. Both disappear together while that field is empty, which it is
			 * until the walkthrough screen exists.
			 */
			$saw_walk = Nera_SAW_Router::walkthrough_url();
			if ( $saw_walk ) :
				?>
				<a class="saw-header__walk" href="<?php echo esc_url( $saw_walk ); ?>"
					aria-label="<?php echo esc_attr( Nera_SAW_Standalone_Fields::shell( 'saw_walkthrough_aria' ) ); ?>">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
						<path d="M3 5.5h18a1 1 0 0 1 1 1v2.6a3 3 0 0 0 0 5.8v2.6a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-2.6a3 3 0 0 0 0-5.8V6.5a1 1 0 0 1 1-1Z"></path>
						<path d="M10.3 10a1.8 1.8 0 0 1 3.5.6c0 1.2-1.8 1.7-1.8 1.7"></path>
						<circle cx="12" cy="15" r="0.4" fill="currentColor" stroke="none"></circle>
					</svg>
				</a>
			<?php endif; ?>

			<?php
			// Omitted rather than linked to nothing when WooCommerce has no account
			// page: an avatar button that 404s is the first thing a player taps.
			if ( $saw_account ) :
				if ( is_user_logged_in() ) :
					$saw_user = wp_get_current_user();
					$saw_name = $saw_user->display_name ? $saw_user->display_name : $saw_user->user_login;
					?>
					<a class="saw-header__avatar" href="<?php echo esc_url( $saw_account ); ?>"
						aria-label="<?php echo esc_attr( Nera_SAW_Standalone_Fields::shell( 'saw_account_aria' ) ); ?>">
						<?php
						// mb_substr, not substr: a Cyrillic initial is two bytes, and
						// half of one renders as a replacement character.
						echo esc_html( function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $saw_name, 0, 1 ) ) : strtoupper( substr( $saw_name, 0, 1 ) ) );
						?>
					</a>
				<?php else : ?>
					<a class="saw-header__signin" href="<?php echo esc_url( $saw_account ); ?>">
						<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true" focusable="false">
							<circle cx="12" cy="8" r="4"></circle>
							<path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"></path>
						</svg>
						<span><?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_signin_label' ) ); ?></span>
					</a>
					<?php
				endif;
			endif;
			?>
		</div>
	</header>
