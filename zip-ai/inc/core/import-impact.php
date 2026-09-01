<?php
/**
 * Import Impact — the ONE source of truth for "what will this import change?".
 *
 * Two surfaces consume it and must never disagree:
 *   - the `zipai/import-html` MCP ability, which returns the list to an AI
 *     client and refuses to write until the client comes back with a token;
 *   - the in-panel import wizard, which renders it under the layout options
 *     (via `ZIPAI_CONFIG.importImpact`, so there is no second copy in JSX).
 *
 * The wording is derived from what `@bsf/wp-importer`'s committer ACTUALLY
 * writes per mode — shared template parts (`writeSiteChrome`), the global Global
 * Styles store, the Style Guide colour + font push, and the front-page flip. If
 * the committer's per-mode writes change, this file changes with it.
 *
 * @since 0.0.8
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Truthful per-layout impact statements for an HTML import.
 */
class Import_Impact {

	/**
	 * Layouts that change state beyond the new page. `match_site` only adds a
	 * page, so it is absent by design.
	 */
	const CONFIRMABLE = array( 'standalone', 'replace_site' );

	/**
	 * Layouts that overwrite state which existed before the import — their
	 * consent copy must say the old design is not saved. `standalone` writes
	 * only its OWN page-scoped chrome.
	 */
	const OVERWRITES_SITE = array( 'replace_site' );

	/**
	 * The impact of one layout choice.
	 *
	 * @param string $layout       One of match_site|standalone|replace_site.
	 * @param bool   $set_homepage Whether the front page would change too.
	 * @return array{change: string[], keep: string[], reversible: string}
	 */
	public static function for_layout( string $layout, bool $set_homepage = false ) {
		$change = array();

		if ( 'replace_site' === $layout ) {
			$change[] = __( 'Your site header will be REPLACED on every page.', 'zip-ai' );
			$change[] = __( 'Your site footer will be REPLACED on every page.', 'zip-ai' );
			$change[] = __( 'Your brand colours will change on every page.', 'zip-ai' );
			$change[] = __( 'Your site fonts will change on every page.', 'zip-ai' );
		} elseif ( 'standalone' === $layout ) {
			$change[] = __( 'The new page gets its OWN header and footer instead of your site\'s.', 'zip-ai' );
			$change[] = __( 'Only this page is affected. Your other pages keep your current header and footer.', 'zip-ai' );
		} else {
			$change[] = __( 'A new page is added. Your existing design is kept exactly as it is.', 'zip-ai' );
		}

		if ( $set_homepage ) {
			$change[] = __( 'This page becomes your front page: it is what visitors see first.', 'zip-ai' );
		}

		return array(
			'change'     => $change,
			'keep'       => array(
				__( 'Your existing pages and posts stay exactly as they are.', 'zip-ai' ),
				__( 'Your plugins, users and site settings are not touched.', 'zip-ai' ),
				__( 'Nothing is deleted from your media library.', 'zip-ai' ),
			),
			'reversible' => self::overwrites_site_state( $layout, $set_homepage )
				? __( 'Not automatically. The design being replaced is not saved, and deleting the new page will not bring it back.', 'zip-ai' )
				: __( 'Yes. The new page can be deleted; nothing else changes.', 'zip-ai' ),
		);
	}

	/**
	 * Every layout's impact, for handing to the panel in one payload. Avoids a
	 * per-selection request and keeps the copy in exactly one place.
	 *
	 * @return array<string,array{change: string[], keep: string[], reversible: string}>
	 */
	public static function all() {
		$all = array();
		foreach ( array( 'match_site', 'standalone', 'replace_site' ) as $layout ) {
			$all[ $layout ] = self::for_layout( $layout );
		}
		return $all;
	}

	/**
	 * Whether a request needs explicit confirmation before it may write.
	 *
	 * @param string $layout       Requested layout.
	 * @param bool   $set_homepage Whether the front page would change.
	 * @return bool
	 */
	public static function needs_confirmation( string $layout, bool $set_homepage = false ) {
		return in_array( $layout, self::CONFIRMABLE, true ) || $set_homepage;
	}

	/**
	 * Whether a request overwrites pre-existing site state (its consent copy
	 * must warn the old design is not saved).
	 *
	 * @param string $layout       Requested layout.
	 * @param bool   $set_homepage Whether the front page would change.
	 * @return bool
	 */
	public static function overwrites_site_state( string $layout, bool $set_homepage = false ) {
		return in_array( $layout, self::OVERWRITES_SITE, true ) || $set_homepage;
	}
}
