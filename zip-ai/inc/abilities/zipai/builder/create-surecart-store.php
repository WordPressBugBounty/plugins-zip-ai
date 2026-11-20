<?php
/**
 * Create SureCart Store — a server-side ability. The website build sells from SureCart,
 * and a site with SureCart active and no store connected gets one here: an unclaimed
 * store in the site's name and its admin's email, which takes test payments until the
 * owner claims it in SureCart. It goes through SureCart's own partner entry point
 * (`AccountService::setup()`), which never replaces a connected store.
 *
 * Hidden from the LLM catalog (`meta.visibility = internal`): the build calls it
 * (wp-importer `surecartCommit`), never the chat.
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Abilities\Zipai\Builder;

use ZipAI\MCP\Classes\Abilities\Abstract_Ability;
use ZipAI\MCP\Classes\Core\Plugin_Abilities_Toggler;
use ZipAI\MCP\Classes\Core\Response;
use ZipAI\MCP\Classes\Core\Tool_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Ability: give the site a SureCart store when it has none.
 */
class CreateSurecartStore extends Abstract_Ability {

	/**
	 * The source SureCart credits the store to (one of SureCart's own sources).
	 *
	 * @var string
	 */
	public const SOURCE = 'zipwp';

	/**
	 * Held while a store is being made.
	 *
	 * @var string
	 */
	private const LOCK = 'zip_ai_surecart_store_creating';

	/**
	 * Flags this ability as destructive (mutates site state).
	 *
	 * @var bool
	 */
	protected $is_destructive = true;

	/**
	 * Configures the ability's id, label, description and metadata.
	 *
	 * @return void
	 */
	public function configure() {
		$this->id          = 'zipai/create-surecart-store';
		$this->label       = 'Create SureCart Store (server-side)';
		$this->description = 'Give the site a SureCart store when none is connected: an unclaimed store in the site name and admin email, test payments until the owner claims it. A connected store is returned as it is. Internal: the website build calls it.';
		$this->capability  = 'manage_options';

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
	 * No input: the store is the site's, named for it.
	 *
	 * @return array<string,mixed> JSON Schema describing accepted arguments.
	 */
	public function get_input_schema() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => new \stdClass(),
		);
	}

	/**
	 * The site's store: made when none is connected, recorded as the build's.
	 *
	 * @param array<string,mixed> $args Validated input arguments.
	 * @return array<string,mixed> Standardized success or error response.
	 */
	public function execute( $args ) {
		$service = class_exists( '\SureCart' ) ? \SureCart::account() : null;
		if ( ! is_object( $service ) || ! method_exists( $service, 'setup' ) || ! method_exists( $service, 'isConnected' ) ) {
			return self::coded_error( 'SureCart is not active, or too old to create a store.', 'surecart_unavailable' );
		}
		$created = ! $service->isConnected();
		if ( $created ) {
			// One store per site: a second call while the first is making it would orphan one.
			// `add_option` is one insert: of two calls at once, one gets it. A lock a crashed call left is taken over after a minute.
			if ( ! add_option( self::LOCK, time(), '', false ) ) {
				$held = get_option( self::LOCK );
				if ( time() - ( is_numeric( $held ) ? (int) $held : 0 ) < MINUTE_IN_SECONDS ) {
					return self::coded_error( 'A store is already being created for this site.', 'surecart_store_in_progress' );
				}
				update_option( self::LOCK, time(), false );
			}
			// SureCart reads the source when it makes the store; an owner's own stays.
			add_option( 'surecart_source', self::SOURCE );
		}
		// SureCart refuses a name under three characters: a short site title is said as a store.
		// The title as written (WordPress stores it escaped), counted in characters.
		$name = trim( wp_strip_all_tags( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) ) );
		try {
			$account = $service->setup(
				array(
					'account_name' => mb_strlen( $name ) >= 3 ? $name : trim( $name . ' Store' ),
					'email'        => get_option( 'admin_email' ),
				)
			);
		} finally {
			delete_option( self::LOCK );
		}
		if ( is_wp_error( $account ) ) {
			return self::coded_error( $account->get_error_message(), (string) $account->get_error_code() );
		}
		if ( $created ) {
			// the store is the build's to set up (wp-importer `surecartCommit`, mirrored)
			update_option( Plugin_Abilities_Toggler::SURECART_INSTALLED_OPTION, '1' );
		}
		$found    = class_exists( '\SureCart\Models\Account' ) ? \SureCart\Models\Account::find() : null;
		$currency = is_object( $found ) && isset( $found->currency ) && is_string( $found->currency ) ? $found->currency : '';
		// claimed unless the store says it is not (an unread store is never told "unclaimed")
		$claimed = ! ( is_object( $found ) && isset( $found->claimed ) && false === $found->claimed );
		return Response::success(
			$created ? 'Made an unclaimed SureCart store for the site.' : 'The site already has a SureCart store.',
			array(
				'created'  => $created,
				'currency' => strtolower( $currency ),
				'claimed'  => $claimed,
			)
		);
	}

	/**
	 * A failure the caller reads by its code.
	 *
	 * @param string $message What went wrong.
	 * @param string $code    Machine code.
	 * @return array<string,mixed>
	 */
	private static function coded_error( string $message, string $code ): array {
		return array(
			'success'    => false,
			'error'      => $message,
			'error_code' => $code,
		);
	}
}
