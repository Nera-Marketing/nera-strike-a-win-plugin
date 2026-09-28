<?php
/**
 * Standalone section — one competition.
 *
 * The screen a player reads before paying, so every number on it is a promise:
 * what the run costs, how long each question lasts, how many tickets a perfect
 * run pays, and how many entries are left. All of them come from
 * Nera_SAW_Competition_Spec, which is the only thing that knows where they live.
 *
 * WHY THERE IS NO PAGE BEHIND THIS ONE
 * ------------------------------------
 * Every other standalone screen is a real page an administrator edits. This one
 * cannot be: one template serves every competition, and a page per product would
 * be a page to forget to create. The copy that is not a number therefore lives on
 * the Standalone Content options page, where it is edited once and applies to all
 * of them — which is also the right place for it, since a sentence about how runs
 * work should not say something different on two competitions.
 *
 * LAYOUT
 * The design reads as one vertical path: prize, then spec, then payment, then the
 * run, joined by short connector strokes. The spec is the only dark block on the
 * screen, which is the point — it is the part the player is being asked to accept.
 *
 * Override by copying to `nera-strikeawin/competition.php` in a theme.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_slug = get_query_var( Nera_SAW_Router::QV_ARG );
$saw_post = $saw_slug ? get_page_by_path( sanitize_title( $saw_slug ), OBJECT, 'product' ) : null;
$saw_spec = $saw_post ? Nera_SAW_Competition_Spec::get( $saw_post->ID ) : null;

if ( ! $saw_spec ) {
	/*
	 * A slug that is not a competition is a 404, not an empty competition page.
	 * Rendering the shell with nothing in it would be indexed, linked and
	 * eventually reported as a bug.
	 */
	status_header( 404 );
	nocache_headers();

	Nera_SAW_Router::part(
		'header.php',
		array(
			'saw_title' => __( 'Not found', 'nera-strikeawin' ),
			'saw_inner' => true,
		)
	);
	Nera_SAW_Router::part( 'screen-head.php', array( 'saw_screen_title' => __( 'Not found', 'nera-strikeawin' ) ) );
	?>
	<div class="saw-screen saw-screen--narrow">
		<div class="saw-empty">
			<p><?php esc_html_e( 'That competition is no longer available.', 'nera-strikeawin' ); ?></p>
			<p><a href="<?php echo esc_url( Nera_SAW_Router::url() ); ?>"><?php esc_html_e( 'See what is open', 'nera-strikeawin' ); ?></a></p>
		</div>
	</div>
	<?php
	Nera_SAW_Router::part( 'footer.php' );
	return;
}

$saw_stock    = $saw_spec['stock'];
$saw_quiz     = $saw_spec['quiz'];
$saw_sold_out = ! empty( $saw_stock['sold_out'] );
$saw_date_fmt = get_option( 'date_format' ) . ', ' . get_option( 'time_format' );

/*
 * The closing date doubles as the screen's subtitle, where the design puts it. It
 * is the one fact that decides whether to read the rest of the page now or later,
 * so it belongs beside the name rather than further down.
 */
$saw_sub = $saw_spec['closes_timestamp']
	? sprintf(
		/* translators: %s: closing date and time */
		__( 'Closes %s', 'nera-strikeawin' ),
		wp_date( $saw_date_fmt, $saw_spec['closes_timestamp'] )
	)
	: '';

Nera_SAW_Router::part(
	'header.php',
	array(
		'saw_title' => $saw_spec['name'],
		'saw_inner' => true,
	)
);

Nera_SAW_Router::part(
	'screen-head.php',
	array(
		'saw_screen_title' => $saw_spec['name'],
		'saw_screen_sub'   => $saw_sub,
	)
);
?>

