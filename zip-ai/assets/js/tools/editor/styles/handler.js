/**
 * editor/get-styles + editor/set-styles — the GBS CSS store, symmetric with
 * editor/get-context (HTML) and editor/get-scripts (JS).
 *
 * READ (get-styles) has two scopes: 'page' (this page's payload + the SELECTED
 * block's ownership styleContext) and 'global' (the site-wide option). Pure reads.
 *
 * WRITE (set-styles) is GLOBAL-scope ONLY — the header/footer / site-wide CHROME
 * WP OPTION (read via GET /global-styles/user-css, written via the shared
 * /global-styles/sitewide merge route — IMMEDIATE + site-wide, not reversible by
 * discard). The former scope:'page' WRITE (an IMMEDIATE `/global-styles/save`
 * write to this post's `spectra_blocks_pro_gs_user_css` meta) was REMOVED: it
 * persisted a per-block style edit to the DB before the user Saved (no undo) and,
 * on a SHARED gs- class body, silently restyled every section carrying it. All
 * per-block styling is now a utility className via editor/apply-change (editor
 * state, committed on Save). scope:'page' here is rejected, defensively.
 *
 * The global write does READ → MERGE only the touched buckets → WRITE (never
 * full-replace, so importer chrome + user classes survive), then RENDERs the
 * merged payload via the SSOT GenCssRenderer (REST /global-styles/render) and
 * injects it into the canvas iframe for live paint.
 *
 * @package
 */
