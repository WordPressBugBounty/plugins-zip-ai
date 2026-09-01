<?php
/**
 * Import HTML — the door an external AI client uses to turn HTML into real
 * Spectra blocks on this site. The clients are Claude, Codex, Gemini, Cursor
 * and ChatGPT. The client writes the HTML first.
 *
 * The client's own model writes the HTML. The ZIP AI service then converts it.
 * The converter runs server-side. It is fail-closed on the block styling
 * contract. The service commits the result back over the MCP of this site.
 * This ability is the gate in front of that work. It checks that the site is
 * connected. It decides whether the request needs explicit consent. It turns
 * every service error into text the calling agent can act on.
 *
 * CONSENT: `match_site` is the default. It only ADDS a page. The other layouts
 * write site-level state. So they run in two phases. The first call returns the
 * impact and a `confirm_token`. It writes NOTHING. No human is in the loop at a
 * tool call. So the second call is the consent record.
 *
 * @since 0.0.8
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\Builder;

use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\Helper;
use ZipAI\MCP\Classes\Core\Import_Impact;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Tool_Types;
use ZipAI\MCP\Classes\Services\Brain_Client;

defined( 'ABSPATH' ) || exit;

/**
 * Ability: import externally-authored HTML as native blocks.
 */
class ImportHtml extends Abstract_Ability {

	/**
	 * Consent window. Long enough for an agent to relay the impact list and come
	 * back, short enough that a token cannot be replayed a session later.
	 */
	const CONFIRM_TTL = 600;

	/**
	 * Repeat-call window. A tool call that times out client-side is commonly
	 * re-issued; inside this window the same HTML returns the SAME page instead
	 * of creating a duplicate.
	 */
	const REPEAT_TTL = 600;

	/**
	 * Configures the ability's id, label, description and metadata.
	 *
	 * @return void
	 */
	public function configure() {
		$this->id          = 'zipai/import-html';
		$this->label       = 'Import HTML as Blocks';
		$this->description = 'Import HTML as native Spectra/Gutenberg blocks on this WordPress site. Call zipai/get-site-context FIRST and write HTML that matches its palette, fonts and brand, with a slug that does not collide with an existing page. HTML written blind clashes with the site\'s design. Write plain semantic HTML and put ALL per-block styling in `className` (utility classes); never in a `style` attribute, and never as backgroundColor/textColor/boxShadow attributes, which the importer rejects outright. Images: reference full URLs (remote ones are re-hosted into the media library on import); ask the user for their images before inventing stock, and where no real image exists use an explicit https://placehold.co/{width}x{height} placeholder with a descriptive alt. Never a guessed URL: it imports as a broken-image swap. Defaults are non-destructive: the page is created as a DRAFT and your existing header, footer and colours are kept. Any layout that changes site-wide design returns an impact list plus a confirm_token first and writes nothing until you call again with that token. Show the user that list and get their approval before you do.';
		// Matches the endpoint that reaches it: the external MCP route demands
		// `manage_options`, and credentials are only issued from an admin-only
		// screen. A lower bar here would read as "editors can import" when no
		// editor can get through the door.
		$this->capability = 'manage_options';
		// Hidden from the AI chat catalog (`visibility: internal`). The chat
		// agent must not see this: it would loop back into the server's own
		// import path and cannot complete the two-phase confirm-token handshake.
		// External MCP clients still get it via an explicit tool list.
		$this->meta['visibility'] = 'internal';
	}

	/**
	 * Returns the tool-type classification for this ability.
	 *
	 * @return string One of the Tool_Types constants.
	 */
	public function get_tool_type() {
		return Tool_Types::ACTION;
	}

