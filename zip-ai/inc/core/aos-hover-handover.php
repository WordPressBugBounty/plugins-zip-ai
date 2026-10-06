<?php
/**
 * Aos Hover Handover — a card's own hover eases again after its scroll entrance.
 *
 * Spectra runs scroll entrances with AOS, which keeps `transition-property:
 * opacity, transform` on an element after its entrance has played, so the
 * element's own hover (translate, scale, box-shadow) snaps instead of easing.
 * When a play-once entrance ends, its `data-aos*` attributes come off and the
 * element's own transition is back. Only an entrance marked play-once
 * (`data-aos-once="true"`) is handed over: AOS replays by default, and a replaying
 * entrance keeps its attributes. It rides Spectra's own AOS init, so it prints only
 * on a page where Spectra loads AOS, and AOS itself is off under reduced motion.
 *
 * @since 0.0.13
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attaches the handover to Spectra's AOS init — see the file header.
 */
class Aos_Hover_Handover {

	/**
	 * Spectra's AOS init script, registered and enqueued on `wp_footer` (priority 10).
	 *
	 * @var string
	 */
	const AOS_INIT_HANDLE = 'spectra-aos-init';

	/**
	 * The handover: when the entrance's own opacity or transform transition ends on
	 * the element (not a pseudo-element), a played play-once entrance gives the
	 * element its transition back.
	 *
	 * @var string
	 */
	const SCRIPT = "document.addEventListener('transitionend',function(e){var el=e.target;if(e.pseudoElement||(e.propertyName!=='opacity'&&e.propertyName!=='transform')||!el.classList||!el.classList.contains('aos-animate')||!el.hasAttribute('data-aos')||el.getAttribute('data-aos-once')!=='true')return;Array.prototype.slice.call(el.attributes).forEach(function(a){if(a.name.indexOf('data-aos')===0)el.removeAttribute(a.name);});});";

	/**
	 * Hook registration: after Spectra enqueues its init (priority 10), before
	 * footer scripts print (priority 20).
	 */
	public static function init(): void {
		add_action( 'wp_footer', array( __CLASS__, 'attach' ), 11 );
	}

	/**
	 * Attach the handover to Spectra's AOS init when this page loads it.
	 */
	public static function attach(): void {
		if ( ! wp_script_is( self::AOS_INIT_HANDLE, 'enqueued' ) ) {
			return;
		}
		wp_add_inline_script( self::AOS_INIT_HANDLE, self::SCRIPT, 'after' );
	}
}
