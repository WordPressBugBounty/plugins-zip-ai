<?php
/**
 * Snippet Lint — production gate that prevents agent + human authors from
 * embedding targeting/routing checks inside snippet code instead of using
 * the structured `conditions[]` field.
 *
 * Why this exists:
 *   When the agent slips up — or a schema rejection elsewhere makes it think
 *   `conditions[]` "doesn't work" — it falls back to rewriting the snippet
 *   body with `if ( ! is_home() ) return;` or `if ( get_the_ID() !== 2805 )`
 *   wrappers. Once that pattern lands in HEAD, it survives migrations, leaks
 *   into versions, and is invisible at the manifest layer (executor sees the
 *   snippet as "global" and runs it everywhere; the body silently filters).
 *   We block it at write time so it never reaches disk.
 *
 *   This is the production version of what was previously enforced only via
 *   tool-description nudges. Author intent: "if a snippet wants targeting,
 *   it MUST come through `conditions[]` — code is for what to do, not where."
 *
 * Filterable:
 *   - `zip_ai_snippets_lint_enabled`            (bool)  — kill switch.
 *   - `zip_ai_snippets_lint_targeting_functions` (array) — extend or override the
 *     forbidden function map (key = function name, value = remediation hint).
 *
 * @since 0.0.5
 * @package zip-ai
 */

namespace ZipAI\MCP\Classes\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Snippet_Lint {

	/**
	 * Default forbidden-targeting function → remediation map.
	 *
	 * Each entry says: "if you find this function in snippet code, the author
	 * is doing routing/targeting; the right place for that is the
	 * `conditions[]` payload. Here is the equivalent shape."
	 *
	 * @return array<string,string>
	 */
	public static function targeting_function_map() {
		$map      = array(
			// Page-type routing
			'is_home'           => "conditions:[{type:'page',operator:'is',value:'home'}]",
			'is_front_page'     => "conditions:[{type:'page',operator:'is',value:'home'}]",
			'is_single'         => "conditions:[{type:'page',operator:'is',value:'single'}]",
			'is_singular'       => "conditions:[{type:'page',operator:'is',value:'single'}]",
			'is_page'           => "conditions:[{type:'page',operator:'is',value:'page'}] (or {type:'post',value:'<id>'} for a specific page)",
			'is_archive'        => "conditions:[{type:'page',operator:'is',value:'archive'}]",
			'is_category'       => "conditions:[{type:'page',operator:'is',value:'archive'}]",
			'is_tag'            => "conditions:[{type:'page',operator:'is',value:'archive'}]",
			'is_tax'            => "conditions:[{type:'page',operator:'is',value:'archive'}]",
			'is_search'         => "conditions:[{type:'page',operator:'is',value:'search'}]",
			'is_404'            => "conditions:[{type:'page',operator:'is',value:'404'}]",

			// Specific post / post-type
			'get_the_ID'        => "conditions:[{type:'post',operator:'is',value:'<id>'}]",
			'get_post_type'     => "conditions:[{type:'post_type',operator:'is',value:'<type>'}]",

			// User / role
			'is_user_logged_in' => "conditions:[{type:'user',operator:'is',value:'logged_in'}]",
			'current_user_can'  => "conditions:[{type:'user_role',operator:'in',values:['administrator','editor',...]}]",

			// Device
			'wp_is_mobile'      => "conditions:[{type:'device',operator:'is',value:'mobile'}]",

			// Admin / frontend split
			'is_admin'          => "execution.<type>.scope = 'admin' (admin-only) or 'frontend' (frontend-only)",
		);
		$filtered = apply_filters( 'zip_ai_snippets_lint_targeting_functions', $map );
		if ( is_array( $filtered ) ) {
			/**
			 * Narrowed type for `$filtered`.
			 *
			 * @var array<string,string> $filtered
			 */
			return $filtered;
		}
		return $map;
	}