	/**
	 * Annotated destructive because the SAME tool performs `replace_site`, which
	 * overwrites the site's header, footer, colours and fonts. The default layout
	 * only adds a draft page, but a client cannot know which layout a call will
	 * carry when it decides whether to prompt — so it should always prompt. The
	 * two-phase confirm token is the real gate; this is the client-side one.
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	public function get_annotations() {
		return array(
			'readonly'    => false,
			'destructive' => true,
			// A repeat call inside the dedupe window returns the same page, but
			// outside it a second call creates a second page.
			'idempotent'  => false,
		);
	}

	/**
	 * Returns the JSON Schema for this ability's input arguments.
	 *
	 * @return array<string,mixed> JSON Schema describing accepted arguments.
	 */
	public function get_input_schema() {
		return array(
			'type'                 => 'object',
			'required'             => array( 'html' ),
			'additionalProperties' => false,
			'properties'           => array(
				'html'            => array(
					'type'        => 'string',
					'description' => 'The full HTML of ONE page. Inline any CSS in a <style> tag; external stylesheets are not fetched. Per-block styling must ride className only.',
					'minLength'   => 1,
				),
				'title'           => array(
					'type'        => 'string',
					'description' => 'Page title. Falls back to the <title>/<h1> found in the HTML.',
					'maxLength'   => 250,
				),
				'slug'            => array(
					'type'        => 'string',
					'description' => 'Optional URL slug. A taken slug is resolved to slug-2, slug-3 …; the slug actually used is returned.',
					'maxLength'   => 200,
				),
				'layout'          => array(
					'type'        => 'string',
					'enum'        => array( 'match_site', 'standalone', 'replace_site' ),
					'default'     => 'match_site',
					'description' => 'match_site (default, non-destructive): keep this site\'s header, footer and colours; only the page body is imported. standalone: the page renders with its OWN header and footer, other pages untouched. replace_site: the imported header, footer, colours and fonts replace the site\'s design EVERYWHERE. standalone and replace_site require confirmation.',
				),
				'status'          => array(
					'type'        => 'string',
					'enum'        => array( 'draft', 'publish' ),
					'default'     => 'draft',
					'description' => 'draft (default) lets the user review before anything is public.',
				),
				'set_homepage'    => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Make this page the site\'s front page. Changes what visitors see first, so it requires confirmation.',
				),
				'sideload_images' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Re-host remote images in the media library. Leave on unless the images must keep pointing at their original URLs.',
				),
				'confirm_token'   => array(
					'type'        => 'string',
					'description' => 'The token from this ability\'s previous impact response. Only send it after the user has approved the listed changes.',
				),
			),
		);
	}

	/**
	 * Import the supplied HTML.
	 *
	 * @param array<string,mixed> $args Validated input arguments.
	 * @return array<string,mixed> Standardized success or error response.
	 */
	public function execute( $args ) {
		$html = isset( $args['html'] ) && is_string( $args['html'] ) ? trim( $args['html'] ) : '';
		if ( '' === $html ) {
			return Response::error( 'html is required.' );
		}

		$layout          = $this->enum_arg( $args, 'layout', array( 'match_site', 'standalone', 'replace_site' ), 'match_site' );
		$status          = $this->enum_arg( $args, 'status', array( 'draft', 'publish' ), 'draft' );
		$title           = isset( $args['title'] ) && is_string( $args['title'] ) ? sanitize_text_field( $args['title'] ) : '';
		$slug            = isset( $args['slug'] ) && is_string( $args['slug'] ) ? sanitize_title( $args['slug'] ) : '';
		$set_homepage    = ! empty( $args['set_homepage'] );
		$sideload_images = ! isset( $args['sideload_images'] ) || (bool) $args['sideload_images'];
		$confirm_token   = isset( $args['confirm_token'] ) && is_string( $args['confirm_token'] ) ? $args['confirm_token'] : '';

		// A draft cannot serve as the front page — visitors would get nothing.
		// Coerced before the fingerprint so the consent covers what really runs.
		if ( $set_homepage ) {
			$status = 'publish';
		}

		// Gate 1 — the account. Checked before anything else: it is the most
		// common reason a fresh site cannot import, and it is fixable by the
		// user in one step.
		if ( '' === Helper::get_decrypted_auth_token() ) {
			return Response::error(
				'This site is not connected to a ZIP AI account, so HTML cannot be converted.',
				'Ask the user to open WP Admin → Settings → ZIP AI and connect their account, then call this tool again.'
			);
		}

		$destructive = $this->is_destructive( $layout, $set_homepage );

		// Gate 2 — kept even though the ability now requires `manage_options` (so
		// an admin satisfies it): it states the intent, and it is the check that
		// still holds if the ability's own capability is ever lowered.
		if ( $destructive && ! current_user_can( 'edit_theme_options' ) ) {
			return Response::error(
				sprintf( 'This account may create pages but not change the site design, so layout "%s" is not permitted.', $layout ),
				'Retry with layout "match_site", which only adds a page.'
			);
		}

		$fingerprint = $this->fingerprint( $html, $layout, $status, $set_homepage );

		// Gate 3 — repeat call, checked BEFORE consent. A destructive call that
		// timed out client-side is re-issued with its already-consumed token; if
		// consent ran first, the retry would bounce off "no longer valid" and
		// drag the user through a second approval for a page that already
		// exists. Returning the previous result writes nothing, so no consent is
		// being skipped.
		//
		// The repeat key is DELIBERATELY wider than the consent fingerprint:
		// slug, title and the sideload choice do not change what the user
		// consented to, but they DO change what gets created — the same HTML
		// re-imported with a different slug or title is a NEW page the caller
		// asked for, not a duplicate submission to swallow.
		$repeat_key = 'zipai_import_done_' . md5(
			implode( '|', array( $fingerprint, $slug, $title, $sideload_images ? '1' : '0' ) )
		);
		$previous   = get_transient( $repeat_key );
		if ( is_array( $previous ) ) {
			return Response::success(
				sprintf(
					'This HTML was already imported a moment ago. The existing page is "%s"; nothing was created a second time.',
					$this->str_field( $previous, 'page_url' )
				),
				array(
					'post_id'     => $this->int_field( $previous, 'post_id' ),
					'page_url'    => $this->str_field( $previous, 'page_url' ),
					'slug'        => $this->str_field( $previous, 'slug' ),
					'block_count' => $this->int_field( $previous, 'block_count' ),
					'status'      => $this->str_field( $previous, 'status' ),
					'layout'      => $this->str_field( $previous, 'layout' ),
					'repeat'      => true,
				)
			);
		}

		// Gate 4 — consent. The first destructive call NEVER writes; it returns
		// what would change plus the token that authorises it.
		if ( $destructive ) {
			$consent = $this->check_consent( $confirm_token, $fingerprint, $layout, $set_homepage );
			if ( ! $consent['granted'] ) {
				return $consent['response'];
			}
		}

		$payload = array(
			'wp_url'               => get_site_url(),
			'layout'               => $layout,
			'sideload_images'      => $sideload_images,
			// Never true from this door: the HTML is externally authored and
			// nobody consented to script injection. The service strips
			// scripts and imports the rest.
			'scripts_acknowledged' => false,
			// Sent as a one-entry `pages[]` rather than the bare `html`
			// field: only the pages form carries a title and slug.
			'pages'                => array(
				array(
					'html'        => $html,
					'slug'        => $slug,
					'title'       => $title,
					'is_homepage' => $set_homepage,
				),
			),
		);

		$result = Brain_Client::post( '/import', $payload );

		// Self-heal the one failure this flow cannot ask the user to fix mid-call:
		// WordPress rejected the application password the service has on file. Only
		// code running INSIDE WordPress holds the admin identity to mint a new one,
		// and we are running inside WordPress right now — so re-mint, re-bind and
		// retry ONCE instead of returning an error the agent can only relay.
		//
		// Safe to retry: the credential is rejected on the service's FIRST call
		// into the site, so nothing was written on the failed attempt.
		if ( ! $result['ok'] && 'wp_credential_rejected' === $result['code'] && Helper::reprovision_app_password() ) {
			$result = Brain_Client::post( '/import', $payload );
		}

		if ( ! $result['ok'] ) {
			return $this->map_failure( $result );
		}

		// Consume the consent token only now that the service accepted the write.
		// Burning it inside check_consent meant a pre-write failure (service
		// unreachable, theme drift, load shed) killed the token while the failure
		// text told the agent to retry — dragging the user through a second
		// approval for a change that never happened. The fingerprint still binds
		// the surviving token to exactly the consequences that were approved.
		if ( $destructive ) {
			delete_transient( 'zipai_import_confirm_' . $fingerprint );
		}

		return $this->map_success( $result['data'], $layout, $status, $repeat_key );
	}

	/**
	 * Turn a successful service response into the agent-facing result.
	 *
	 * @param array<string,mixed> $data       Decoded server response.
	 * @param string              $layout     Requested layout.
	 * @param string              $status     Requested post status.
	 * @param string              $repeat_key Transient key guarding repeat calls.
	 * @return array<string,mixed>
	 */
	private function map_success( array $data, string $layout, string $status, string $repeat_key ) {
		$pages = isset( $data['pages'] ) && is_array( $data['pages'] ) ? $data['pages'] : array();
		$page  = isset( $pages[0] ) && is_array( $pages[0] ) ? $pages[0] : array();

		// The service reports per-page success inside a 2xx. A page that failed
		// must not be announced as an import.
		//
		// A 2xx failure row is NOT proof nothing was written: the committer
		// creates the page, then writes GBS / the Style Guide / the shared
		// chrome, and a throw at any of those steps leaves a real `post_id` on
		// the row (the site-wide chrome write does exactly this). A page that
		// exists is reported rather than denied. Claiming "nothing was added"
		// here left a PUBLISHED page behind (the draft flip below never runs)
		// while telling the agent otherwise.
		if ( empty( $page['success'] ) ) {
			$detail       = isset( $page['error'] ) && is_string( $page['error'] ) ? $page['error'] : 'the service did not say why';
			$failed_id    = $this->int_field( $page, 'post_id' );
			$failed_url   = $this->str_field( $page, 'page_url' );
			$failure_data = array();
			$next         = 'Fix the reported problem in the HTML and call this tool again.';

			if ( $failed_id > 0 ) {
				$failure_data['post_id'] = $failed_id;
				if ( '' !== $failed_url ) {
					$failure_data['page_url'] = $failed_url;
				}
				// Created as `publish` by the committer, and the draft flip is
				// downstream of this branch — so it is live right now.
				$failure_data['status'] = 'publish';
				$detail                .= sprintf( ' The page WAS created (post %d) and is PUBLISHED. Tell the user, and delete it if they do not want it.', $failed_id );
				$next                   = 'Tell the user the page exists and is public, then fix the reported problem and re-run.';
			} else {
				$next .= ' Nothing was added to the site.';
			}

			// A styling-contract rejection arrives on the page ROW, not as a typed
			// status — the importer catches its own throw per page. It signals the
			// CONVERTER emitted a banned block attribute (inline CSS in the
			// submitted HTML is fine and gets translated), so this is our bug, not
			// the caller's: pass the exact blocks and keys through for a report
			// rather than telling the agent to rewrite its HTML.
			if ( isset( $page['violations'] ) && is_array( $page['violations'] ) ) {
				$failure_data['violations'] = $page['violations'];
				$next                       = 'This is a converter fault on our side, not a problem with your HTML. Report the listed blocks and keys to the user and do not simply retry; the same HTML will fail identically.';
			}

			return Response::error( 'The page could not be imported: ' . $detail, $next, $failure_data );
		}

		$post_id  = $this->int_field( $page, 'post_id' );
		$page_url = $this->str_field( $page, 'page_url' );

		// The service publishes; honour a `draft` request here, where the post is
		// local. Done after the commit so chrome/design writes are complete —
		// which means the page IS briefly public. If the flip fails, the caller
		// must be told the page is live rather than trusting `status: draft`.
		$effective_status = $status;
		if ( 'draft' === $status && $post_id > 0 ) {
			$flipped = wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				),
				true
			);
			if ( is_wp_error( $flipped ) ) {
				$effective_status = 'publish';
			}
		}

		$data_out = array(
			'post_id'     => $post_id,
			'page_url'    => $page_url,
			'slug'        => $this->str_field( $page, 'slug' ),
			'block_count' => $this->int_field( $page, 'block_count' ),
			'status'      => $effective_status,
			'layout'      => $layout,
		);

		set_transient( $repeat_key, $data_out, self::REPEAT_TTL );

		$message = sprintf(
			'Imported %d blocks into "%s" (%s).',
			$data_out['block_count'],
			'' !== $page_url ? $page_url : 'the new page',
			'draft' === $effective_status ? 'saved as a draft, not public yet' : 'published'
		);
		if ( $effective_status !== $status ) {
			$message .= ' NOTE: the page could not be switched back to a draft, so it is PUBLIC. Tell the user.';
		}
		// A slug the site had to rewrite is worth saying out loud — the agent may
		// have told the user a URL that does not exist.
		$requested = $this->str_field( $page, 'submitted_slug' );
		if ( '' !== $requested && '' !== $data_out['slug'] && $requested !== $data_out['slug'] ) {
			$message .= sprintf( ' The slug "%s" was taken, so the page uses "%s".', $requested, $data_out['slug'] );
		}
		return Response::success( $message, $data_out );
	}

	/**
	 * Turn a service failure into an actionable agent response.
	 *
	 * @param array{ok: bool, status: int, code: string, message: string, data: array<string,mixed>} $result Client result.
	 * @return array<string,mixed>
	 */
	private function map_failure( array $result ) {
		$code    = $result['code'];
		$message = $result['message'];
		$data    = array();

		// One table, so a new service code cannot quietly fall through to a
		// generic message. `suggestion` is the agent's next action, not prose.
		$suggestions = array(
			'no_wordpress_credentials' => 'Ask the user to reconnect WordPress in WP Admin → Settings → ZIP AI.',
			'wp_credential_rejected'   => 'WordPress refused the stored application password, so nothing was written. Ask the user to open WP Admin → Settings → ZIP AI and reconnect the site (this re-issues the password), then call this tool again. Do not retry before they do; it will fail identically.',
			'contract_violation'       => 'This is a converter fault on our side, not a problem with the submitted HTML; inline CSS is legitimate and gets translated. Nothing was imported. Report the listed blocks and attributes to the user; do not simply retry, the same HTML will fail identically.',
			'payload_too_large'        => 'The HTML is too large for one call. Split it into separate pages and import them one at a time.',
			'import_busy'              => 'Wait for the number of seconds in retry_after_seconds, then call this tool again.',
			'import_in_progress'       => 'Another import is already running on this site. Wait for it to finish, then retry.',
			'theme_drift'              => 'The site theme changed while importing. Nothing was written; call this tool again.',
			'chrome_reader_pending'    => 'This site\'s ZIP AI plugin is too old to apply an imported header and footer. Ask the user to update it, or retry with layout "match_site".',
			'site_mismatch'            => 'This site is not the one the connected ZIP AI account is bound to. Ask the user to reconnect it.',
			'brain_unreachable'        => 'The ZIP AI service could not be reached. Nothing was written; retry in a minute.',
			'bad_response'             => 'The ZIP AI service replied in a form this site could not read. Retry; if it repeats, the user should contact support.',
		);

		// 413 never reaches the service's own taxonomy — the request is refused
		// at the transport by its byte cap.
		if ( 413 === $result['status'] ) {
			$code    = 'payload_too_large';
			$message = 'The HTML is larger than the import service accepts in one request.';
		}

		if ( 'contract_violation' === $code && isset( $result['data']['violations'] ) && is_array( $result['data']['violations'] ) ) {
			// Structured, because this is the one failure the calling model can
			// fix by itself — but only if it is told which block carried which
			// banned attribute.
			$data['violations'] = $result['data']['violations'];
		}
		if ( 'import_busy' === $code ) {
			$retry = $this->int_field( $result['data'], 'retry_after_seconds' );
			if ( $retry > 0 ) {
				$data['retry_after_seconds'] = $retry;
			}
		}
		$suggestion = isset( $suggestions[ $code ] ) ? $suggestions[ $code ] : 'Report this to the user; nothing was imported.';

		return Response::error( $message, $suggestion, $data );
	}

	/**
	 * Whether a request needs explicit confirmation before it may write.
	 *
	 * @param string $layout       Requested layout.
	 * @param bool   $set_homepage Whether the front page would change.
	 * @return bool
	 */
	private function is_destructive( string $layout, bool $set_homepage ) {
		return Import_Impact::needs_confirmation( $layout, $set_homepage );
	}

	/**
	 * Verify a confirmation token, or produce the impact response that mints one.
	 *
	 * @param string $token        Token supplied by the caller.
	 * @param string $fingerprint  Fingerprint of this exact request.
	 * @param string $layout       Requested layout.
	 * @param bool   $set_homepage Whether the front page would change.
	 * @return array{granted: bool, response: array<string,mixed>}
	 */
	private function check_consent( string $token, string $fingerprint, string $layout, bool $set_homepage ) {
		$key = 'zipai_import_confirm_' . $fingerprint;

		if ( '' !== $token ) {
			$expected = get_transient( $key );
			if ( is_string( $expected ) && hash_equals( $expected, $token ) ) {
				// NOT consumed here: execute() burns the token only after the
				// service accepts the write, so a pre-write failure leaves it
				// valid for the retry the failure text asks for. Single-use
				// still holds — a successful write deletes it, and the repeat
				// gate answers the window between the write and its expiry.
				return array(
					'granted'  => true,
					'response' => array(),
				);
			}
			// A token that does not match this request is not an error to
			// paper over: either it expired, or the HTML/layout changed after
			// approval — which means the user approved different consequences.
			return array(
				'granted'  => false,
				'response' => Response::error(
					'That confirmation is no longer valid: it expired, was already used, or the request changed after it was approved.',
					'Call this tool again WITHOUT confirm_token to get the current impact list, show it to the user, and use the new token.'
				),
			);
		}

		// The impact copy is shared with the wizard, so it is translated — but
		// this consumer is an AI client whose surrounding text is English. Pin
		// the locale so the consent block is not half-translated.
		$switched = function_exists( 'switch_to_locale' ) ? switch_to_locale( 'en_US' ) : false;
		$impact   = Import_Impact::for_layout( $layout, $set_homepage );
		if ( $switched ) {
			restore_current_locale();
		}
		$fresh = wp_generate_password( 32, false );
		set_transient( $key, $fresh, self::CONFIRM_TTL );

		// The token and the impact list are repeated IN THE MESSAGE, not only in
		// `data`. Some MCP transports keep just the message string on a failed
		// tool call and drop every structured field — losing the token there
		// makes the consent flow impossible to complete, because the agent is
		// told to come back with something it was never given.
		$narrative = "NOTHING WAS IMPORTED. This request changes more than the new page, so it needs the user's approval first.\n\nWHAT WILL CHANGE:\n- "
			. implode( "\n- ", $impact['change'] )
			. "\n\nWHAT WILL NOT CHANGE:\n- "
			. implode( "\n- ", $impact['keep'] )
			. "\n\nReversible: " . $impact['reversible']
			. "\n\nShow the lists above to the user verbatim. ONLY if they approve, call this tool again with the same arguments plus confirm_token: "
			. $fresh
			. sprintf( ' (valid for %d seconds).', self::CONFIRM_TTL );

		return array(
			'granted'  => false,
			'response' => Response::error(
				$narrative,
				'Relay will_change and will_not_change to the user in your own message, then repeat the call with confirm_token.',
				array(
					'needs_confirmation' => true,
					'confirm_token'      => $fresh,
					'expires_in_seconds' => self::CONFIRM_TTL,
					'will_change'        => $impact['change'],
					'will_not_change'    => $impact['keep'],
					'reversible'         => $impact['reversible'],
				)
			),
		);
	}

	/**
	 * Stable fingerprint of one import request. Binds a confirmation to the exact
	 * consequences the user approved: change the HTML, the layout, the status or
	 * the homepage flag and the old token stops working.
	 *
	 * @param string $html         Page HTML.
	 * @param string $layout       Requested layout.
	 * @param string $status       Requested post status.
	 * @param bool   $set_homepage Whether the front page would change.
	 * @return string
	 */
	private function fingerprint( string $html, string $layout, string $status, bool $set_homepage ) {
		return md5(
			implode(
				'|',
				array(
					(string) get_current_user_id(),
					get_stylesheet(),
					$layout,
					$status,
					$set_homepage ? '1' : '0',
					md5( $html ),
				)
			)
		);
	}

	/**
	 * Read an enum argument, falling back to the default for anything unexpected.
	 *
	 * @param array<string,mixed> $args    Input arguments.
	 * @param string              $key     Argument name.
	 * @param string[]            $allowed Allowed values.
	 * @param string              $default Default value.
	 * @return string
	 */
	private function enum_arg( array $args, string $key, array $allowed, string $default ) {
		$value = isset( $args[ $key ] ) && is_string( $args[ $key ] ) ? $args[ $key ] : '';
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Read a string out of a decoded service response. Everything crossing that
	 * boundary is untyped, so each field is narrowed at the point of use — the
	 * same discipline the importer's own `strField`/`numField` readers apply to
	 * WordPress REST responses.
	 *
	 * @param array<mixed,mixed> $row Decoded row.
	 * @param string             $key Field name.
	 * @return string Empty string when absent or not a string.
	 */
	private function str_field( array $row, string $key ) {
		return isset( $row[ $key ] ) && is_string( $row[ $key ] ) ? $row[ $key ] : '';
	}

	/**
	 * Read an integer out of a decoded service response.
	 *
	 * @param array<mixed,mixed> $row Decoded row.
	 * @param string             $key Field name.
	 * @return int Zero when absent or not numeric.
	 */
	private function int_field( array $row, string $key ) {
		return isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ? (int) $row[ $key ] : 0;
	}

	/**
	 * Returns the JSON Schema for this ability's response.
	 *
	 * @return array<string,mixed> JSON Schema describing the response shape.
	 */
	public function get_output_schema() {
		return array(
			'type'       => 'object',
			'required'   => array( 'success' ),
			'properties' => array(
				'success'    => array( 'type' => 'boolean' ),
				'message'    => array( 'type' => 'string' ),
				'error'      => array( 'type' => 'string' ),
				'suggestion' => array( 'type' => 'string' ),
				'data'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'            => array( 'type' => 'integer' ),
						'page_url'           => array( 'type' => 'string' ),
						'slug'               => array( 'type' => 'string' ),
						'block_count'        => array( 'type' => 'integer' ),
						'status'             => array( 'type' => 'string' ),
						'layout'             => array( 'type' => 'string' ),
						'needs_confirmation' => array( 'type' => 'boolean' ),
						'confirm_token'      => array( 'type' => 'string' ),
						'will_change'        => array( 'type' => 'array' ),
						'will_not_change'    => array( 'type' => 'array' ),
						'reversible'         => array( 'type' => 'string' ),
						'violations'         => array( 'type' => 'array' ),
					),
				),
			),
		);
	}
}
