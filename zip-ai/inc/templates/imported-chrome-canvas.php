<?php
/**
 * Imported-chrome canvas — the plugin-served template for takeover pages.
 *
 * Structure ONLY, zero design opinions: all design comes from the imported
 * blocks (GBS-self-contained) and whatever the site enqueues through the
 * normal `wp_head`/`wp_footer` pipelines (theme CSS included — same proven
 * behavior as the FSE lane). Rendered when `Imported_Chrome::maybe_takeover`
 * resolves a scope:
 *   site — imported header part · content · imported footer part
 *   page — content only (standalone pages embed their chrome in post_content)
 *
 * Known limitation (documented, not fixed): themes that print UI via
 * `wp_head`/`wp_footer`/`wp_body_open` (mobile drawers, preloaders) keep
 * printing it inside the canvas.
 *
 * @since 0.0.8
 * @package zip-ai
 */

use ZipAI\MCP\Classes\Core\Imported_Chrome;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$zipai_scope = Imported_Chrome::$scope;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php if ( ! current_theme_supports( 'title-tag' ) ) : ?>
		<?php // Legacy themes print their <title> in header.php, which never runs here (the Elementor-Canvas guard). ?>
		<title><?php echo esc_html( wp_get_document_title() ); ?></title>
	<?php endif; ?>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'zipai-chrome-canvas' ); ?>>
<?php wp_body_open(); ?>
<?php if ( 'site' === $zipai_scope ) : ?>
	<?php
	// Imported block markup, admin-authored via the importer.
	echo do_blocks( Imported_Chrome::imported_part( 'header' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
<?php endif; ?>
<main class="zipai-canvas-content">
	<?php if ( is_singular() ) : ?>
		<?php
		while ( have_posts() ) {
			the_post();
			// Imported pages carry their H1 in post_content; non-imported
			// singulars keep their headline (title-unless-marker rule).
			if ( ! Imported_Chrome::is_imported_post() ) {
				echo '<h1 class="zipai-canvas-title entry-title">' . esc_html( get_the_title() ) . '</h1>';
			}
			echo '<div class="entry-content">';
			the_content();
			wp_link_pages();
			echo '</div>';
			if ( comments_open() || get_comments_number() ) {
				comments_template();
			}
		}
		?>
	<?php else : ?>
		<?php if ( have_posts() ) : ?>
			<ul class="zipai-canvas-archive">
				<?php
				while ( have_posts() ) {
					the_post();
					$zipai_permalink = get_permalink();
					if ( false === $zipai_permalink ) {
						continue;
					}
					echo '<li><a href="' . esc_url( $zipai_permalink ) . '">' . esc_html( get_the_title() ) . '</a>';
					echo '<p>' . esc_html( get_the_excerpt() ) . '</p></li>';
				}
				?>
			</ul>
			<?php the_posts_pagination(); ?>
		<?php else : ?>
			<p class="zipai-canvas-empty"><?php esc_html_e( 'Nothing found.', 'zip-ai' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</main>
<?php if ( 'site' === $zipai_scope ) : ?>
	<?php
	// Imported block markup, admin-authored via the importer.
	echo do_blocks( Imported_Chrome::imported_part( 'footer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
<?php endif; ?>
<?php wp_footer(); ?>
</body>
</html>
