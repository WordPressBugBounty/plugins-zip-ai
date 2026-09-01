/**
 * ZipWP MCP — pure js_rpc dispatch-dedup decision (B-1 / P5).
 *
 * The bridge's executeTools loop owns the seen-map I/O (sessionStorage-backed,
 * FIFO-bounded). THIS module is the pure DECISION over a prior seen entry, so the
 * idempotency logic — the silent-until-it-bites duplicate-content path — is
 * unit-tested instead of living only inline in the browser IIFE.
 *
 * Decisions, given the prior _rpcSeen entry for a js_rpc call_id (or undefined):
 *   - a prior entry (IN-FLIGHT or COMPLETED) → repost_cached: NEVER re-dispatch
 *     (re-running the handler double-applies the mutation); re-POST the stored
 *     reply so a still-waiting brain BRPOP resolves.
 *   - no prior entry → run: the caller records IN_FLIGHT_MARKER BEFORE the handler
 *     mutates the tree, so a crash BETWEEN the mutation and the completion-record
 *     still leaves a marker. A replay then reposts an uncertain (ok:false) reply
 *     and the brain verifies with get_context before any retry — never a blind
 *     re-dispatch.
 *
 * Dual-mode: window.zipwpRpcDedup in the browser; CommonJS for jest.
 *
 * @package
 */
( function () {
    'use strict';

    // The reply a replay receives while the first run is mid-apply, or after a
    // crash before the completion-record. ok:false → the brain treats it as an
    // unconfirmed apply (verify-before-retry), never a blind re-apply.
    const IN_FLIGHT_MARKER = { ok: false, data: undefined, error: 'apply_pending_no_confirmation' };

    // A COMPLETED session-scoped write (apply_change / set_scripts — both mutate
    // block attributes in the OPEN editor session) cached on a PRIOR page load: the
    // page reloaded since, and Gutenberg DISCARDS unsaved session edits on reload.
    // The cached ok:true would then mask a LOST edit (the brain folds success while
    // the page reverted). Repost this uncertain reply so the brain re-verifies with
    // get_context instead — same "don't assume, verify" contract as IN_FLIGHT.
    const RELOAD_REVERIFY_MARKER = { ok: false, data: undefined, error: 'apply_unconfirmed_after_reload' };

    // `currentPageLoadId` is the token minted once per page load. A cached entry
    // tagged `mutating` (a session-scoped write) whose `pageLoadId` differs means a
    // reload happened between the apply and this replay → the edit is gone.
    function decideJsRpcDispatch( seenEntry, currentPageLoadId ) {
        if ( seenEntry ) {
            if ( seenEntry.mutating === true &&
                currentPageLoadId !== undefined && currentPageLoadId !== null &&
                seenEntry.pageLoadId !== undefined &&
                seenEntry.pageLoadId !== currentPageLoadId ) {
                return { action: 'repost_cached', reply: RELOAD_REVERIFY_MARKER };
            }
            return { action: 'repost_cached', reply: seenEntry };
        }
        return { action: 'run', inFlightMarker: IN_FLIGHT_MARKER };
    }

    if ( typeof window !== 'undefined' ) {
        window.zipwpRpcDedup = window.zipwpRpcDedup || {};
        window.zipwpRpcDedup.decideJsRpcDispatch = decideJsRpcDispatch;
        window.zipwpRpcDedup.IN_FLIGHT_MARKER = IN_FLIGHT_MARKER;
        window.zipwpRpcDedup.RELOAD_REVERIFY_MARKER = RELOAD_REVERIFY_MARKER;
    }
    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = {
            decideJsRpcDispatch,
            IN_FLIGHT_MARKER,
            RELOAD_REVERIFY_MARKER,
        };
    }
}() );
