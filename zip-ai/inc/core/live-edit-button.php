<?php
/**
 * "Edit with ZIP AI" on the live site.
 *
 * The assistant is admin-only and loads nothing on the public site. A logged-in
 * admin viewing a page or post they can edit in the block editor gets one small
 * floating link, plain HTML and inline CSS, that opens it in the editor with the
 * ZIP AI chat already open (`zipwp_open_assistant`, read by wp-bridge-host.js).
 *
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the live site's "Edit with ZIP AI" link.
 */
class Live_Edit_Button {

	/**
	 * Hook the link into the page footer.
	 */
	public function __construct() {
		add_action( 'wp_footer', array( $this, 'render' ) );
	}

	/**
	 * The editor link for the page shown, when this user can use ZIP AI on it.
	 *
	 * @return string '' when the link does not apply.
	 */
	public static function edit_url() {
		if ( is_admin() || is_customize_preview() || ! is_singular() || ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! use_block_editor_for_post( $post_id ) ) {
			return '';
		}
		$link = get_edit_post_link( $post_id, 'raw' );
		return $link ? add_query_arg( 'zipwp_open_assistant', '1', $link ) : '';
	}

	/**
	 * Print the floating link.
	 *
	 * @return void
	 */
	public function render() {
		/**
		 * Whether the live site shows the "Edit with ZIP AI" link.
		 *
		 * @param bool $show Default true.
		 */
		if ( ! apply_filters( 'zip_ai_show_live_edit_button', true ) ) {
			return;
		}
		$url = self::edit_url();
		if ( '' === $url ) {
			return;
		}
		?>
		<a href="<?php echo esc_url( $url ); ?>" id="zip-ai-live-edit" style="position:fixed;right:24px;bottom:24px;z-index:99999;display:inline-flex;align-items:center;gap:8px;padding:8px 14px 8px 8px;border-radius:999px;background:#0A0A0C;color:#fff;font:500 14px/1 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;text-decoration:none;box-shadow:0 8px 24px rgba(0,0,0,.2),0 2px 8px rgba(0,0,0,.1);">
			<svg width="24" height="24" viewBox="0 0 32 32" fill="none" aria-hidden="true"><rect width="32" height="32" rx="8.88889" fill="#FF580E"/><path fill="#FFF" d="M10.6163 9.04474C14.6886 8.38907 21.5161 8.52189 22.6036 8.55646C22.6688 8.55139 22.7246 8.63189 22.6797 8.68634C22.4473 9.07806 21.6058 10.2989 19.3233 12.0047C19.2606 12.0435 19.2876 12.1771 19.3692 12.1707C20.1605 12.2111 22.8025 12.243 26.9971 11.7996C27.0778 11.7942 27.1306 11.857 27.1036 11.9266C26.8268 12.5935 25.3999 15.3714 20.4708 17.6902C20.3757 17.7318 20.4041 17.8824 20.502 17.8748L24.6436 17.8934C24.7262 17.9042 24.7826 18.0008 24.7217 18.0565C23.818 19.1117 18.3426 24.972 10.2803 23.118C7.10173 22.3795 4.9017 19.4422 4.88873 15.8934C4.87595 12.3444 7.01332 9.61306 10.6163 9.04474ZM12.6895 13.7557C12.612 13.5437 12.312 13.5437 12.2344 13.7557L12.003 14.3885C11.8213 14.8846 11.5337 15.3352 11.1602 15.7088C10.7865 16.0825 10.3352 16.3699 9.83892 16.5516L9.20611 16.784C8.99451 16.8617 8.99436 17.1615 9.20611 17.2391L9.83892 17.4705C10.3352 17.6522 10.7865 17.9396 11.1602 18.3133C11.5339 18.687 11.8213 19.1383 12.003 19.6346L12.2344 20.2664C12.312 20.4787 12.612 20.4787 12.6895 20.2664L12.921 19.6346C13.1026 19.1383 13.391 18.687 13.7647 18.3133C14.1383 17.9397 14.5889 17.6522 15.085 17.4705L15.7178 17.2391C15.9301 17.1615 15.93 16.8616 15.7178 16.784L15.085 16.5516C14.5889 16.3699 14.1383 16.0824 13.7647 15.7088C13.391 15.3351 13.1026 14.8847 12.921 14.3885L12.6895 13.7557ZM15.792 11.8924C15.7533 11.7827 15.6033 11.7827 15.5645 11.8924L15.4483 12.2195C15.3575 12.4763 15.2142 12.7098 15.0274 12.9031C14.8405 13.0965 14.6144 13.2456 14.3663 13.3397L14.0499 13.4588C13.9442 13.499 13.9442 13.6549 14.0499 13.6951L14.3663 13.8143C14.6144 13.9083 14.8405 14.0574 15.0274 14.2508C15.2142 14.4441 15.3575 14.6776 15.4483 14.9344L15.5645 15.2615C15.6033 15.3714 15.7533 15.3714 15.792 15.2615L15.9073 14.9344C15.9981 14.6776 16.1423 14.4442 16.3292 14.2508C16.516 14.0575 16.7412 13.9083 16.9893 13.8143L17.3057 13.6951C17.4119 13.655 17.4119 13.4989 17.3057 13.4588L16.9893 13.3397C16.7413 13.2456 16.516 13.0965 16.3292 12.9031C16.1423 12.7098 15.9981 12.4763 15.9073 12.2195L15.792 11.8924ZM26.9971 11.7996H26.9952L27.0118 11.7986C27.0069 11.7992 27.002 11.7991 26.9971 11.7996Z"/></svg>
			<?php esc_html_e( 'Edit with ZIP AI', 'zip-ai' ); ?>
		</a>
		<?php
		$this->render_tour();
	}