	/**
	 * Detect forbidden inline-targeting calls inside snippet PHP code.
	 *
	 * Token-based scan — comments and strings are skipped automatically.
	 *
	 * @param string $php_code Raw snippet body (with or without `<?php`).
	 * @return array<int,array{function:string,line:int,hint:string}>
	 */
	public static function detect_inline_targeting( $php_code ) {
		if ( ! apply_filters( 'zip_ai_snippets_lint_enabled', true ) ) {
			return array();
		}
		if ( '' === trim( (string) $php_code ) ) {
			return array();
		}

		// Ensure opening tag so `token_get_all` parses correctly even when
		// callers strip the leading `<?php`.
		$prefixed = ltrim( $php_code );
		$has_tag  = stripos( $prefixed, '<?php' ) === 0 || stripos( $prefixed, '<?' ) === 0;
		$source   = $has_tag ? $php_code : ( '<?php ' . $php_code );

		try {
			$tokens = @token_get_all( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} catch ( \Throwable $e ) {
			// If parsing fails outright, downstream syntax check will report it.
			return array();
		}

		$map         = self::targeting_function_map();
		$hits        = array();
		$name_tokens = array( T_STRING );
		if ( defined( 'T_NAME_FULLY_QUALIFIED' ) ) {
			$name_tokens[] = T_NAME_FULLY_QUALIFIED;
		}

		// Track preceding non-whitespace token so we can ignore method calls
		// (`$obj->is_home()`) while still catching global calls such as
		// `\is_page()` and mixed-case variants.
		$prev_meaningful = null;

		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				$id   = $token[0];
				$text = $token[1];
				$line = $token[2];

				if ( in_array( $id, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}

				$function_name = strtolower( ltrim( $text, '\\' ) );
				if ( in_array( $id, $name_tokens, true ) && isset( $map[ $function_name ] ) ) {
					$is_method = ( T_OBJECT_OPERATOR === $prev_meaningful );
					if ( ! $is_method ) {
						// Adjust line number when we synthesized the leading tag.
						$reported_line = $has_tag ? $line : max( 1, $line );
						$hits[]        = array(
							'function' => $function_name,
							'line'     => $reported_line,
							'hint'     => $map[ $function_name ],
						);
					}
				}

				$prev_meaningful = $id;
			} else {
				// Single-character tokens (`(`, `;`, `?` etc.) — track as
				// non-method-operator so following identifiers count.
				$prev_meaningful = null;
			}
		}

		// Dedupe by function name keeping earliest line — agent only needs
		// to know each fingerprint once.
		$seen = array();
		$out  = array();
		foreach ( $hits as $h ) {
			if ( isset( $seen[ $h['function'] ] ) ) {
				continue;
			}
			$seen[ $h['function'] ] = true;
			$out[]                  = $h;
		}
		return $out;
	}

	/**
	 * Detect code that wraps the same hook the snippet executor already uses.
	 *
	 * The executor includes PHP snippet files on execution.<type>.hook. If the
	 * body then calls add_action( '<same hook>', ... ), that callback is
	 * registered while the hook is already running and will not fire in the
	 * current request. The snippet saves cleanly but appears broken.
	 *
	 * @param string $php_code Raw snippet body (with or without `<?php`).
	 * @param string $execution_hook Hook configured in execution.php.hook.
	 * @return array<int,array{function:string,hook:string,line:int}>
	 */
	public static function detect_redundant_hook_wrappers( $php_code, $execution_hook ) {
		if ( ! apply_filters( 'zip_ai_snippets_lint_enabled', true ) ) {
			return array();
		}
		$execution_hook = (string) $execution_hook;
		if ( '' === $execution_hook || '' === trim( (string) $php_code ) ) {
			return array();
		}

		$prefixed = ltrim( $php_code );
		$has_tag  = stripos( $prefixed, '<?php' ) === 0 || stripos( $prefixed, '<?' ) === 0;
		$source   = $has_tag ? $php_code : ( '<?php ' . $php_code );

		try {
			$tokens = @token_get_all( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} catch ( \Throwable $e ) {
			return array();
		}

		$hits  = array();
		$count = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
				continue;
			}
			$function = strtolower( $token[1] );
			if ( ! in_array( $function, array( 'add_action', 'add_filter' ), true ) ) {
				continue;
			}

			$arg = self::first_string_argument( $tokens, $i + 1 );
			if ( ! $arg || $arg['value'] !== $execution_hook ) {
				continue;
			}

			$hits[] = array(
				'function' => $function,
				'hook'     => $arg['value'],
				'line'     => $arg['line'],
			);
		}