( function () {
    // eslint-disable-next-line no-unused-vars
    const META_KEY = 'spectra_blocks_pro_gs_user_css'; // page post-meta AND global option key
    const NS = '/spectra-blocks/v1/global-styles';

    // Editor-store access (select/dispatch core/editor + session meta) comes from
    // the ONE shared source (editor/shared/editor-shared-utils.js): window in the
    // browser, require() under jest — so the editor handlers can't drift.
    function sharedEditorUtils() {
        if ( typeof window !== 'undefined' && window.zipwpEditorShared ) {
return window.zipwpEditorShared;
}
        if ( typeof require === 'function' ) {
            try {
 return require( '../shared/editor-shared-utils.js' );
} catch ( e ) {
 return null;
}
        }
        return null;
    }
    function editorSelect() {
 const u = sharedEditorUtils(); return u && u.editorSelect ? u.editorSelect() : null;
}
    function blockEditorSelect() {
 const u = sharedEditorUtils(); return u && u.blockEditorSelect ? u.blockEditorSelect() : null;
}
    // How many blocks on the page carry a gs- token (the reverse `class -> blocks`
    // edge). >1 means editing this class body restyles other sections too. Shared SSOT.
    function classUsage( token ) {
        const u = sharedEditorUtils();
        const besel = blockEditorSelect();
        if ( ! u || ! u.classDependents || ! besel ) {
return 1;
}
        const n = u.classDependents( besel, token, [] ).length;
        return n > 0 ? n : 1;
    }
    function apiFetch( opts ) {
        if ( ! ( window.wp && window.wp.apiFetch ) ) {
            return Promise.reject( new Error( 'wp.apiFetch unavailable' ) );
        }
        return window.wp.apiFetch( opts );
    }

    // GBS page-store logic (merge / iframe-inject / the read→merge→save→render→inject
    // persist) lives in ONE place — editor/shared/editor-shared-utils.js — so this
    // styling tool and editor/apply-change can't drift. These are thin forwarders,
    // the same pattern as editorSelect above.
    function mergePayload( existing, incoming ) {
        const u = sharedEditorUtils();
        return u && u.mergePayload ? u.mergePayload( existing, incoming ) : Object.assign( {}, existing, incoming );
    }
    function canvasDoc() {
        const u = sharedEditorUtils();
        return u && u.canvasDoc ? u.canvasDoc() : document;
    }
    function injectCss( elementId, css ) {
        const u = sharedEditorUtils();
        if ( u && u.injectCss ) {
            u.injectCss( elementId, css );
        }
    }

    function bucketsOf( payload ) {
        return Object.keys( payload || {} ).filter( function ( k ) {
 return k !== 'v';
} );
    }

    // ── STYLE CONTEXT (ownership) ──────────────────────────────────────────────
    // The OWNERSHIP MODEL: a visual property is set by ONE of three layers, in
    // descending CSS specificity — a block ATTRIBUTE, a GBS CLASS body, or the
    // block DEFAULT (DEVELOPER-INSTRUCTIONS §5.1). To change a property you edit
    // its current OWNER (update the existing class, don't stack a new one; clear a
    // block attr that pins it). This resolver answers "who owns each property" so
    // the agent never hand-resolves specificity. It does NOT compute the exact
    // frontend winner (the editor canvas inverts utility-vs-gsClass specificity,
    // and utilities are JIT-compiled, not in the GBS payload) — `effective` is the
    // rendered truth and the agent's verify-iterate loop corrects any mis-guess.
    // The property map is small and explicit ON PURPOSE: it is this tool's job.
    // `prop` is the kebab CSS property — it doubles as the GBS-body key (bodies are
    // kebab, e.g. `font-size`) and the getComputedStyle key. Only `attrs` (the
    // block-attribute path(s) for that property) is non-derivable.
    // NOTE: ownership matches the EXACT property name — the shorthands `padding` /
    // `margin` resolve against a rule's `padding` / `margin` declaration, not a
    // longhand-only rule (`padding-top`). This system's utilities + gs- bodies write
    // the shorthand, so that is the intended scope; widen this list if a longhand-
    // only owner ever needs to be surfaced.
    const STYLE_PROPS = [
        { prop: 'color', attrs: [ 'style.color.text' ] },
        { prop: 'background-color', attrs: [ 'style.color.background', 'background.color' ] },
        { prop: 'padding', attrs: [ 'style.spacing.padding' ] },
        { prop: 'margin', attrs: [ 'style.spacing.margin' ] },
        { prop: 'font-size', attrs: [ 'style.typography.fontSize' ] },
        { prop: 'font-weight', attrs: [ 'style.typography.fontWeight' ] },
        { prop: 'text-align', attrs: [ 'align' ] },
        { prop: 'max-width', attrs: [ 'maxWidth' ] },
        // Border / shadow / motion — className-utility-owned (the visual block
        // attributes for these are BANNED, so there is no block-attribute owner
        // path: `attrs` is empty and ownership resolves purely from the live
        // cascade). Tracked so a `border-2 border-red-500`, `rounded-lg`,
        // `shadow-md`, `animate-*`, or a transform utility applied via apply_change
        // has an `effective`/`owner` the agent can VERIFY — without these the
        // resolver was blind to them and the agent could never confirm the edit
        // (the "the border is applied, the bounce could not be confirmed" hedge).
        // Longhands (not the `border` shorthand): utilities author border-width /
        // border-color separately, and getComputedStyle returns longhands.
        { prop: 'border-color', attrs: [] },
        { prop: 'border-width', attrs: [] },
        { prop: 'border-radius', attrs: [] },
        { prop: 'box-shadow', attrs: [] },
        { prop: 'animation', attrs: [] },
        { prop: 'transform', attrs: [] },
    ];
    function deepGet( obj, path ) {
        let cur = obj;
        const parts = path.split( '.' );
        for ( let i = 0; i < parts.length; i++ ) {
            if ( cur === null || typeof cur !== 'object' ) {
return undefined;
}
            cur = cur[ parts[ i ] ];
        }
        return cur;
    }
    function gsTokensOf( block ) {
        const cn = ( block && block.attributes && typeof block.attributes.className === 'string' )
            ? block.attributes.className : '';
        return cn.trim().split( /\s+/ ).filter( function ( t ) {
 return t && t.indexOf( 'gs-' ) === 0;
} );
    }
    function computedForBlock( clientId ) {
        try {
            const doc = canvasDoc();
            const el = doc.querySelector( '[data-block="' + clientId + '"]' );
            if ( ! el ) {
return null;
}
            return ( doc.defaultView || window ).getComputedStyle( el );
        } catch ( e ) {
 return null;
}
    }
    // ── CSSOM CASCADE RESOLUTION ───────────────────────────────────────────────
    // The ROOT of ownership: `effective` is read from the rendered cascade, so
    // `owner` MUST be resolved from that SAME cascade — not an authored guess. The
    // authored join (attrs + GBS payload) is blind to JIT utility classes (they are
    // compiled to real CSS rules, never in the GBS payload) and to source-order
    // ties between two utilities — the exact gap behind "colour set but not
    // applied" (owner came back `default` while the pixel was clearly painted). We
    // read the real matched rules from the canvas stylesheets instead.

    // The live element for a block (the [data-block] node in the canvas), or null
    // when unmounted (off-screen / virtualized) — then we degrade to the authored
    // join below, flagged as best-effort.
    function elForBlock( doc, clientId ) {
        try {
            return doc && clientId ? doc.querySelector( '[data-block="' + clientId + '"]' ) : null;
        } catch ( e ) {
 return null;
}
    }
    function computedForEl( el, doc ) {
        try {
            return el ? ( doc.defaultView || window ).getComputedStyle( el ) : null;
        } catch ( e ) {
 return null;
}
    }

    // Approximate CSS specificity [ids, classes/attrs/pseudo-classes, types] for a
    // single (comma-free) selector. Our selectors are single classes (`.gs-x`,
    // `.text-primary-600`) → [0,1,0]; the ties they create are broken by SOURCE
    // ORDER, which is the real decider and is tracked separately.
    function specificityOf( sel ) {
        const s = String( sel );
        const ids = ( s.match( /#[\w-]+/g ) || [] ).length;
        const classesAttrsPc =
            ( s.match( /\.[\w-]+/g ) || [] ).length +
            ( s.match( /\[[^\]]*\]/g ) || [] ).length +
            ( s.match( /:(?!:)[\w-]+/g ) || [] ).length;
        const stripped = s
            .replace( /::?[\w-]+(\([^)]*\))?/g, ' ' )
            .replace( /[.#][\w-]+/g, ' ' )
            .replace( /\[[^\]]*\]/g, ' ' );
        const types = ( stripped.match( /[a-zA-Z][\w-]*/g ) || [] ).length;
        return [ ids, classesAttrsPc, types ];
    }
    function cmpSpec( a, b ) {
        for ( let i = 0; i < 3; i++ ) {
            if ( a[ i ] !== b[ i ] ) {
 return a[ i ] - b[ i ];
}
        }
        return 0;
    }
    // Does a conditional group rule (@media / @supports) currently apply? Permissive
    // on the way in — a group we can't evaluate is INCLUDED (better to surface a
    // possibly-inactive source than hide the active one), matching the live paint
    // which the model verifies against `effective` anyway.
    function groupRuleApplies( doc, rule ) {
        try {
            const win = doc.defaultView || window;
            if ( rule.type === 4 /* MEDIA */ ) {
                const mt = rule.media && rule.media.mediaText;
                return ! mt || ! win.matchMedia ? true : win.matchMedia( mt ).matches;
            }
            if ( rule.type === 12 /* SUPPORTS */ ) {
                const ct = rule.conditionText;
                return ! ct || ! ( win.CSS && win.CSS.supports ) ? true : win.CSS.supports( ct );
            }
        } catch ( e ) { /* permissive */ }
        return true;
    }

    // Every stylesheet declaration of `prop` whose selector MATCHES `el`, sorted in
    // ascending cascade priority (winner LAST): !important tier, then specificity,
    // then source order. Reads real CSSOM rules from the canvas — the true source
    // of the painted value.
    function matchedDeclarations( el, doc, prop ) {
        const out = [];
        let order = 0;
        function walk( rules ) {
            for ( let i = 0; i < rules.length; i++ ) {
                const r = rules[ i ];
                if ( r.type === 1 /* STYLE_RULE */ && r.selectorText && r.style ) {
                    order++;
                    const value = r.style.getPropertyValue( prop );
                    if ( value === '' || value === null || value === undefined ) {
 continue;
}
                    const important = r.style.getPropertyPriority( prop ) === 'important';
                    let best = null;
                    String( r.selectorText ).split( ',' ).forEach( function ( part ) {
                        const p = part.trim();
                        if ( ! p ) {
 return;
}
                        let matches = false;
                        try {
 matches = el.matches( p );
} catch ( e ) {
 matches = false;
}
                        if ( ! matches ) {
 return;
}
                        const spec = specificityOf( p );
                        if ( ! best || cmpSpec( spec, best.specificity ) > 0 ) {
                            best = { part: p, specificity: spec };
                        }
                    } );
                    if ( best ) {
                        out.push( { selectorPart: best.part, value: String( value ).trim(), important, specificity: best.specificity, order } );
                    }
                } else if ( ( r.type === 4 || r.type === 12 ) && r.cssRules && groupRuleApplies( doc, r ) ) {
                    walk( r.cssRules );
                }
            }
        }
        const sheets = ( doc && doc.styleSheets ) || [];
        for ( let s = 0; s < sheets.length; s++ ) {
            let rules;
            try {
 rules = sheets[ s ].cssRules || sheets[ s ].rules;
} catch ( e ) {
 continue;
} // cross-origin sheet
            if ( rules ) {
 walk( rules );
}
        }
        out.sort( function ( a, b ) {
            return ( a.important ? 1 : 0 ) - ( b.important ? 1 : 0 ) ||
                cmpSpec( a.specificity, b.specificity ) ||
                a.order - b.order;
        } );
        return out;
    }

    // Map a single-selector matched rule to an EDITABLE source ON this block: a
    // `.gs-*` class (edit its body via set_styles) or a utility class (replace it
    // in className via apply_change). A compound selector (`.parent .child`) isn't
    // a class the model owns here → surfaced as `selector` so it edits the real
    // owner (or an ancestor) rather than stacking.
    function classifySelectorPart( part, el ) {
        const sel = String( part ).trim();
        if ( ! el || ! el.classList ) {
            return { type: 'selector', selector: sel };
        }
        // Class tokens named anywhere in the selector (a `[class]` attribute selector
        // or `:root` scoping carries no dot, so it is ignored here). The GBS JIT
        // boosts per-block rules to `[class].gs-x.gs-x` (attribute + DOUBLED class)
        // to outrank utilities — the same class repeated is ONE owner, editable via
        // set_styles. We must see through that, and through `:root .gs-x` scoping.
        const classTokens = ( sel.match( /\.[\w-]+/g ) || [] ).map( function ( c ) {
 return c.slice( 1 );
} );
        if ( classTokens.length === 0 ) {
            return { type: 'selector', selector: sel };
        }
        // A SELF-target: every class the selector names is on THIS element (so it is
        // repetition / attribute-boosting / a structural `:root` scope, not a real
        // ancestor). A descendant selector like `.wrap .child` names an ancestor
        // class that ISN'T on el → it stays a contextual `selector:` the model can't
        // edit here. Exactly one DISTINCT on-el class keeps the owner unambiguous.
        const distinct = [];
        let allOnEl = true;
        classTokens.forEach( function ( c ) {
            if ( ! el.classList.contains( c ) ) {
                allOnEl = false;
            }
            if ( distinct.indexOf( c ) === -1 ) {
                distinct.push( c );
            }
        } );
        if ( ! allOnEl || distinct.length !== 1 ) {
            return { type: 'selector', selector: sel };
        }
        const cls = distinct[ 0 ];
        return cls.indexOf( 'gs-' ) === 0
            ? { type: 'gbs', class: cls }
            : { type: 'utility', class: cls };
    }
    function ownerLabel( src ) {
        if ( ! src ) {
 return 'default';
}
        if ( src.type === 'block_attribute' ) {
 return 'block_attribute:' + src.path;
}
        if ( src.type === 'gbs' ) {
 return 'gbs:' + src.class;
}
        if ( src.type === 'utility' ) {
 return 'utility:' + src.class;
}
        if ( src.type === 'selector' ) {
 return 'selector:' + src.selector;
}
        return 'default';
    }

    // Cascade-ordered editable sources for ONE property on a MOUNTED block, winner
    // first. Merges the authored inline attribute (Spectra renders style.* inline,
    // a normal declaration above class selectors) with the real matched rules, then
    // sorts by the CSS cascade: !important tier > inline > selectors, each broken by
    // specificity then source order.
    function resolveCascadeSources( def, attrs, el, doc, usageByToken ) {
        const candidates = [];
        def.attrs.forEach( function ( path ) {
            const v = deepGet( attrs, path );
            if ( v !== undefined && v !== null && v !== '' ) {
                // level 2 = normal inline; specificity above any class selector.
                candidates.push( { src: { type: 'block_attribute', path, value: v }, level: 2, specificity: [ 1, 0, 0 ], order: Number.MAX_SAFE_INTEGER } );
            }
        } );
        matchedDeclarations( el, doc, def.prop ).forEach( function ( d ) {
            const cls = classifySelectorPart( d.selectorPart, el );
            let src;
            if ( cls.type === 'gbs' ) {
                src = { type: 'gbs', class: cls.class, value: d.value };
                if ( usageByToken && usageByToken[ cls.class ] !== undefined ) {
                    src.usage = usageByToken[ cls.class ];
                }
            } else if ( cls.type === 'utility' ) {
                src = { type: 'utility', class: cls.class, value: d.value };
            } else {
                src = { type: 'selector', selector: cls.selector, value: d.value };
            }
            candidates.push( { src, level: d.important ? 3 : 1, specificity: d.specificity, order: d.order } );
        } );
        candidates.sort( function ( a, b ) {
            return a.level - b.level || cmpSpec( a.specificity, b.specificity ) || a.order - b.order;
        } );
        return candidates.reverse().map( function ( c ) {
 return c.src;
} ); // winner first
    }

    // Per-property { effective, owner, availableSources[] } for ONE block. When the
    // block is MOUNTED (the real case: the selected block is on-screen), ownership
    // is resolved from the live CSS cascade (CSSOM) so `owner` can never disagree
    // with the painted `effective`. When unmounted (off-screen / no canvas — e.g.
    // jsdom), we degrade to the authored join (attrs + GBS payload), which is
    // best-effort. `canvas` ({ doc, el }) is an injectable seam for tests.
    function buildStyleContext( block, pagePayload, usageByToken, canvas ) {
        if ( ! block ) {
return null;
}
        const classes = ( pagePayload && pagePayload.classes && typeof pagePayload.classes === 'object' )
            ? pagePayload.classes : {};
        const gsTokens = gsTokensOf( block );
        const attrs = block.attributes || {};
        const doc = ( canvas && canvas.doc ) || canvasDoc();
        const el = ( canvas && canvas.el !== undefined ) ? canvas.el : elForBlock( doc, block.clientId );
        const cs = el ? computedForEl( el, doc ) : computedForBlock( block.clientId );
        const properties = {};
        if ( el ) {
            // ROOT PATH — resolve ownership from the live cascade (CSSOM), so a
            // colour painted by a JIT utility (or the winner of two competing
            // utilities) is named correctly instead of collapsing to `default`.
            STYLE_PROPS.forEach( function ( def ) {
                const sources = resolveCascadeSources( def, attrs, el, doc, usageByToken );
                properties[ def.prop ] = {
                    effective: cs ? cs.getPropertyValue( def.prop ) : null,
                    owner: sources.length ? ownerLabel( sources[ 0 ] ) : 'default',
                    availableSources: sources,
                };
            } );
        } else {
            // FALLBACK — block not mounted (no canvas node): the authored join
            // (attrs + GBS payload) is the best we can do and is flagged best-effort
            // by the absence of a rendered `effective`. It cannot see utilities.
            STYLE_PROPS.forEach( function ( def ) {
                const sources = [];
                // block_attribute tier (highest specificity)
                def.attrs.forEach( function ( path ) {
                    const v = deepGet( attrs, path );
                    if ( v !== undefined && v !== null && v !== '' ) {
                        sources.push( { type: 'block_attribute', path, value: v } );
                    }
                } );
                // gbs class tier — only classes ACTUALLY on this block, only if they declare it.
                // `usage` = how many blocks carry this class: >1 means editing its body
                // restyles those other sections too (the reverse-dependency signal).
                gsTokens.forEach( function ( token ) {
                    const body = classes[ token ] && classes[ token ].default;
                    if ( ! body || typeof body !== 'object' ) {
return;
}
                    const v = body[ def.prop ];
                    if ( v !== undefined && v !== null && v !== '' ) {
                        sources.push( { type: 'gbs', class: token, value: v, usage: usageByToken ? usageByToken[ token ] : undefined } );
                    }
                } );
                const owner = sources.length === 0
                    ? 'default'
                    : ( sources[ 0 ].type === 'block_attribute'
                        ? 'block_attribute:' + sources[ 0 ].path
                        : 'gbs:' + sources[ 0 ].class );
                properties[ def.prop ] = {
                    effective: null,
                    owner,
                    availableSources: sources,
                };
            } );
        }
        // Classes on THIS block that also style other sections — edit their body
        // and every listed block moves with it. The model surfaces this to the user
        // (or restyles this block with utilities instead — see the doctrine).
        const sharedClasses = usageByToken
            ? gsTokens.filter( function ( t ) {
 return ( usageByToken[ t ] || 1 ) > 1;
} )
                .map( function ( t ) {
 return { class: t, used_by: usageByToken[ t ] };
} )
            : [];
        return {
            client_id: block.clientId,
            gbs_classes: gsTokens,
            properties,
            shared_gbs_classes: sharedClasses,
        };
    }

    // ── get-styles ───────────────────────────────────────────────────────────
    async function handleGetStyles( args ) {
        const scope = args && args.scope === 'global' ? 'global' : 'page';
        if ( scope === 'page' ) {
            const sel = editorSelect();
            const postId = sel && sel.getCurrentPostId ? sel.getCurrentPostId() : 0;
            if ( ! postId ) {
                return { success: false, error: 'editor_unavailable: no current post id (is the block editor open?)' };
            }
            try {
                const pres = await apiFetch( { path: NS + '/save?scope=page&post_id=' + postId } );
                const pp = ( pres && pres.payload && typeof pres.payload === 'object' ) ? pres.payload : {};
                const out = { scope: 'page', post_id: postId, buckets: bucketsOf( pp ), payload: pp };
                // STYLE CONTEXT (ownership) for a target block — the agent reads
                // this BEFORE a styling edit so it updates the existing owner
                // instead of guessing/stacking. Best-effort: a missing block /
                // unmounted node simply omits styleContext (never blocks the read).
                const clientId = args && typeof args.client_id === 'string' ? args.client_id : null;
                if ( clientId && sel && sel.getBlock ) {
                    const blk = sel.getBlock( clientId );
                    // Reverse-dependency counts for the block's gs- tokens, so the
                    // model sees "shared by N" BEFORE it picks an owner to edit.
                    const usageByToken = {};
                    ( blk && blk.attributes && typeof blk.attributes.className === 'string'
                        ? blk.attributes.className.trim().split( /\s+/ ) : [] )
                        .filter( function ( t ) {
 return t.indexOf( 'gs-' ) === 0;
} )
                        .forEach( function ( t ) {
 usageByToken[ t ] = classUsage( t );
} );
                    const ctx = buildStyleContext( blk, pp, usageByToken );
                    if ( ctx ) {
out.styleContext = ctx;
}
                }
                return { success: true, data: out };
            } catch ( e ) {
                return { success: false, error: 'page_read_failed: ' + String( e && e.message ? e.message : e ) };
            }
        }
        try {
            const res = await apiFetch( { path: NS + '/user-css' } );
            const gp = ( res && res.payload && typeof res.payload === 'object' ) ? res.payload : {};
            return { success: true, data: { scope: 'global', buckets: bucketsOf( gp ), payload: gp } };
        } catch ( e ) {
            return { success: false, error: 'global_read_failed: ' + String( e && e.message ? e.message : e ) };
        }
    }

    // ── set-styles ───────────────────────────────────────────────────────────
    // GLOBAL (chrome) scope ONLY. The former scope:'page' write — an IMMEDIATE,
    // irreversible REST write to this post's `spectra_blocks_pro_gs_user_css` meta
    // — was REMOVED: it persisted a per-block style edit to the DB even if the user
    // never Saved (no undo), and editing a SHARED gs- class body silently restyled
    // every section carrying it. All per-block styling now goes through
    // editor/apply-change utility classNames (editor state, committed on Save). This
    // handler still rejects scope:'page' defensively so a stale/forked brain bundle
    // can never resurrect the silent DB write.
    async function handleSetStyles( args ) {
        const scope = args && args.scope === 'global' ? 'global' : null;
        const incoming = args && args.payload;
        if ( args && args.scope === 'page' ) {
            return { success: false, error: 'unsupported_scope: per-page style writes were removed (they wrote the DB before Save and restyled shared classes). Change per-block styling with a utility className via apply_change instead. set_styles is header/footer chrome (scope:"global") only.' };
        }
        if ( scope === null ) {
            return { success: false, error: 'invalid_input: scope must be "global" (per-block styling uses apply_change utilities)' };
        }
        if ( ! incoming || typeof incoming !== 'object' || Array.isArray( incoming ) ) {
            return { success: false, error: 'invalid_input: payload must be a schema-v1 object of style buckets' };
        }
        if ( ! bucketsOf( incoming ).length ) {
            return { success: false, error: 'invalid_input: payload has no style buckets (classes / wrapperStyles / rootStyles / …)' };
        }

        // scope === 'global' — read-modify-write the option (immediate, site-wide).
        let existingGlobal;
        try {
            // eslint-disable-next-line no-redeclare, no-var
            var g = await apiFetch( { path: NS + '/user-css' } );
            existingGlobal = ( g && g.payload && typeof g.payload === 'object' ) ? g.payload : {};
        } catch ( e ) {
            return { success: false, error: 'global_read_failed: ' + String( e && e.message ? e.message : e ) };
        }
        const mergedGlobal = mergePayload( existingGlobal, incoming );
        try {
            // /sitewide replaces non-class buckets wholesale → send the FULL merged
            // payload so the write equals the merged state (chrome/user classes kept).
            await apiFetch( { path: NS + '/sitewide', method: 'POST', data: { payload: mergedGlobal } } );
        } catch ( e ) {
            return { success: false, error: 'global_write_failed: ' + String( e && e.message ? e.message : e ) };
        }
        try {
            const rg = await apiFetch( { path: NS + '/render', method: 'POST', data: { payload: mergedGlobal, post_id: 0, scope: 'global' } } );
            // Append-last override so the live global paint wins source-order ties
            // (deletions converge on reload, consistent with the live-JIT model).
            injectCss( 'zipwp-gbs-live-global', rg && rg.css );
        } catch ( e ) {
            return { success: false, error: 'render_failed: ' + String( e && e.message ? e.message : e ) };
        }
        return {
            success: true,
            data: {
                scope: 'global',
                buckets: bucketsOf( incoming ),
                note: 'Site-wide + IMMEDIATE: applied to every page now (not reversible by discarding the editor).',
            },
        };
    }

    function initHandler() {
        if ( window.zipwpMcp && window.zipwpMcp.registerTool ) {
            window.zipwpMcp.registerTool( 'editor/get-styles', async function ( args ) {
 return handleGetStyles( args );
}, { previewMode: 'client' } );
            window.zipwpMcp.registerTool( 'editor/set-styles', async function ( args ) {
 return handleSetStyles( args );
}, { previewMode: 'client' } );
        } else {
            setTimeout( initHandler, 100 );
        }
    }
    initHandler();

    // Test-only surface (Node/CommonJS) — inert in the browser bundle.
    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = {
            handleGetStyles,
            handleSetStyles,
            mergePayload,
            // Ownership resolver (per-property effective/owner/availableSources).
            // Exported PURE so a unit test locks the join, not a reimplementation.
            buildStyleContext,
        };
    }
}() );