	/**
	 * Once per browser: the admin tours' spotlight on the link, with what it does.
	 * Plain HTML and an inline script, like the link; the same localStorage key
	 * scheme as the admin tours (`zip-ai-tour:<id>`).
	 *
	 * @return void
	 */
	private function render_tour() {
		?>
		<div id="zip-ai-live-edit-tour" hidden style="position:fixed;inset:0;z-index:100000;font:14px/1.45 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
			<div data-ring style="position:fixed;border-radius:999px;box-shadow:0 0 0 2px #FF580E,0 0 0 9999px rgba(0,0,0,.45);"></div>
			<div role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Edit with ZIP AI', 'zip-ai' ); ?>" style="position:fixed;right:24px;width:288px;box-sizing:border-box;display:flex;flex-direction:column;gap:6px;padding:14px;border-radius:12px;background:#fff;color:#4b5563;text-align:left;box-shadow:0 20px 40px rgba(0,0,0,.25);">
				<span style="font-size:14px;font-weight:600;line-height:1.35;color:#0A0A0C;"><?php esc_html_e( 'Edit this page with ZIP AI', 'zip-ai' ); ?></span>
				<span style="font-size:13px;"><?php esc_html_e( 'Opens this page in the editor with ZIP AI ready. Ask for any change in your own words.', 'zip-ai' ); ?></span>
				<button type="button" data-done style="align-self:flex-end;margin-top:6px;padding:4px 12px;border:0;border-radius:6px;background:#3D4592;color:#fff;font:inherit;font-size:12.5px;font-weight:500;line-height:1.5;cursor:pointer;"><?php esc_html_e( 'Got it', 'zip-ai' ); ?></button>
			</div>
		</div>
		<script>
		( function () {
			var key = 'zip-ai-tour:live-edit';
			try {
				if ( localStorage.getItem( key ) ) {
					return;
				}
				localStorage.setItem( key, '1' );
			} catch ( e ) {
				return;
			}
			var tour = document.getElementById( 'zip-ai-live-edit-tour' );
			var link = document.getElementById( 'zip-ai-live-edit' );
			var ring = tour.querySelector( '[data-ring]' );
			var card = tour.querySelector( '[role="dialog"]' );
			var done = tour.querySelector( '[data-done]' );
			var place = function () {
				var r = link.getBoundingClientRect();
				ring.style.top = ( r.top - 4 ) + 'px';
				ring.style.left = ( r.left - 4 ) + 'px';
				ring.style.width = ( r.width + 8 ) + 'px';
				ring.style.height = ( r.height + 8 ) + 'px';
				card.style.bottom = ( window.innerHeight - r.top + 12 ) + 'px';
			};
			var end = function () {
				tour.hidden = true;
				window.removeEventListener( 'resize', place );
				document.removeEventListener( 'keydown', onKey, true );
			};
			var onKey = function ( e ) {
				if ( 'Escape' === e.key ) {
					end();
				}
			};
			done.addEventListener( 'click', end );
			window.addEventListener( 'resize', place );
			document.addEventListener( 'keydown', onKey, true );
			tour.hidden = false;
			place();
			done.focus();
		}() );
		</script>
		<?php
	}
}