		return $hits;
	}

	/**
	 * Detect top-level output on hooks that run before headers are safe.
	 *
	 * Direct echo/print on `init` or `wp_loaded` sends output before REST,
	 * redirects, cookies, and admin headers can be emitted. Output snippets
	 * should run on a render hook such as `wp_head`, `wp_footer`, or register
	 * their later callback from the early hook.
	 *
	 * @param string $php_code Raw snippet body (with or without `<?php`).
	 * @param string $execution_hook Hook configured in execution.php.hook.
	 * @return array<int,array{token:string,line:int,hook:string}>
	 */
	public static function detect_early_direct_output( $php_code, $execution_hook ) {
		$no_direct_output_hooks = array( 'plugins_loaded', 'init', 'wp_loaded', 'wp_enqueue_scripts', 'template_redirect', 'admin_init' );
		if ( ! in_array( (string) $execution_hook, $no_direct_output_hooks, true ) || '' === trim( (string) $php_code ) ) {
			return array();
		}

		$prefixed = ltrim( $php_code );
		$has_tag  = stripos( $prefixed, '<?php' ) === 0 || stripos( $prefixed, '<?' ) === 0;
		$source   = $has_tag ? $php_code : ( '<?php ' . $php_code );

		try {
			$tokens = @token_get_all( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} catch ( \Throwable $e ) {
			return array();
		}

		$depth = 0;
		$hits  = array();
		foreach ( $tokens as $token ) {
			$text = is_array( $token ) ? $token[1] : $token;
			if ( '{' === $text ) {
				++$depth;
				continue;
			}
			if ( '}' === $text ) {
				$depth = max( 0, $depth - 1 );
				continue;
			}
			if ( $depth > 0 || ! is_array( $token ) ) {
				continue;
			}
			if (
				in_array( $token[0], array( T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO ), true )
				|| ( T_INLINE_HTML === $token[0] && '' !== trim( $token[1] ) )
			) {
				$hits[] = array(
					'token' => strtolower( token_name( $token[0] ) ),
					'line'  => $token[2],
					'hook'  => (string) $execution_hook,
				);
			}
		}

		return $hits;
	}

	/**
	 * Find the first string argument after a function token.
	 *
	 * @param array<int,string|array{0:int,1:string,2:int}> $tokens Token stream from token_get_all().
	 * @param int                                           $offset Search offset.
	 * @return array{value:string,line:int}|null
	 */
	private static function first_string_argument( array $tokens, $offset ) {
		$depth = 0;
		$count = count( $tokens );
		for ( $i = $offset; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			$text  = is_array( $token ) ? $token[1] : $token;

			if ( '(' === $text ) {
				++$depth;
				continue;
			}
			if ( ')' === $text ) {
				--$depth;
				if ( $depth <= 0 ) {
					return null;
				}
				continue;
			}
			if ( $depth < 1 ) {
				continue;
			}
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$value = trim( $token[1], "'\"" );
				return array(
					'value' => stripcslashes( $value ),
					'line'  => $token[2],
				);
			}
			if ( ',' === $text ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Format the detected hits into a single human + agent-readable error
	 * string. Agents can parse the deterministic prefix; humans can read
	 * the inline guidance.
	 *
	 * @param array<int,array{function:string,line:int,hint:string}> $hits Result of `detect_inline_targeting()`.
	 * @return string
	 */
	public static function format_violation( array $hits ) {
		if ( empty( $hits ) ) {
			return '';
		}
		$lines   = array();
		$lines[] = 'Inline targeting/routing detected in snippet code. Targeting MUST be expressed via the `conditions[]` field on the run-snippet tool call — not as `if ( ... ) return;` guards inside the snippet body.';
		$lines[] = 'Move each of the following calls out of the code and into `conditions[]`, then resubmit:';
		foreach ( $hits as $h ) {
			$lines[] = sprintf(
				'  • Line %d: `%s()` → %s',
				$h['line'],
				$h['function'],
				$h['hint']
			);
		}
		$lines[] = 'If you genuinely need one of these for non-routing logic (e.g. `current_user_can()` to gate a feature toggle, not page access), apply the `zip_ai_snippets_lint_targeting_functions` filter to whitelist it for this site.';
		return implode( "\n", $lines );
	}

	/**
	 * Format redundant hook wrapper hits.
	 *
	 * @param array<int,array{function:string,hook:string,line:int}> $hits Result of `detect_redundant_hook_wrappers()`.
	 * @return string
	 */
	public static function format_redundant_hook_violation( array $hits ) {
		if ( empty( $hits ) ) {
			return '';
		}

		$first = $hits[0];
		return sprintf(
			'Redundant hook wrapper detected. This snippet is already configured to run on `%1$s`, so `%2$s( \'%1$s\', ... )` inside the snippet body registers too late and will not run for the current request. Remove the wrapper and perform the work directly in the snippet body, or choose an earlier execution hook if the body must register a later hook.',
			$first['hook'],
			$first['function']
		);
	}

	/**
	 * Format early direct output hits.
	 *
	 * @param array<int,array{token:string,line:int,hook:string}> $hits Result of `detect_early_direct_output()`.
	 * @return string
	 */
	public static function format_early_output_violation( array $hits ) {
		if ( empty( $hits ) ) {
			return '';
		}

		$first = $hits[0];
		return sprintf(
			'Direct output detected on early/bootstrap hook `%s` at line %d. This can send headers too early, print in the wrong document phase, or break REST, redirects, cookies, and admin screens. Move execution.php.hook to a render hook such as `wp_footer`/`wp_head`, or register a later callback instead of echoing at top level.',
			$first['hook'],
			$first['line']
		);
	}

	/**
	 * Detect HTML wrapper tags inside JS or CSS snippet bodies.
	 *
	 * The executor pipes JS/CSS snippet contents through wp_add_inline_script /
	 * wp_add_inline_style, which already wraps the payload in a `<script>` /
	 * `<style>` tag. Per HTML5 raw-text-element parsing, the *closing* tag
	 * (`</script>`/`</style>`) inside the body terminates the wrapper —
	 * everything after becomes regular HTML, so the intended JS/CSS never
	 * executes (this is exactly the failure mode of pasting the canonical GA
	 * gtag.js snippet into a `js` snippet — its `</script>` killed the inline
	 * wrapper and the loader tag became dead text).
	 *
	 * We only flag the *closing* tag, not the opening: a literal `<script` or
	 * `<style` substring inside raw-text is inert and shows up legitimately in
	 * regex/string literals (`const re = /<script>/g`, `'<style>'.length`). An
	 * over-aggressive opening-tag block would false-positive too often.
	 *
	 * `<!--` is also flagged because it puts the HTML parser into the
	 * "script-data-escaped" state, changing how subsequent `</script>` tokens
	 * are interpreted and confusing JS strict-mode parsers; modern JS/CSS
	 * bodies have no reason to contain raw HTML comments — use JS/CSS comment syntax instead.
	 *
	 * @param string $code Raw snippet body.
	 * @param string $type Snippet file type (js|css).
	 * @return array<int,array{tag:string,line:int}> Hits — empty if clean.
	 */
	public static function detect_wrapper_tags( $code, $type ) {
		if ( ! apply_filters( 'zip_ai_snippets_lint_enabled', true ) ) {
			return array();
		}
		$code = (string) $code;
		if ( '' === trim( $code ) ) {
			return array();
		}

		if ( 'js' === $type ) {
			$patterns = array(
				'</script>' => '/<\/script\s*>/i',
				'<!--'      => '/<!--/',
			);
		} elseif ( 'css' === $type ) {
			$patterns = array(
				'</style>' => '/<\/style\s*>/i',
				'<!--'     => '/<!--/',
			);
		} else {
			return array();
		}

		$hits = array();
		foreach ( $patterns as $label => $regex ) {
			if ( preg_match( $regex, $code, $m, PREG_OFFSET_CAPTURE ) ) {
				$offset = (int) $m[0][1];
				$line   = substr_count( substr( $code, 0, $offset ), "\n" ) + 1;
				$hits[] = array(
					'tag'  => $label,
					'line' => $line,
				);
			}
		}
		return $hits;
	}

	/**
	 * Format wrapper-tag violation for agent + human consumption.
	 *
	 * @param array<int,array{tag:string,line:int}> $hits Result of `detect_wrapper_tags()`.
	 * @param string                                $type Snippet type (js|css).
	 * @return string
	 */
	public static function format_wrapper_tag_violation( array $hits, $type ) {
		if ( empty( $hits ) ) {
			return '';
		}
		$type     = (string) $type;
		$first    = $hits[0];
		$pipeline = 'js' === $type ? 'wp_add_inline_script' : 'wp_add_inline_style';
		$wrapper  = 'js' === $type ? '<script>' : '<style>';
		$bullets  = array();
		foreach ( $hits as $h ) {
			$bullets[] = sprintf( '  • Line %d: `%s`', $h['line'], $h['tag'] );
		}
		return sprintf(
			"%s snippet body contains HTML wrapper tags — found `%s` at line %d. The executor wraps %s snippets in a `%s...%s` tag via %s, so an inner closing tag terminates the wrapper prematurely and the surrounding markup never executes.\nHits:\n%s\nFix: pass only the body that belongs inside `%s` (no `<script>`/`<style>` tags, no HTML comments). For verbatim head/footer markup (external script loaders, JSON-LD, `<noscript>`, GA/GTM/FB Pixel tags), use `type:\"html\"` instead — its executor emits the file contents as-is on the configured hook.",
			strtoupper( $type ),
			$first['tag'],
			$first['line'],
			$type,
			$wrapper,
			str_replace( '<', '</', $wrapper ),
			$pipeline,
			implode( "\n", $bullets ),
			$wrapper
		);
	}

	/**
	 * Lint a non-PHP snippet body. JS/CSS run the wrapper-tag detector; HTML
	 * is emitted verbatim so it gets no body lint, but we reject `<?php` /
	 * `<?=` open tags inside HTML because the executor `echo`s the file as a
	 * static string — PHP open tags become literal text, never execute, and
	 * the author thinks their server-side logic is broken.
	 *
	 * @param string $code Raw snippet body.
	 * @param string $type Snippet type (js|css|html).
	 * @return \WP_Error|null
	 */
	public static function check_non_php( $code, $type ) {
		$type = (string) $type;
		if ( 'html' === $type ) {
			if ( preg_match( '/<\?(php|=)/i', (string) $code, $m, PREG_OFFSET_CAPTURE ) ) {
				$offset = (int) $m[0][1];
				$line   = substr_count( substr( (string) $code, 0, $offset ), "\n" ) + 1;
				return new \WP_Error(
					'php_in_html_forbidden',
					sprintf(
						'HTML snippet body contains a PHP open tag (`%s`) at line %d. HTML snippets are emitted verbatim by `echo $content` on the configured hook — PHP open tags become literal text and never execute. For server-side logic, switch to `type:"php"`. For verbatim head/footer markup, remove the PHP and inline the resolved value.',
						$m[0][0],
						$line
					),
					array(
						'line' => $line,
						'tag'  => $m[0][0],
					)
				);
			}
			return null;
		}
		if ( ! in_array( $type, array( 'js', 'css' ), true ) ) {
			return null;
		}
		$hits = self::detect_wrapper_tags( $code, $type );
		if ( empty( $hits ) ) {
			return null;
		}
		return new \WP_Error(
			'wrapper_tags_forbidden',
			self::format_wrapper_tag_violation( $hits, $type ),
			array(
				'hits' => $hits,
				'type' => $type,
			)
		);
	}

	/**
	 * Convenience: returns WP_Error if violation found, null otherwise.
	 * Lets callers do `is_wp_error( Snippet_Lint::check( $code ) )`.
	 *
	 * @param string $php_code
	 * @param string $execution_hook Optional configured execution hook.
	 * @return \WP_Error|null
	 */
	/**
	 * Scan a snippet body (any type) for likely hard-coded secrets.
	 *
	 * Pattern-matches common credential shapes — `api[_-]?key`, `bearer`,
	 * `secret`, `token`, AWS-style access keys, JWTs, GitHub tokens, etc. —
	 * paired with a high-entropy value or an obvious assignment. Returns a
	 * list of human-readable warning strings; an empty list means "looks
	 * clean to the eye". Non-blocking by design: secrets are sometimes the
	 * point of a snippet (analytics measurement IDs, public site keys), so
	 * we surface a heads-up instead of refusing the write.
	 *
	 * @since 0.0.5
	 * @param string $code Raw snippet body.
	 * @return array<int,string>
	 */
	public static function detect_secrets( $code ) {
		$code = (string) $code;
		if ( '' === trim( $code ) ) {
			return array();
		}
		$warnings = array();

		// 1. Named secret-ish keys with a quoted/literal value.
		// Captures `api_key = "..."`, `apiKey: '...'`, `BEARER_TOKEN="..."`, etc.
		$named_regex = '/\b(api[_-]?key|api[_-]?secret|secret[_-]?key|client[_-]?secret|access[_-]?token|auth[_-]?token|bearer[_-]?token|private[_-]?key|password|passwd|webhook[_-]?secret)\b\s*[:=]\s*[\'"]([^\'"\\s]{8,})[\'"]/i';
		if ( preg_match_all( $named_regex, $code, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$key   = strtolower( $m[1] );
				$value = $m[2];
				if ( self::looks_like_placeholder( $value ) ) {
					continue;
				}
				$warnings[] = sprintf(
					'Looks like a hard-coded `%s` literal — move to a constant in wp-config.php or store in an option/secrets manager.',
					$key
				);
			}
		}

		// 2. Well-known token formats with low false-positive rate.
		$token_patterns = array(
			'/\b(sk-[A-Za-z0-9]{20,})\b/'        => 'OpenAI-style secret key',
			'/\bAKIA[0-9A-Z]{16}\b/'             => 'AWS access key ID',
			'/\bghp_[A-Za-z0-9]{36,}\b/'         => 'GitHub personal access token',
			'/\bgho_[A-Za-z0-9]{36,}\b/'         => 'GitHub OAuth token',
			'/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/' => 'Slack token',
			'/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/' => 'JWT',
		);
		foreach ( $token_patterns as $pattern => $label ) {
			if ( preg_match( $pattern, $code ) ) {
				$warnings[] = sprintf(
					'%s detected in snippet body — store in wp-config.php or an encrypted option, not in code.',
					$label
				);
			}
		}

		return array_values( array_unique( $warnings ) );
	}

	/**
	 * True if the candidate looks like an obvious placeholder/example —
	 * "YOUR_KEY_HERE", "G-0.0.210.0.21", repeated digits, dashes-only.
	 *
	 * @param string $value Candidate secret value.
	 * @return bool
	 */
	private static function looks_like_placeholder( $value ) {
		$lower = strtolower( $value );
		if ( preg_match( '/^(your|sample|example|placeholder|test|fake|dummy)[-_]/', $lower ) ) {
			return true;
		}
		if ( preg_match( '/^[x]+$/i', $value ) || preg_match( '/^[-_]+$/', $value ) ) {
			return true;
		}
		// Very low character variety = unlikely real credential.
		if ( strlen( count_chars( $value, 3 ) ) <= 3 ) {
			return true;
		}
		return false;
	}

	/**
	 * Detect a fatal PHP parse error in a snippet body WITHOUT executing it.
	 *
	 * The most common agent mistake: a `php` body that drops literal markup
	 * (`<div>…`) into PHP context with no `?>` to exit first. That is a fatal
	 * parse error — `include` aborts and the snippet renders nothing on every
	 * hook — yet it writes and enables silently because the lenient
	 * `token_get_all()` used elsewhere never flags it. `TOKEN_PARSE` makes
	 * `token_get_all()` validate the body as a complete file and throw on
	 * invalid syntax. Unlike an `if(false){…}` eval wrapper it does NOT
	 * false-positive on a correct body that closes `?>` and ends in trailing
	 * HTML, and it cannot fatal the request.
	 *
	 * @param string $php_code Raw snippet body (with or without `<?php`).
	 * @return string|null Parser error message, or null when the body parses.
	 */
	public static function detect_syntax_error( $php_code ) {
		// Own switch — NOT the style-lint kill-switch (`zip_ai_snippets_lint_enabled`).
		// Disabling style lints (inline targeting, redundant hook wrappers) to
		// dodge a false-positive must not silently void fatal-parse-error
		// protection — the exact failure this gate exists to catch.
		if ( ! apply_filters( 'zip_ai_snippets_syntax_check_enabled', true ) ) {
			return null;
		}
		$code = (string) $php_code;
		if ( '' === trim( $code ) ) {
			return null;
		}
		// Parse-mode tokenizer unavailable — cannot validate without executing.
		if ( ! defined( 'TOKEN_PARSE' ) ) {
			return null;
		}

		$trimmed    = ltrim( $code );
		$has_tag    = stripos( $trimmed, '<?php' ) === 0 || stripos( $trimmed, '<?' ) === 0;
		$source     = $has_tag ? $code : "<?php\n" . $code;
		$tag_offset = $has_tag ? 0 : 1; // We prepended one "<?php\n" line.

		try {
			// Called purely for its side effect: TOKEN_PARSE throws on invalid syntax.
			$parsed = token_get_all( $source, TOKEN_PARSE ); // phpcs:ignore
			unset( $parsed );
		} catch ( \ParseError $e ) {
			// Report the body-relative line so the author/agent can self-correct.
			$line = max( 1, $e->getLine() - $tag_offset );
			return sprintf( 'Line %d: %s', $line, $e->getMessage() );
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		}
		return null;
	}

	/**
	 * Run all PHP-snippet lint gates and return the first violation found.
	 *
	 * @param string $php_code       Raw snippet body (with or without `<?php`).
	 * @param string $execution_hook Optional configured execution hook.
	 * @return \WP_Error|null WP_Error on the first failed gate, null when clean.
	 */
	public static function check( $php_code, $execution_hook = '' ) {
		$syntax_error = self::detect_syntax_error( $php_code );
		if ( null !== $syntax_error ) {
			return new \WP_Error(
				'php_syntax_error',
				sprintf(
					'PHP syntax error in snippet body: %s. All literal HTML must sit OUTSIDE <?php ?> — close PHP with ?> before any markup, and reopen <?php only around dynamic output.',
					$syntax_error
				),
				array( 'error' => $syntax_error )
			);
		}
		$hits = self::detect_inline_targeting( $php_code );
		if ( empty( $hits ) ) {
			$hook_hits = self::detect_redundant_hook_wrappers( $php_code, $execution_hook );
			if ( ! empty( $hook_hits ) ) {
				return new \WP_Error(
					'redundant_hook_wrapper',
					self::format_redundant_hook_violation( $hook_hits ),
					array( 'hits' => $hook_hits )
				);
			}
			$output_hits = self::detect_early_direct_output( $php_code, $execution_hook );
			if ( ! empty( $output_hits ) ) {
				return new \WP_Error(
					'early_direct_output',
					self::format_early_output_violation( $output_hits ),
					array( 'hits' => $output_hits )
				);
			}
			return null;
		}
		return new \WP_Error(
			'inline_targeting_forbidden',
			self::format_violation( $hits ),
			array( 'hits' => $hits )
		);
	}
}