<div class="saw-screen saw-screen--narrow">
<article class="saw-path">

	<?php // --- the prize ------------------------------------------------------ ?>
	<?php Nera_SAW_Router::part( 'parts/hero.php', array( 'saw_spec' => $saw_spec ) ); ?>

	<div class="saw-connector" aria-hidden="true"></div>

	<?php // --- the spec ------------------------------------------------------- ?>
	<?php Nera_SAW_Router::part( 'parts/quiz-spec.php', array( 'saw_spec' => $saw_spec ) ); ?>

	<div class="saw-connector" aria-hidden="true"></div>

	<?php // --- buying --------------------------------------------------------- ?>
	<?php if ( ! $saw_spec['open'] ) : ?>

		<section class="saw-empty">
			<p>
				<?php
				echo esc_html(
					$saw_sold_out
						? __( 'Every entry has gone. Nothing more can be bought for this competition.', 'nera-strikeawin' )
						: __( 'This competition has closed.', 'nera-strikeawin' )
				);
				?>
			</p>
			<p><a href="<?php echo esc_url( Nera_SAW_Router::url() ); ?>"><?php esc_html_e( 'See what is open', 'nera-strikeawin' ); ?></a></p>
		</section>

	<?php else : ?>

		<?php
		/*
		 * A GET form, posting the same arguments the entry shortcode builds by hand:
		 * WooCommerce reads add-to-cart from $_REQUEST on wp_loaded, and the tier key
		 * is picked up by Nera_SAW_Cart_Entry::add_cart_item_data(). Reusing that path
		 * rather than inventing one keeps the price, the ceiling gate and the run
		 * grant identical in both modes.
		 */
		// Posted at the cart URL, but nobody lands there: the section redirects
		// add-to-cart to Before you pay. Keeping WooCommerce's own target means the
		// form still works if that redirect is ever filtered off.
		$saw_action = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );
		?>
		<form class="saw-buy" method="get" action="<?php echo esc_url( $saw_action ); ?>">
			<input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $saw_spec['id'] ); ?>">
			<?php
			// Which basket this add belongs to. The form posts to WooCommerce's cart
			// URL, which is outside the section, so the path alone would say "main".
			?>
			<input type="hidden" name="saw_basket" value="1">
			<?php if ( class_exists( 'Nera_SAW_Language' ) ) { Nera_SAW_Language::hidden_field(); } ?>

			<p class="saw-buy__eyebrow"><?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_cd_tier_heading' ) ); ?></p>

			<ul class="saw-tiers">
				<?php foreach ( $saw_spec['tiers'] as $saw_i => $saw_tier ) : ?>
					<li>
						<label class="saw-tier">
							<input type="radio" name="<?php echo esc_attr( Nera_SAW_Cart_Entry::CART_KEY ); ?>"
								value="<?php echo esc_attr( $saw_tier['key'] ); ?>"
								<?php checked( 0, $saw_i ); ?>>
							<span class="saw-tier__body">
								<span class="saw-tier__label"><?php echo esc_html( $saw_tier['label'] ); ?></span>
								<span class="saw-tier__price">
									<?php
									echo wp_kses_post(
										sprintf(
											/* translators: 1: price, 2: ticket multiplier */
											__( '%1$s &middot; tickets &times;%2$d', 'nera-strikeawin' ),
											wc_price( $saw_tier['price'] ),
											$saw_tier['multiplier']
										)
									);
									?>
								</span>
								<span class="saw-tier__perfect">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: tickets */
											_n( 'Perfect run: %s ticket', 'Perfect run: %s tickets', $saw_tier['perfect_run'], 'nera-strikeawin' ),
											number_format_i18n( $saw_tier['perfect_run'] )
										)
									);
									?>
								</span>
							</span>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>

			<div class="saw-qty">
				<label for="saw-qty-input" class="saw-qty__label">
					<span><?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_cd_runs_heading' ) ); ?></span>
					<small><?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_cd_runs_note' ) ); ?></small>
				</label>
				<?php
				// Capped at what is actually left: an input that accepts 50 when 3
				// remain is a promise the cart then breaks.
				$saw_max = $saw_stock['total'] > 0 ? max( 1, (int) $saw_stock['remaining'] ) : 99;
				?>
				<input id="saw-qty-input" class="saw-qty__input" type="number" name="quantity"
					value="1" min="1" max="<?php echo esc_attr( $saw_max ); ?>" step="1" inputmode="numeric">
			</div>

			<button type="submit" class="saw-cta"><?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_cd_cta' ) ); ?></button>

			<p class="saw-buy__risk">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of questions, 2: seconds per question */
						__( '%1$s questions, %2$d seconds each.', 'nera-strikeawin' ),
						number_format_i18n( $saw_quiz['questions'] ),
						$saw_spec['timer']['seconds']
					)
				);
				?>
				<?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_cd_risk' ) ); ?>
			</p>
		</form>

	<?php endif; ?>

	<div class="saw-connector" aria-hidden="true"></div>

	<?php // --- the shape of the run ------------------------------------------- ?>
	<section class="saw-run">
		<p class="saw-run__eyebrow">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: section name, 2: the run's shape */
					__( '%1$s · %2$s', 'nera-strikeawin' ),
					Nera_SAW_Standalone_Fields::shell( 'saw_cd_run_heading' ),
					$saw_quiz['is_ladder']
						? sprintf(
							/* translators: 1: questions, 2: stages */
							__( '%1$s questions, %2$s stages', 'nera-strikeawin' ),
							number_format_i18n( $saw_quiz['questions'] ),
							number_format_i18n( $saw_quiz['stages'] )
						)
						: sprintf(
							/* translators: %s: questions */
							__( '%s questions, in random order', 'nera-strikeawin' ),
							number_format_i18n( $saw_quiz['questions'] )
						)
				)
			);
			?>
		</p>

		<?php
		if ( $saw_quiz['is_ladder'] ) :
			/*
			 * Ladder discloses the order, because the order is the product: the player
			 * is told they climb easy to hard, so the screen shows the climb.
			 *
			 * The zig-zag is not decoration. Laid out in a row, ten questions read as a
			 * list of ten things; walked down a spine, they read as one route with a
			 * start and an end -- which is what a run is, and what the player is being
			 * asked to picture before paying.
			 */
			$saw_n     = 0;
			$saw_delay = 0;
			?>
			<div class="saw-map">
				<div class="saw-map__spine" aria-hidden="true"></div>

				<?php foreach ( $saw_quiz['levels'] as $saw_stage => $saw_level ) : ?>
					<div class="saw-stage">
						<p class="saw-stage__pill" style="background:<?php echo esc_attr( $saw_level['colour'] ); ?>">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: stage number, 2: level name, 3: tickets each */
									__( 'Stage %1$d · %2$s · %3$s', 'nera-strikeawin' ),
									$saw_stage + 1,
									$saw_level['label'],
									sprintf(
										/* translators: %s: tickets */
										_n( '%s ticket each', '%s tickets each', $saw_level['tickets'], 'nera-strikeawin' ),
										number_format_i18n( $saw_level['tickets'] )
									)
								)
							);
							?>
						</p>

						<ol class="saw-map__slots" start="<?php echo esc_attr( $saw_n + 1 ); ?>">
							<?php
							for ( $saw_q = 0; $saw_q < $saw_level['questions']; $saw_q++ ) :
								$saw_n++;
								// Odd numbers left, even right, counted across the whole run
								// rather than per stage -- so the walk never doubles back on
								// itself where one stage ends and the next begins.
								$saw_side = ( 0 === $saw_n % 2 ) ? 'right' : 'left';
								?>
								<li class="saw-node saw-node--<?php echo esc_attr( $saw_side ); ?>"
									style="--saw-node-colour:<?php echo esc_attr( $saw_level['colour'] ); ?>;--saw-node-delay:<?php echo (int) $saw_delay; ?>ms">
									<span class="saw-node__n"><?php echo esc_html( number_format_i18n( $saw_n ) ); ?></span>
									<span class="saw-node__t">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: tickets */
												_n( '%s ticket', '%s tickets', $saw_level['tickets'], 'nera-strikeawin' ),
												number_format_i18n( $saw_level['tickets'] )
											)
										);
										?>
									</span>
								</li>
								<?php
								$saw_delay += 70;
							endfor;
							?>
						</ol>
					</div>
				<?php endforeach; ?>
			</div>

		<?php else : ?>

			<?php
			/*
			 * Random discloses the composition and nothing else. There is no spine and
			 * no zig-zag here on purpose: a path is a claim about order, and the draw
			 * shuffles. The nodes carry what a question is worth, never where it falls.
			 */
			?>
			<ul class="saw-map__bag">
				<?php foreach ( $saw_quiz['levels'] as $saw_level ) : ?>
					<?php for ( $saw_q = 0; $saw_q < $saw_level['questions']; $saw_q++ ) : ?>
						<li class="saw-node saw-node--blind" style="--saw-node-colour:<?php echo esc_attr( $saw_level['colour'] ); ?>">
							<span class="saw-node__n"><?php echo esc_html( number_format_i18n( $saw_level['tickets'] ) ); ?></span>
							<span class="saw-node__t">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: tickets */
										_n( '%s ticket', '%s tickets', $saw_level['tickets'], 'nera-strikeawin' ),
										number_format_i18n( $saw_level['tickets'] )
									)
								);
								?>
							</span>
						</li>
					<?php endfor; ?>
				<?php endforeach; ?>
			</ul>

			<p class="saw-map__note"><?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_cd_random_note' ) ); ?></p>

		<?php endif; ?>
	</section>

	<div class="saw-connector" aria-hidden="true"></div>

	<?php Nera_SAW_Router::part( 'parts/result-teaser.php' ); ?>

</article>
</div>

<?php
Nera_SAW_Router::part( 'footer.php' );
