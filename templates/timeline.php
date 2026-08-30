<?php
/**
 * Standalone visual timeline iframe page — HORIZONTAL layout.
 *
 * @var string      $teamId    Team unique ID (from controller)
 * @var string      $apiUrl    Timeline API path, front-controller aware (GitHub #91)
 * @var string      $webRoot   NC web root, no trailing slash ('' at a domain root)
 * @var string      $urlPrefix $webRoot, plus '/index.php' where the install needs it
 * @var string|null $error     Non-null when the user is not a team member
 * @var string      $locale    Reader's locale, canonical form (`nl-NL`)
 * @var string      $timezone  Reader's IANA zone (`Europe/Amsterdam`)
 *
 * Layout model
 * ────────────
 * Time runs left → right. The horizontal axis sits near the top of the canvas.
 * Day markers are vertical lines hanging below the axis labels (top-aligned
 * date text + dotted vertical rule). The "Today" marker is a vertical blue
 * line. Events render as chips below the axis, anchored to their proportional
 * x-coordinate by a short vertical connector. When two chips would overlap
 * horizontally, the later one is pushed to the next "lane" (a row below the
 * previous) so proportional x is preserved exactly. Source bands render
 * top-to-bottom in SECTIONS order: Deck, Decisions, Messages, Calendar
 * (v3.78.9). Dated Milestones (v1, v3.78.2) are a fifth, cross-cutting
 * concept: each renders as a full-height red vertical line with its label,
 * independent of the four source bands.
 * When a single section has CROWD_THRESHOLD+ chips on the same calendar day
 * (v3.78.4), those chips collapse into one count-badge instead of stacking
 * that many lanes deep — clicking it asks the parent page (via postMessage)
 * to jump to the 1-Week view centred on that day.
 *
 * Connector overlays (all on by default, v3.78.9) — each draws an arrow
 * between two already-visible chips, independent of which sources/sub-
 * filters are on:
 *   - Decision ↔ task      (v3.78.5, dashed slate)  — decision outcome → linked Deck card
 *   - Deck card dependency (v3.78.8, solid amber)    — prerequisite card → blocked card (NC 34 / Deck 1.18+ only)
 *   - Message ↔ decision   (v3.78.9, dashed green)   — proposal post → decision "proposed" chip
 *
 *
 * On the CSP nonce
 * ────────────────
 * RENDER_AS_BLANK bypasses the layout pipeline that emits <jsfiles>, so
 * Util::addScript() calls don't produce any script tag. We inline the script
 * here and stamp it with the per-request CSP nonce retrieved from the same
 * manager NC core uses — the nonce is already present in the response's CSP
 * header so the browser allows the script to run.
 */
// CSP nonce lookup — history and reasoning:
//   pre-NC 34: \OC::$server->getContentSecurityPolicyNonceManager()
//              was a public shortcut on OC\Server.
//   NC 34:     shortcut removed. No public OCP interface was ever
//              introduced — v4.3.14 mistakenly tried
//              \OCP\Security\ContentSecurityPolicyNonceManager
//              (that class doesn't exist), so `$server->get()` threw
//              QueryNotFoundException and the whole template blew up
//              before rendering anything.
//   Correct:   the OC-private class \OC\Security\CSP\ContentSecurityPolicyNonceManager
//              still exists on every NC version we support (32–34).
//              It's private API in principle but has been stable for
//              years and Nextcloud's own core templates still resolve
//              it the same way — there is no OCP replacement yet.
//
// Wrapped in try/catch with a two-step fallback: if the class ever
// moves again, we log the miss and still render the iframe (script
// will be blocked by CSP because no matching nonce, but at least the
// user sees the "Loading…" HTML rather than a 500).
$nonce = '';
try {
    $nonce = \OC::$server->get(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class)->getNonce();
} catch (\Throwable $nonceErr) {
    // Legacy shortcut (pre-NC 34) — might survive on very old NC
    // versions but be renamed on newer ones.
    try {
        $nonce = \OC::$server->getContentSecurityPolicyNonceManager()->getNonce();
    } catch (\Throwable $legacyNonceErr) {
        \OC::$server->get(\Psr\Log\LoggerInterface::class)->warning(
            '[TeamHub] timeline template: could not resolve CSP nonce manager on this NC version — inline timeline script will be CSP-blocked. Errors: '
            . $nonceErr->getMessage() . ' / ' . $legacyNonceErr->getMessage(),
            ['app' => 'teamhub']
        );
    }
}
?><!DOCTYPE html>
<!-- v4.7.6 — `teamhub` on <html> is the same admin-CSS hook AppEmbed adds to
     the NC apps it embeds (see EMBED_MARKER_CLASS in AppEmbed.vue). This page
     is served by TeamHub rather than embedded from another app, so it is
     written in the markup instead of injected. The rule holds without
     exception: every TeamHub iframe carries it. -->
<html lang="en" class="teamhub">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Timeline</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --nc-blue:        #0082c9;
    --nc-green:       #2d7d46;
    --nc-red:         #c8253f;
    --nc-orange:      #a05a00;
    --nc-slate:       #6c757d;
    --nc-bg:          #fff;
    --nc-text:        #222;
    --nc-text-muted:  #767676;
    --nc-border:      #d8d8d8;
    --axis-top:       46px;   /* y-position of the horizontal date axis */
    --chip-h:         24px;
    --lane-gap:       4px;
}

html, body { height: 100%; background: var(--nc-bg); color: var(--nc-text); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 13px; }

#root { display: flex; flex-direction: column; height: 100%; overflow: hidden; }

/* Horizontal scroll container — vertical scrolls only if a lot of events stack up. */
#scroll {
    flex: 1;
    overflow-x: auto;
    overflow-y: auto;
    position: relative;
    background: var(--nc-bg);
}

#overlay {
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; color: var(--nc-text-muted);
    pointer-events: none;
}

#canvas {
    position: relative;
    /* width and height set by JS based on view mode and lane count */
    height: 100%;
}

/* Date markers and today line now span the full canvas height because
   there's no longer a single global axis — each section has its own. */

/* Section backgrounds — full-width horizontal tinted stripes, one per source. */
.section-bg {
    position: absolute;
    left: 0; right: 0;
    z-index: 0;
    pointer-events: none;
}
.section-bg.sec--calendar  { background: rgba(0, 130, 201, 0.04); }
.section-bg.sec--messages  { background: rgba(111, 66, 193, 0.04); }
.section-bg.sec--decisions { background: rgba(45, 125, 70, 0.04); }
.section-bg.sec--deck      { background: rgba(160, 90, 0, 0.04); }

/* Subtle separators between adjacent bands. */
.section-bg + .section-bg {
    border-top: 1px solid var(--nc-border);
}

/* Section name pinned to the left edge of each band — scrolls horizontally
   with the canvas because it's part of the canvas itself. */
.section-label {
    position: absolute;
    left: 8px;
    z-index: 3;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    pointer-events: none;
    line-height: 1;
}
.section-label.sec--calendar  { color: var(--nc-blue); }
.section-label.sec--messages  { color: #6f42c1; }
.section-label.sec--decisions { color: var(--nc-green); }
.section-label.sec--deck      { color: var(--nc-orange); }

/* Per-section axis line — dotted, in the section's accent colour. */
.section-axis {
    position: absolute;
    left: 0; right: 0;
    height: 1px;
    z-index: 1;
    pointer-events: none;
    background: repeating-linear-gradient(to right, var(--nc-border) 0, var(--nc-border) 6px, transparent 6px, transparent 12px);
}
.section-axis.sec--calendar  { background: repeating-linear-gradient(to right, rgba(0,130,201,.5) 0, rgba(0,130,201,.5) 6px, transparent 6px, transparent 12px); }
.section-axis.sec--messages  { background: repeating-linear-gradient(to right, rgba(111,66,193,.5) 0, rgba(111,66,193,.5) 6px, transparent 6px, transparent 12px); }
.section-axis.sec--decisions { background: repeating-linear-gradient(to right, rgba(45,125,70,.5) 0, rgba(45,125,70,.5) 6px, transparent 6px, transparent 12px); }
.section-axis.sec--deck      { background: repeating-linear-gradient(to right, rgba(160,90,0,.5) 0, rgba(160,90,0,.5) 6px, transparent 6px, transparent 12px); }

/* Vertical date marker — top label, full-height dotted vertical rule. */
.dmark {
    position: absolute;
    top: 0; bottom: 0;
    width: 0;
    z-index: 2;
    pointer-events: none;
}
.dmark__label {
    position: absolute;
    top: 2px;
    left: 0;
    transform: translateX(-50%);
    font-size: 10px; font-weight: 600;
    color: var(--nc-text-muted);
    white-space: nowrap;
    line-height: 1;
    background: var(--nc-bg);
    padding: 0 3px;
    z-index: 3;
}
.dmark--minor .dmark__label { font-weight: 400; }
.dmark__rule {
    position: absolute;
    top: 16px;
    bottom: 0;
    left: 0;
    width: 1px;
    background: repeating-linear-gradient(to bottom, var(--nc-border) 0, var(--nc-border) 4px, transparent 4px, transparent 8px);
}
.dmark--major .dmark__rule { background: var(--nc-border); }

/* Today vertical line — runs the full canvas height. */
.today-line {
    position: absolute;
    top: 0; bottom: 0;
    width: 0;
    z-index: 6;
    pointer-events: none;
}
.today-line__label {
    position: absolute;
    top: 16px;
    left: 0;
    transform: translateX(-50%);
    font-size: 9px; font-weight: 800;
    color: var(--nc-blue);
    text-transform: uppercase;
    letter-spacing: .04em;
    white-space: nowrap;
    background: var(--nc-bg);
    padding: 0 4px;
}
.today-line__rule {
    position: absolute;
    top: 16px;
    bottom: 0;
    left: 0;
    width: 2px;
    background: var(--nc-blue);
    opacity: .7;
}

/* Milestone vertical line — full canvas height, red, one per dated
   milestone. Sits above the Today line (z-index) so a milestone that
   happens to land on today's date still reads clearly as a milestone. */
.milestone-line {
    position: absolute;
    top: 0; bottom: 0;
    width: 0;
    z-index: 7;
    pointer-events: none;
}
.milestone-line__label {
    position: absolute;
    top: 30px;
    left: 0;
    transform: translateX(-50%);
    font-size: 9px; font-weight: 800;
    color: var(--nc-red);
    white-space: nowrap;
    max-width: 160px;
    overflow: hidden;
    text-overflow: ellipsis;
    background: var(--nc-bg);
    padding: 0 4px;
    border: 1px solid var(--nc-red);
    border-radius: 3px;
}
.milestone-line__rule {
    position: absolute;
    top: 16px;
    bottom: 0;
    left: 0;
    width: 2px;
    background: var(--nc-red);
}

/* Crowding count-badge — replaces a cluster of same-day, same-section chips
   when there are too many to show individually (see CROWD_THRESHOLD in the
   render script). Click jumps the whole timeline to the 1-Week view
   centred on that day, via postMessage to the parent (AppEmbed/TeamView
   own the actual navigation state — the iframe has none of its own). */
.ecount-badge {
    position: absolute;
    box-sizing: border-box;
    transform: translateX(-50%);
    height: var(--chip-h);
    min-width: 28px;
    padding: 0 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    border: 2px solid var(--nc-slate);
    background: var(--nc-bg);
    font: inherit;
    font-size: 12px;
    font-weight: 800;
    color: inherit;
    cursor: pointer;
    z-index: 5;
    box-shadow: 0 1px 2px rgba(0, 0, 0, .08);
}
.ecount-badge:hover { filter: brightness(0.97); }
.ecount-badge:focus-visible {
    outline: 2px solid var(--nc-blue);
    outline-offset: 2px;
}

/* Decision↔task connector overlay (v3.78.5) — purely decorative, sits
   below chips (z=5) and badges so it never blocks clicking them. */
.link-overlay {
    position: absolute;
    top: 0;
    left: 0;
    z-index: 4;
    pointer-events: none;
    overflow: visible;
}
.link-line {
    stroke: var(--nc-slate);
    stroke-width: 1.5;
    stroke-dasharray: 4 3;
    opacity: 0.8;
}
.link-arrowhead { fill: var(--nc-slate); }

/* Deck card-dependency connector overlay (v3.78.8) — same idea as the
   decision↔task overlay above, deliberately distinct styling (solid amber
   vs dashed slate) so both can be on at once without being confused for
   each other. Deck's own accent colour (--nc-orange) ties it visually to
   the Deck section band it's drawing between. */
.dep-link-overlay {
    position: absolute;
    top: 0;
    left: 0;
    z-index: 4;
    pointer-events: none;
    overflow: visible;
}
.dep-link-line {
    stroke: var(--nc-orange);
    stroke-width: 1.5;
    opacity: 0.8;
}
.dep-link-arrowhead { fill: var(--nc-orange); }

/* Message↔decision connector overlay (v3.78.9) — a third distinct style
   (dashed green) so all three overlays read as separate concepts when
   stacked: decision↔task = dashed slate, deck dependency = solid amber,
   message↔decision = dashed green. */
.msg-link-overlay {
    position: absolute;
    top: 0;
    left: 0;
    z-index: 4;
    pointer-events: none;
    overflow: visible;
}
.msg-link-line {
    stroke: var(--nc-green);
    stroke-width: 1.5;
    stroke-dasharray: 4 3;
    opacity: 0.8;
}
.msg-link-arrowhead { fill: var(--nc-green); }

/* Event chips — anchored at left = proportional x, top = axis + lane offset */
.echip {
    position: absolute;
    height: var(--chip-h);
    display: flex; align-items: center; gap: 5px;
    padding: 0 7px 0 5px;
    border-radius: 11px;
    border: 1.5px solid var(--nc-border);
    background: var(--nc-bg);
    box-shadow: 0 1px 3px rgba(0,0,0,.08);
    cursor: default;
    white-space: nowrap; overflow: hidden;
    z-index: 5;
    transition: box-shadow .1s, transform .1s;
    transform: translateX(-50%);   /* centre chip on its anchor x */
    max-width: 220px;
}
.echip a.echip { cursor: pointer; text-decoration: none; color: inherit; }
.echip:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,.14);
    z-index: 10;
}
.echip--url { cursor: pointer; }
.echip--url:hover .echip__title { text-decoration: underline; }

.echip__dot {
    width: 7px; height: 7px; border-radius: 50%;
    flex-shrink: 0;
}

.echip__title {
    font-size: 12px; font-weight: 500; color: var(--nc-text);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    flex: 1 1 0; min-width: 0;
}

.echip__time, .echip__badge {
    flex-shrink: 0; white-space: nowrap;
}
.echip__time {
    font-size: 10px; color: var(--nc-text-muted);
}
.echip__badge {
    font-size: 9px; font-weight: 700;
    padding: 1px 5px; border-radius: 8px;
}

.echip__overdue {
    font-size: 9px; font-weight: 800; color: var(--nc-red);
    flex-shrink: 0;
}

.echip__pinned {
    font-size: 10px;
    flex-shrink: 0;
}

/* Per source/type colours */
.echip--calendar       { border-color: var(--nc-blue); }
.echip--calendar       .echip__dot   { background: var(--nc-blue); }
.echip--calendar       .echip__badge { background: #e8f2fb; color: var(--nc-blue); }
.echip--calendar::before { background: var(--nc-blue); }

.echip--d-proposed     { border-color: var(--nc-slate); }
.echip--d-proposed     .echip__dot   { background: var(--nc-slate); }
.echip--d-proposed     .echip__badge { background: #efefef; color: var(--nc-slate); }
.echip--d-proposed::before { background: var(--nc-slate); }

.echip--d-decided      { border-color: var(--nc-green); }
.echip--d-decided      .echip__dot   { background: var(--nc-green); }
.echip--d-decided      .echip__badge { background: #e6f0e9; color: var(--nc-green); }
.echip--d-decided::before { background: var(--nc-green); }

.echip--d-withdrawn    { border-color: var(--nc-red); }
.echip--d-withdrawn    .echip__dot   { background: var(--nc-red); }
.echip--d-withdrawn    .echip__badge { background: #f6d8de; color: var(--nc-red); }
.echip--d-withdrawn::before { background: var(--nc-red); }

.echip--deck-created   { border-color: var(--nc-orange); border-style: dashed; }
.echip--deck-created   .echip__dot   { background: transparent; border: 2px solid var(--nc-orange); }
.echip--deck-created   .echip__badge { background: #fbe9d1; color: var(--nc-orange); }
.echip--deck-created::before { background: var(--nc-orange); }

.echip--deck-due       { border-color: var(--nc-orange); }
.echip--deck-due       .echip__dot   { background: var(--nc-orange); }
.echip--deck-due       .echip__badge { background: #fbe9d1; color: var(--nc-orange); }
.echip--deck-due::before { background: var(--nc-orange); }

/* Completed — green tick, takes precedence over due styling. A completed
   card shouldn't read as "due" anymore even if its due date is also shown. */
.echip--deck-completed { border-color: var(--nc-green); }
.echip--deck-completed .echip__dot   { background: var(--nc-green); }
.echip--deck-completed .echip__badge { background: #e6f0e9; color: var(--nc-green); }
.echip--deck-completed::before { background: var(--nc-green); }

/* Messages — purple/violet so it's distinct from the other four palettes. */
.echip--message        { border-color: #6f42c1; }
.echip--message        .echip__dot   { background: #6f42c1; }
.echip--message        .echip__badge { background: #ece2f8; color: #6f42c1; }
.echip--message::before { background: #6f42c1; }
.echip--message-pinned { border-width: 2px; }

.echip--overdue        .echip__dot   { background: var(--nc-red) !important; }
.echip--overdue        { border-color: var(--nc-red) !important; }

/* ── Gantt-style connecting bars ─────────────────────────────────────
   When the user enables multiple sub-filters for the same item type
   (e.g. Deck "Created" + "Completed"), each item produces several chips
   on the same row. A horizontal bar between the leftmost and rightmost
   visualises the lifecycle as a duration — turning the timeline into a
   small Gantt chart per item. Chips remain on top so their rounded
   ends visually cap the bar. */
.gantt-bar {
    position: absolute;
    height: 4px;
    border-radius: 2px;
    z-index: 4;                 /* below chips (z=5) but above section axes (z=1) */
    opacity: 0.55;
    pointer-events: none;
    transform: translateY(-50%);
}
.gantt-bar--deck      { background: var(--nc-orange); }
.gantt-bar--decisions { background: var(--nc-green); }

/* ── Print rules ──────────────────────────────────────────────────────
   When the parent fires window.print() on this iframe (Print button in
   the embed bar), produce a clean printout: white background, full
   natural width (no clipping at viewport), no scrollbars, lighter
   section backgrounds for ink saving. Chip colours stay visible because
   the type/source is the whole point of the printout.
   `print-color-adjust: exact` forces browsers to keep our accent colours
   on chip dots/borders instead of dropping them to save ink. */
@media print {
    html, body {
        background: #fff !important;
        height: auto !important;
        overflow: visible !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    #root, #scroll, #canvas {
        height: auto !important;
        overflow: visible !important;
    }
    #scroll {
        overflow: visible !important;
    }
    /* Section background tints are kept (subtle, ink-friendly) but lightened
       further so printed text stays legible. */
    .section-bg.sec--calendar  { background: rgba(0, 130, 201, 0.06) !important; }
    .section-bg.sec--messages  { background: rgba(111, 66, 193, 0.06) !important; }
    .section-bg.sec--decisions { background: rgba(45, 125, 70, 0.06) !important; }
    .section-bg.sec--deck      { background: rgba(160, 90, 0, 0.06) !important; }
    /* Loading/error overlay must never show in print output. */
    #overlay { display: none !important; }
    /* Drop the chip box-shadow — looks blurry on paper. */
    .echip {
        box-shadow: none !important;
        transition: none !important;
    }
    .ecount-badge {
        box-shadow: none !important;
    }
}

/* ─────────────────────────────────────────────────────────────────────────
   Item popover (v3.85.8) — click on a chip opens this small dialog with the
   key fields for the underlying item, plus an "Open in [app]" link that
   navigates the parent window (the chip itself no longer navigates on
   plain click; middle-click / ctrl-click still do, via the underlying
   <a href>).
   ───────────────────────────────────────────────────────────────────────── */
.th-popover {
    position: fixed;
    z-index: 100;
    min-width: 280px;
    max-width: 360px;
    background: var(--nc-bg);
    border: 1px solid var(--nc-border);
    border-radius: 8px;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18);
    padding: 12px 14px 10px;
    font-size: 13px;
    color: var(--nc-text);
}
.th-popover[hidden] { display: none; }

.th-popover__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 6px;
}
.th-popover__source {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--nc-slate);
}
.th-popover__close {
    background: none;
    border: none;
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
    color: var(--nc-slate);
    padding: 2px 6px;
    border-radius: 4px;
}
.th-popover__close:hover { background: rgba(0, 0, 0, 0.06); }
.th-popover__close:focus-visible {
    outline: 2px solid var(--nc-blue);
    outline-offset: 2px;
}

.th-popover__title {
    margin: 0 0 10px;
    font-size: 15px;
    font-weight: 700;
    line-height: 1.3;
    word-break: break-word;
}

.th-popover__details {
    margin: 0 0 10px;
    padding: 0;
    display: grid;
    grid-template-columns: max-content 1fr;
    column-gap: 10px;
    row-gap: 6px;
    font-size: 12px;
}
.th-popover__row {
    display: contents;
}
.th-popover__row > dt {
    color: var(--nc-slate);
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}
.th-popover__row > dd {
    margin: 0;
    color: var(--nc-text);
    word-break: break-word;
}
/* Inline MDI SVG icons — sized to match label line-height with currentColor
   so they pick up the surrounding text colour. */
.th-popover__svg {
    width: 14px;
    height: 14px;
    flex-shrink: 0;
    color: var(--nc-slate);
}

.th-popover__description,
.th-popover__snippet {
    border-top: 1px solid var(--nc-border);
    margin-top: 8px;
    padding-top: 8px;
    color: var(--nc-text);
    font-size: 12px;
    line-height: 1.45;
    white-space: pre-wrap;
}

.th-popover__footer {
    margin-top: 10px;
    display: flex;
    justify-content: flex-end;
}
.th-popover__open {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    border-radius: 6px;
    background: var(--nc-blue);
    color: #fff;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
}
.th-popover__open:hover { filter: brightness(0.95); text-decoration: none; }
.th-popover__open:focus-visible {
    outline: 2px solid var(--nc-blue);
    outline-offset: 2px;
}
</style>
</head>
<body>
<?php if ($error): ?>
<div id="root"><div id="overlay"><?= htmlspecialchars($error) ?></div></div>
<?php else: ?>
<div id="root" data-team-id="<?= htmlspecialchars($teamId) ?>" data-api-url="<?= htmlspecialchars($apiUrl) ?>" data-web-root="<?= htmlspecialchars($webRoot) ?>" data-url-prefix="<?= htmlspecialchars($urlPrefix) ?>" data-locale="<?= htmlspecialchars($locale) ?>" data-timezone="<?= htmlspecialchars($timezone) ?>">
    <div id="scroll">
        <div id="canvas">
        </div>
        <div id="overlay">Loading…</div>
    </div>
</div>
<script nonce="<?= htmlspecialchars($nonce) ?>">
(function () {
    'use strict';

    var root = document.getElementById('root');
    if (!root) return;

    // Built by the controller through IURLGenerator, so both already carry
    // '/index.php' on an install without pretty URLs (GitHub #91). Never
    // assemble an NC URL here from a bare '/apps/…' path.
    var API_URL    = root.dataset.apiUrl || '';
    var WEB_ROOT   = root.dataset.webRoot || '';
    var URL_PREFIX = root.dataset.urlPrefix || '';

    // Which locale writes a date, and which zone it is read in. Both come
    // from DateContextService, the same source the Vue bundle reads through
    // initial state — `lang` on <html> is Nextcloud's *language*, which is a
    // different setting and formats dates wrongly for anyone whose UI
    // language and locale differ.
    //
    // This surface cannot import src/lib/localDate.js: it is server-rendered
    // vanilla JS with no module loader (the same constraint Track C5 records
    // for its strings). The three helpers below mirror that module and must
    // be changed with it.
    var LOCALE = root.dataset.locale || undefined;
    var TIMEZONE = root.dataset.timezone || '';

    var params       = new URLSearchParams(window.location.search);
    var viewMode     = params.get('view') || '1W';
    var fromParam    = parseInt(params.get('from'), 10);
    var sourcesParam = (params.get('sources') || 'calendar,decisions,deck,messages').split(',').filter(Boolean);
    var showSrc = {
        calendar:  sourcesParam.indexOf('calendar')  !== -1,
        decisions: sourcesParam.indexOf('decisions') !== -1,
        deck:      sourcesParam.indexOf('deck')      !== -1,
        messages:  sourcesParam.indexOf('messages')  !== -1,
    };
    // Decision↔task connector overlay (v3.78.5) — independent of showSrc;
    // doesn't filter which chips appear, just whether the arrows are drawn.
    // Defaults true when the param is absent entirely (e.g. iframe loaded
    // standalone without TeamView.vue's full querystring) — v3.78.9 made
    // every connector overlay on-by-default, so "absent" should read the
    // same as "on" here, matching timelineShowLinks's default in TeamView.vue.
    var showLinks = params.has('links') ? params.get('links') === '1' : true;
    // Deck card-dependency connector overlay (v3.78.8) — same idea, separate
    // toggle. Only ever meaningfully '1' on installs where TeamView.vue
    // confirmed cardDependenciesSupported; harmless no-op otherwise since
    // there'd be no meta.blockedByCardIds on any event to draw from.
    var showDepLinks = params.has('depLinks') ? params.get('depLinks') === '1' : true;
    // Message↔decision connector overlay (v3.78.9) — arrow from a
    // messageType='decision' post's chip to the decision's "proposed" chip
    // it announced.
    var showMsgLinks = params.has('msgLinks') ? params.get('msgLinks') === '1' : true;

    // Sub-filter parsing. Param shape: "deck:due,deck:completed,decisions:decided"
    // Each entry enables exactly one source/type pair. Missing pairs default to off.
    // Defaults match the parent (TeamView.vue): only resolved-state events are on,
    // so an empty/missing sub param falls back to the same default set.
    var subParam = (params.get('sub') || 'deck:created,deck:due,deck:completed,decisions:proposed,decisions:decided').split(',').filter(Boolean);
    var showSub = {
        deck:      { created: false, due: false, completed: false },
        decisions: { proposed: false, decided: false, withdrawn: false },
    };
    subParam.forEach(function (pair) {
        var c = pair.split(':');
        if (c.length === 2 && showSub[c[0]] && c[1] in showSub[c[0]]) {
            showSub[c[0]][c[1]] = true;
        }
    });
    // "decided" implicitly enables "withdrawn" — both are concluded states and
    // grouping them under one user-facing checkbox keeps the filter UI simple.
    if (showSub.decisions.decided) {
        showSub.decisions.withdrawn = true;
    }

    function startOfWeek(d) {
        var r = new Date(d); r.setHours(0, 0, 0, 0);
        r.setDate(r.getDate() - ((r.getDay() + 6) % 7));
        return r;
    }
    function addPeriod(d, mode) {
        var r = new Date(d);
        if (mode === '1W') r.setDate(r.getDate() + 7);
        else if (mode === '1M') r.setMonth(r.getMonth() + 1);
        else if (mode === '3M') r.setMonth(r.getMonth() + 3);
        else r.setMonth(r.getMonth() + 6);
        return r;
    }

    var windowStart = isNaN(fromParam) ? startOfWeek(new Date()) : new Date(fromParam * 1000);

    // Horizontal pixels per day per view mode. 1W is generous so users can read
    // chips comfortably; 6M is compressed but stays scrollable left/right.
    var PX_PER_DAY = { '1W': 180, '1M': 60, '3M': 32, '6M': 18 };
    var CHIP_MIN_W = 90;      // approximate chip width for collision detection — smaller now that pills are simpler
    var CHIP_H     = 24;      // must match CSS --chip-h — used to centre connector-line anchors on the chip
    var LANE_H     = 28;      // chip height + gap (= CSS --chip-h + --lane-gap)
    // Crowding threshold (v3.78.4): when a single section has this many or
    // more events landing on the same calendar day, those chips collapse
    // into one count-badge instead of stacking N lanes deep. This is driven
    // purely by per-day item density within the section — not by the
    // current view mode — so a busy day stays a badge at 1W zoom just as it
    // would at 6M, and a quiet 6M view still shows individual chips.
    var CROWD_THRESHOLD = 4;
    var SECTION_ACCENT = {
        deck:      'var(--nc-orange)',
        calendar:  'var(--nc-blue)',
        decisions: 'var(--nc-slate)',
        messages:  '#6f42c1',
    };
    var AXIS_TOP   = 46;      // matches --axis-top in CSS

    var allEvents = [];

    /** opts + the reader's zone, without mutating the caller's object. */
    function withZone(opts, zone) {
        var out = {}, k;
        for (k in opts) {
            if (Object.prototype.hasOwnProperty.call(opts, k)) out[k] = opts[k];
        }
        if (zone) out.timeZone = zone;
        return out;
    }

    /** An instant, in the reader's locale and zone. */
    function fmtDate(d, opts) {
        return new Intl.DateTimeFormat(LOCALE, withZone(opts, TIMEZONE)).format(d);
    }

    /**
     * A floating calendar day, in the reader's locale but through UTC so no
     * zone can move it. TimelineService emits `format('c')` for every event
     * and flags all-day separately, so an all-day value arrives already
     * pinned at midnight — its date part is the answer, and re-reading it in
     * a zone west of Greenwich would report the previous day.
     */
    function fmtIsoDay(iso, opts) {
        var ms = Date.parse(String(iso).slice(0, 10) + 'T00:00:00Z');
        if (isNaN(ms)) return String(iso);
        return new Intl.DateTimeFormat(LOCALE, withZone(opts, 'UTC')).format(new Date(ms));
    }
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    // Only http(s) and root-relative (single-slash) URLs are allowed as chip /
    // popover hrefs. Everything else — javascript:, data:, vbscript:, and
    // protocol-relative //host — is dropped so a crafted event URL can never
    // execute script or navigate off-origin when a chip is activated.
    //
    // A surviving relative URL is web-root relative, not origin relative: the
    // API hands back '/apps/deck/board/…'. Used as-is it lands at the domain
    // root, which is the wrong host path on a sub-directory install and skips
    // the front controller where pretty URLs are off (GitHub #91). '/index.php/…'
    // is accepted too — IntegrationService documents it as a legal stored shape —
    // and needs the web root only, since it carries the front controller itself.
    function safeHref(u) {
        if (u == null) return null;
        var s = String(u).trim();
        if (/^https?:\/\//i.test(s)) return s;
        if (s.charAt(0) === '/' && s.charAt(1) !== '/') {
            return (s.indexOf('/index.php/') === 0 ? WEB_ROOT : URL_PREFIX) + s;
        }
        return null;
    }
    function trunc(s, n) { return s.length > n ? s.slice(0, n) + '…' : s; }

    function fetchAndRender() {
        var from = Math.floor(windowStart.getTime() / 1000);
        var to   = Math.floor(addPeriod(windowStart, viewMode).getTime() / 1000);

        var overlay = document.getElementById('overlay');
        overlay.textContent = 'Loading…';
        overlay.style.display = '';

        var url = API_URL + '?from=' + from + '&to=' + to;
        fetch(url, { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                allEvents = Array.isArray(data && data.events) ? data.events : [];
                overlay.style.display = 'none';
                renderCanvas(from, to);
            })
            .catch(function (err) {
                console.error('[TeamHub][Timeline]', err);
                overlay.textContent = 'Could not load timeline: ' + err.message;
                overlay.style.display = '';
            });
    }

    function renderCanvas(from, to) {
        var canvas  = document.getElementById('canvas');
        var totalS  = to - from;
        // Canvas width: pixels-per-day × days, with a minimum so 6M is still legible.
        var days    = (to - from) / 86400;
        var totalPx = Math.max(900, days * PX_PER_DAY[viewMode]);

        canvas.innerHTML = '';
        canvas.style.width = totalPx + 'px';

        var pxPerS = totalPx / totalS;
        function xOf(ts) { return Math.max(0, Math.min(totalPx, (ts - from) * pxPerS)); }

        // ── Vertical date markers ────────────────────────────────────────
        var DAY = 86400;
        var markerDays = (viewMode === '1W' || viewMode === '1M') ? 1 : 7;

        var d = new Date(windowStart);
        while (d.getTime() / 1000 < to + DAY) {
            var ts = d.getTime() / 1000;
            if (ts < from) { d.setDate(d.getDate() + markerDays); continue; }
            var x = xOf(ts);
            var dow = d.getDay();
            var isWeekStart = dow === 1;
            var isMonthStart = d.getDate() === 1;
            var isMajor = isMonthStart || (markerDays >= 7 && isWeekStart);

            var el = document.createElement('div');
            el.className = 'dmark' + (isMajor ? ' dmark--major' : ' dmark--minor');
            el.style.left = x + 'px';

            var labelText;
            if (viewMode === '1W') {
                labelText = fmtDate(d, { weekday: 'short', day: 'numeric' });
            } else if (viewMode === '1M') {
                labelText = isMajor ? fmtDate(d, { month: 'short', day: 'numeric' }) : fmtDate(d, { day: 'numeric' });
            } else {
                labelText = fmtDate(d, { month: 'short', day: 'numeric' });
            }

            el.innerHTML = '<span class="dmark__label">' + esc(labelText) + '</span><div class="dmark__rule"></div>';
            canvas.appendChild(el);

            d.setDate(d.getDate() + markerDays);
        }

        // ── Today line ───────────────────────────────────────────────────
        var nowTs = Date.now() / 1000;
        if (nowTs >= from && nowTs <= to) {
            var xToday = xOf(nowTs);
            var todayEl = document.createElement('div');
            todayEl.className = 'today-line';
            todayEl.style.left = xToday + 'px';
            todayEl.innerHTML = '<span class="today-line__label">Today</span><div class="today-line__rule"></div>';
            canvas.appendChild(todayEl);
        }

        // ── Milestone lines ──────────────────────────────────────────────
        // Full-height red marker line per dated milestone — cross-cutting,
        // independent of the per-source bands below, and always plotted
        // (not a filterable source — admins who set a milestone want it
        // visible unconditionally). v1: no collision avoidance between
        // milestone labels; closely-spaced milestones may overlap visually.
        // Acceptable for a first version.
        allEvents.filter(function (ev) { return ev.source === 'milestone'; }).forEach(function (ev) {
            var msTs = new Date(ev.date).getTime() / 1000;
            var xMs  = xOf(msTs);
            var msEl = document.createElement('div');
            msEl.className = 'milestone-line';
            msEl.style.left = xMs + 'px';
            msEl.innerHTML = '<span class="milestone-line__label" title="' + esc(ev.title) + '">' + esc(ev.title) + '</span><div class="milestone-line__rule"></div>';
            canvas.appendChild(msEl);
        });

        // ── Events ────────────────────────────────────────────────────────
        // FOUR SECTIONS — one horizontal band per source: Calendar, Messages,
        // Decisions, Deck. Each band has:
        //   • a tinted background stripe (matching the source's accent colour)
        //   • a left-edge label
        //   • its own dotted axis line at the band's vertical centre
        //   • independent lane assignment so chips stack only within their own
        //     band (calendar chips never collide with deck chips, etc.)
        // The single horizontal Today line still runs the full canvas height
        // so the "now" reference is visible across all bands.
        var filtered = allEvents.filter(function (ev) {
            if (!showSrc[ev.source]) return false;
            // Sub-filter: Deck and Decisions both have per-type checkboxes.
            // Other sources (calendar, messages) have no sub-types so they
            // pass through unconditionally.
            if (ev.source === 'deck' && showSub.deck) {
                return showSub.deck[ev.type] === true;
            }
            if (ev.source === 'decisions' && showSub.decisions) {
                return showSub.decisions[ev.type] === true;
            }
            return true;
        });

        // Section spec: order matters (top → bottom). Hidden via the filter
        // menu => not rendered. Each section gets its own band even when empty
        // so the structure stays predictable as users toggle filters on/off.
        var SECTIONS = [
            { id: 'deck',      label: 'Deck',      cls: 'sec--deck'      },
            { id: 'decisions', label: 'Decisions', cls: 'sec--decisions' },
            { id: 'messages',  label: 'Messages',  cls: 'sec--messages'  },
            { id: 'calendar',  label: 'Calendar',  cls: 'sec--calendar'  },
        ].filter(function (s) { return showSrc[s.id]; });

        // Bucket events into their sections.
        var bySection = {};
        SECTIONS.forEach(function (s) { bySection[s.id] = []; });
        filtered.forEach(function (ev) {
            // For decisions, the event.source is 'decisions' regardless of
            // proposed/decided/withdrawn. Same for deck. So we can route on
            // ev.source alone.
            if (bySection[ev.source]) {
                var ts = Math.max(from, Math.min(to, new Date(ev.date).getTime() / 1000));
                bySection[ev.source].push({ ev: ev, x: xOf(ts), lane: 0 });
            }
        });

        /** Local-calendar-day key, e.g. "2026-6-17". Matches the local-time
         *  basis the date markers and Today line already use, so a badge's
         *  day always lines up with the day marker above it. */
        function dayKeyOf(ev) {
            var d = new Date(ev.date);
            return d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate();
        }
        /** Local noon of the same calendar day, as a Unix timestamp — used
         *  to anchor the badge under the middle of its day's marker rather
         *  than at whichever individual event happened to start the group. */
        function middayTsOf(ev) {
            var d = new Date(ev.date);
            return new Date(d.getFullYear(), d.getMonth(), d.getDate(), 12, 0, 0).getTime() / 1000;
        }

        // ── Crowding pass ───────────────────────────────────────────────
        // Per section, group its chips by calendar day. Days with
        // CROWD_THRESHOLD or more chips collapse into a single count-badge
        // (see buildBadge) instead of stacking that many lanes deep. Days
        // under the threshold are left as individual chips, untouched.
        //
        // Skipped entirely in 1-Week view (v3.78.5): the whole point of
        // zooming into a week — often BY clicking a badge from a more
        // zoomed-out view — is to see every individual item. Week view
        // switches to full per-item lane assignment below instead (one row
        // per item, connected lifecycle chips sharing a row), which is the
        // right fix for vertical crowding at this zoom level: most same-day
        // collisions at 1W are coincidental neighbours, not genuinely so
        // many items that a count is more useful than the list itself.
        if (viewMode !== '1W') {
        SECTIONS.forEach(function (s) {
            var byDay = {};
            bySection[s.id].forEach(function (p) {
                var key = dayKeyOf(p.ev);
                if (!byDay[key]) byDay[key] = [];
                byDay[key].push(p);
            });
            var collapsed = [];
            Object.keys(byDay).forEach(function (key) {
                var group = byDay[key];
                if (group.length >= CROWD_THRESHOLD) {
                    var midTs = middayTsOf(group[0].ev);
                    collapsed.push({
                        ev: {
                            // Synthetic event — note this never came from
                            // TimelineService; it only exists to give the
                            // badge a place in the lane-assignment and
                            // chip-rendering pipelines below.
                            id:      'badge-' + s.id + '-' + key,
                            source:  s.id,
                            type:    'badge',
                            isBadge: true,
                            count:   group.length,
                            dayTs:   midTs,
                            title:   group.length + ' items',
                        },
                        x: xOf(midTs),
                        lane: 0,
                    });
                } else {
                    group.forEach(function (p) { collapsed.push(p); });
                }
            });
            bySection[s.id] = collapsed;
        });
        }

        /**
         * Lane assignment within one section. Sorts by x and drops each chip
         * into the first lane whose previous chip ended before the new one
         * starts (proportional x preserved exactly; vertical stacking only
         * when chips truly overlap horizontally).
         * Returns number of lanes used.
         */
        function assignLanes(band) {
            band.sort(function (a, b) { return a.x - b.x; });
            var laneEnds = [];
            band.forEach(function (p) {
                var leftEdge  = p.x - CHIP_MIN_W / 2;
                var rightEdge = p.x + CHIP_MIN_W / 2;
                var assigned  = -1;
                for (var i = 0; i < laneEnds.length; i++) {
                    if (laneEnds[i] <= leftEdge) {
                        assigned = i;
                        laneEnds[i] = rightEdge;
                        break;
                    }
                }
                if (assigned === -1) {
                    assigned = laneEnds.length;
                    laneEnds.push(rightEdge);
                }
                p.lane = assigned;
            });
            return laneEnds.length;
        }

        /**
         * Per-item lane assignment — Gantt-chart layout. Every distinct item
         * (Deck card or Decision) gets its own dedicated row, regardless of
         * whether its chips would have fit on a shared row. Lanes are ordered
         * by the item's earliest event x, so the chart reads top → bottom in
         * starting order.
         *
         * Used when the section being rendered is the only active section
         * (e.g. user filtered to Deck only), where giving each card its own
         * row turns the visual into a proper Gantt chart with one row per
         * task and a connecting bar showing duration.
         */
        function assignLanesPerItem(band, itemKeyFn) {
            // Group chips by item, remembering each group's earliest x.
            var groups = {};        // itemKey → { firstX: number, chips: [] }
            band.forEach(function (p) {
                var key = itemKeyFn(p.ev);
                if (!key) {
                    // Items without an itemKey (shouldn't happen here, but
                    // guard anyway) fall back to their own anonymous group
                    // keyed by chip id so they still each get a lane.
                    key = '__nokey_' + p.ev.id;
                }
                if (!groups[key]) groups[key] = { firstX: p.x, chips: [] };
                if (p.x < groups[key].firstX) groups[key].firstX = p.x;
                groups[key].chips.push(p);
            });
            // Sort groups by their earliest x; assign sequential lanes.
            var orderedKeys = Object.keys(groups).sort(function (a, b) {
                return groups[a].firstX - groups[b].firstX;
            });
            orderedKeys.forEach(function (key, lane) {
                groups[key].chips.forEach(function (p) { p.lane = lane; });
            });
            return orderedKeys.length;
        }

        // Constants for section geometry.
        // SECTION_LABEL_H reserves a clean row at the top of each band for
        // the source label ("CALENDAR", "MESSAGES", ...). Lane 0 starts BELOW
        // this row so labels never collide with chips. Previously chips sat
        // at lane 0 starting just 8px below the band top, which made the label
        // overlap row 0's chips on busy bands.
        var SECTION_LABEL_H    = 22;  // label row + breathing space
        var SECTION_PAD_BOTTOM = 12;  // padding below last lane
        var SECTION_HEADER_GAP = 22;  // reserved row at top of canvas for the date markers

        // Compute each section's height + cumulative y offset.
        //
        // Per-item Gantt mode: every distinct item (Deck card or Decision)
        // gets its own row instead of the regular x-overlap lane packing,
        // so connected lifecycle chips (created+due, proposed+decided)
        // always land on the same row — see assignLanesPerItem. Two
        // triggers:
        //   • Filtered down to a single source with item identity (Deck or
        //     Decisions) — turns the view into a clean Gantt chart.
        //   • 1-Week view (v3.78.5), regardless of how many sources are
        //     visible — "give every item its own row" is what zooming all
        //     the way in is FOR. Calendar/Messages have no lifecycle
        //     concept, but assignLanesPerItem's fallback (keyed by chip id
        //     when itemKeyFor returns null) gives each of those its own
        //     row too, which is exactly the same outcome the regular
        //     x-sorted assignLanes would give them anyway — so applying it
        //     uniformly across all four sections at this zoom level is safe.
        var isSolo = SECTIONS.length === 1;
        var soloId = isSolo ? SECTIONS[0].id : null;
        var perItemMode = (viewMode === '1W') || (isSolo && (soloId === 'deck' || soloId === 'decisions'));

        function itemKeyFor(ev) {
            var meta = ev.meta || {};
            if (ev.source === 'deck' && meta.cardId)         return 'deck-' + meta.cardId;
            if (ev.source === 'decisions' && meta.decisionId) return 'dec-' + meta.decisionId;
            return null;
        }

        var cumulativeY = SECTION_HEADER_GAP;  // top date labels live here
        SECTIONS.forEach(function (s) {
            s.lanes = perItemMode ? assignLanesPerItem(bySection[s.id], itemKeyFor) : assignLanes(bySection[s.id]);
            // height = label row + chip lanes + bottom padding
            s.height = SECTION_LABEL_H + Math.max(1, s.lanes) * LANE_H + SECTION_PAD_BOTTOM;
            s.top    = cumulativeY;
            cumulativeY += s.height;
        });

        var totalHeight = cumulativeY + 6;
        canvas.style.height = totalHeight + 'px';

        // Render section backgrounds + labels + per-section axis lines BEFORE
        // chips so chips sit on top. Each background is positioned absolutely
        // and spans full canvas width; the source-specific tint comes from
        // .sec--<id> classes in the CSS.
        SECTIONS.forEach(function (s) {
            var bg = document.createElement('div');
            bg.className = 'section-bg ' + s.cls;
            bg.style.top    = s.top + 'px';
            bg.style.height = s.height + 'px';
            canvas.appendChild(bg);

            var label = document.createElement('div');
            label.className = 'section-label ' + s.cls;
            label.style.top = (s.top + 4) + 'px';
            label.textContent = s.label;
            canvas.appendChild(label);

            // Section's own faint horizontal axis line, just below the label
            // row, so the chip row visually reads as "sits on the axis below
            // the label" rather than "floats in the middle of the band".
            var sectionAxis = document.createElement('div');
            sectionAxis.className = 'section-axis ' + s.cls;
            sectionAxis.style.top = (s.top + SECTION_LABEL_H - 1) + 'px';
            canvas.appendChild(sectionAxis);
        });

        // ── Gantt-style connecting bars ──────────────────────────────────
        // When multiple sub-filters are active for the same item (Deck card
        // or Decision), several chips for that item appear on the same row.
        // Drawing a thin horizontal bar between the leftmost and rightmost
        // chip in such a group makes the lifecycle visually obvious — the
        // chip pair "created → completed" reads as a duration rather than
        // as two disconnected dots.
        //
        // Grouping key: source + cardId / decisionId. Only pairs that share a
        // section AND a lane are connected (different lanes would mean the
        // collision-avoidance code already placed them apart for a reason).
        SECTIONS.forEach(function (s) {
            var groups = {};
            bySection[s.id].forEach(function (p) {
                var meta = p.ev.meta || {};
                var itemKey = null;
                if (p.ev.source === 'deck' && meta.cardId)         itemKey = 'deck-' + meta.cardId;
                else if (p.ev.source === 'decisions' && meta.decisionId) itemKey = 'dec-' + meta.decisionId;
                if (!itemKey) return;
                var laneKey = itemKey + '#' + p.lane;
                if (!groups[laneKey]) groups[laneKey] = [];
                groups[laneKey].push(p);
            });

            Object.keys(groups).forEach(function (key) {
                var g = groups[key];
                if (g.length < 2) return;
                g.sort(function (a, b) { return a.x - b.x; });
                var leftX  = g[0].x;
                var rightX = g[g.length - 1].x;
                if (rightX - leftX < 4) return; // not enough span to be useful

                var bar = document.createElement('div');
                bar.className = 'gantt-bar gantt-bar--' + g[0].ev.source;
                bar.style.left   = leftX + 'px';
                bar.style.width  = (rightX - leftX) + 'px';
                bar.style.top    = (s.top + SECTION_LABEL_H + g[0].lane * LANE_H + 11) + 'px'; // vertically centred on chip row (chip h=22 → centre ~11)
                canvas.appendChild(bar);
            });
        });

        // Render chips per section (chips appear ON TOP of bars). While
        // we're here, also record the screen position of each decision's
        // "proposed" chip and each Deck card's "created" chip — the two
        // canonical anchors used by the decision↔task connector overlay
        // below. Every decision always has a "proposed" event and every
        // linked card always has a "created" event, so these two types are
        // guaranteed anchors when the item is visible at all; an item that
        // got folded into a crowding badge (isBadge) has no usable single
        // point, so it's simply skipped — no connector is drawn for it.
        var decisionAnchor = {};   // decisionId → {x, y}  (decided/withdrawn — for the decision↔task overlay)
        var decisionProposedAnchor = {}; // decisionId → {x, y}  (proposed — for the message↔decision overlay)
        var cardAnchor     = {};   // cardId     → {x, y}
        var messageAnchor  = {};   // messageId  → {x, y}
        SECTIONS.forEach(function (s) {
            bySection[s.id].forEach(function (p) {
                var node  = p.ev.isBadge ? buildBadge(p.ev) : buildChip(p.ev);
                var topPx = s.top + SECTION_LABEL_H + p.lane * LANE_H;
                node.style.left = p.x + 'px';
                node.style.top  = topPx + 'px';
                canvas.appendChild(node);

                if (!p.ev.isBadge) {
                    var meta = p.ev.meta || {};
                    var anchorY = topPx + CHIP_H / 2;
                    // The connector runs from the decision's outcome chip
                    // (decided or withdrawn — whichever is visible) to the
                    // linked card's created chip. Using the outcome chip
                    // communicates "this task flows from a resolved decision",
                    // which is the causal direction. If both decided and
                    // withdrawn chips happen to be visible we prefer decided.
                    if (p.ev.source === 'decisions' && meta.decisionId) {
                        if (p.ev.type === 'decided') {
                            decisionAnchor[meta.decisionId] = { x: p.x, y: anchorY };
                        } else if (p.ev.type === 'withdrawn' && !decisionAnchor[meta.decisionId]) {
                            decisionAnchor[meta.decisionId] = { x: p.x, y: anchorY };
                        }
                        if (p.ev.type === 'proposed') {
                            decisionProposedAnchor[meta.decisionId] = { x: p.x, y: anchorY };
                        }
                    }
                    if (p.ev.source === 'deck' && p.ev.type === 'created' && meta.cardId) {
                        cardAnchor[meta.cardId] = { x: p.x, y: anchorY };
                    }
                    if (p.ev.source === 'messages' && meta.messageId) {
                        messageAnchor[meta.messageId] = { x: p.x, y: anchorY };
                    }
                }
            });
        });

        if (showLinks) {
            drawDecisionTaskLinks(canvas, totalPx, totalHeight, decisionAnchor, cardAnchor);
        }
        if (showDepLinks) {
            drawCardDependencyLinks(canvas, totalPx, totalHeight, cardAnchor);
        }
        if (showMsgLinks) {
            drawMessageDecisionLinks(canvas, totalPx, totalHeight, messageAnchor, decisionProposedAnchor);
        }
    }

    /**
     * Decision↔task connector overlay (v3.78.5). Draws a dashed arrow from
     * each visible decision's "decided" (or "withdrawn") chip to every Deck
     * card linked to it as a task. Using the outcome chip — not "proposed" —
     * communicates causality: the task flows FROM a resolved decision.
     *
     * If a decision's outcome chip is not currently visible (filtered out,
     * or folded into a crowding badge) the connector is silently skipped.
     * The arrowhead sits on the task-card (Deck) end of the line
     * (marker-end), indicating direction: decision → task.
     */
    function drawDecisionTaskLinks(canvas, totalPx, totalHeight, decisionAnchor, cardAnchor) {
        var pairs = [];
        allEvents.forEach(function (ev) {
            // Anchor on the outcome chip (decided or withdrawn), not
            // proposed — the connector communicates causality: task flows
            // FROM a resolved decision. Prefer decided over withdrawn when
            // both exist (same decisionId will produce at most one entry
            // in decisionAnchor anyway, since capture favours 'decided').
            if (ev.source !== 'decisions') return;
            if (ev.type !== 'decided' && ev.type !== 'withdrawn') return;
            var meta = ev.meta || {};
            var from = meta.decisionId ? decisionAnchor[meta.decisionId] : null;
            if (!from) return;
            (meta.linkedCardIds || []).forEach(function (cardId) {
                var to = cardAnchor[cardId];
                if (to) pairs.push({ from: from, to: to });
            });
        });
        if (!pairs.length) return;

        var svgNS = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(svgNS, 'svg');
        svg.setAttribute('class', 'link-overlay');
        svg.setAttribute('width', totalPx);
        svg.setAttribute('height', totalHeight);

        var defs   = document.createElementNS(svgNS, 'defs');
        var marker = document.createElementNS(svgNS, 'marker');
        marker.setAttribute('id', 'th-link-arrow');
        marker.setAttribute('viewBox', '0 0 8 8');
        marker.setAttribute('refX', '7');
        marker.setAttribute('refY', '4');
        marker.setAttribute('markerWidth', '6');
        marker.setAttribute('markerHeight', '6');
        marker.setAttribute('orient', 'auto-start-reverse');
        var arrowHead = document.createElementNS(svgNS, 'path');
        arrowHead.setAttribute('d', 'M0,0 L8,4 L0,8 z');
        arrowHead.setAttribute('class', 'link-arrowhead');
        marker.appendChild(arrowHead);
        defs.appendChild(marker);
        svg.appendChild(defs);

        pairs.forEach(function (pair) {
            var line = document.createElementNS(svgNS, 'line');
            line.setAttribute('x1', pair.from.x);
            line.setAttribute('y1', pair.from.y);
            line.setAttribute('x2', pair.to.x);
            line.setAttribute('y2', pair.to.y);
            line.setAttribute('class', 'link-line');
            line.setAttribute('marker-end', 'url(#th-link-arrow)');
            svg.appendChild(line);
        });

        canvas.appendChild(svg);
    }

    /**
     * Deck card-dependency connector overlay (v3.78.8). NC 34 / Deck 1.18+
     * only — meta.blockedByCardIds is only ever present when
     * TimelineService::isCardDependencySupported() detected the
     * deck_dependent_cards table (see TimelineService::fetchDeckEvents).
     *
     * Draws a dashed arrow from a prerequisite card's "created" chip to the
     * card it blocks. Direction is inferred from Deck's own CardMapper
     * (addDependency($cardId, $dependentCardId) stores "$cardId depends on
     * $dependentCardId"), not confirmed by Nextcloud documentation — if
     * this turns out backwards in practice, flip the two `to`/`from`
     * arguments below.
     *
     * Visually distinct from the decision↔task overlay (amber, solid,
     * separate arrowhead marker id) so both can be on at once without
     * being confused for each other.
     */
    function drawCardDependencyLinks(canvas, totalPx, totalHeight, cardAnchor) {
        var pairs = [];
        allEvents.forEach(function (ev) {
            if (ev.source !== 'deck' || ev.type !== 'created') return;
            var meta = ev.meta || {};
            var to = meta.cardId ? cardAnchor[meta.cardId] : null;
            if (!to) return;
            (meta.blockedByCardIds || []).forEach(function (blockerCardId) {
                var from = cardAnchor[blockerCardId];
                if (from) pairs.push({ from: from, to: to });
            });
        });
        if (!pairs.length) return;

        var svgNS = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(svgNS, 'svg');
        svg.setAttribute('class', 'dep-link-overlay');
        svg.setAttribute('width', totalPx);
        svg.setAttribute('height', totalHeight);

        var defs   = document.createElementNS(svgNS, 'defs');
        var marker = document.createElementNS(svgNS, 'marker');
        marker.setAttribute('id', 'th-dep-link-arrow');
        marker.setAttribute('viewBox', '0 0 8 8');
        marker.setAttribute('refX', '7');
        marker.setAttribute('refY', '4');
        marker.setAttribute('markerWidth', '6');
        marker.setAttribute('markerHeight', '6');
        marker.setAttribute('orient', 'auto-start-reverse');
        var arrowHead = document.createElementNS(svgNS, 'path');
        arrowHead.setAttribute('d', 'M0,0 L8,4 L0,8 z');
        arrowHead.setAttribute('class', 'dep-link-arrowhead');
        marker.appendChild(arrowHead);
        defs.appendChild(marker);
        svg.appendChild(defs);

        pairs.forEach(function (pair) {
            var line = document.createElementNS(svgNS, 'line');
            line.setAttribute('x1', pair.from.x);
            line.setAttribute('y1', pair.from.y);
            line.setAttribute('x2', pair.to.x);
            line.setAttribute('y2', pair.to.y);
            line.setAttribute('class', 'dep-link-line');
            line.setAttribute('marker-end', 'url(#th-dep-link-arrow)');
            svg.appendChild(line);
        });

        canvas.appendChild(svg);
    }

    /**
     * Message↔decision connector overlay (v3.78.9). Draws a dashed arrow
     * from a messageType='decision' stream post's chip to the "proposed"
     * chip of the decision it announced. Every decision is created
     * alongside exactly one such post (see MessageService::createMessage,
     * DecisionService — the decision row's message_id), so this is a
     * straightforward 1:1 join, unlike the other two overlays which can
     * fan out to multiple targets.
     *
     * Anchors on "proposed" specifically (not decided/withdrawn) — the
     * post announced the proposal, not its eventual outcome.
     */
    function drawMessageDecisionLinks(canvas, totalPx, totalHeight, messageAnchor, decisionProposedAnchor) {
        var pairs = [];
        allEvents.forEach(function (ev) {
            if (ev.source !== 'decisions' || ev.type !== 'proposed') return;
            var meta = ev.meta || {};
            if (!meta.sourceMessageId) return;
            var from = messageAnchor[meta.sourceMessageId];
            var to   = decisionProposedAnchor[meta.decisionId];
            if (from && to) pairs.push({ from: from, to: to });
        });
        if (!pairs.length) return;

        var svgNS = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(svgNS, 'svg');
        svg.setAttribute('class', 'msg-link-overlay');
        svg.setAttribute('width', totalPx);
        svg.setAttribute('height', totalHeight);

        var defs   = document.createElementNS(svgNS, 'defs');
        var marker = document.createElementNS(svgNS, 'marker');
        marker.setAttribute('id', 'th-msg-link-arrow');
        marker.setAttribute('viewBox', '0 0 8 8');
        marker.setAttribute('refX', '7');
        marker.setAttribute('refY', '4');
        marker.setAttribute('markerWidth', '6');
        marker.setAttribute('markerHeight', '6');
        marker.setAttribute('orient', 'auto-start-reverse');
        var arrowHead = document.createElementNS(svgNS, 'path');
        arrowHead.setAttribute('d', 'M0,0 L8,4 L0,8 z');
        arrowHead.setAttribute('class', 'msg-link-arrowhead');
        marker.appendChild(arrowHead);
        defs.appendChild(marker);
        svg.appendChild(defs);

        pairs.forEach(function (pair) {
            var line = document.createElementNS(svgNS, 'line');
            line.setAttribute('x1', pair.from.x);
            line.setAttribute('y1', pair.from.y);
            line.setAttribute('x2', pair.to.x);
            line.setAttribute('y2', pair.to.y);
            line.setAttribute('class', 'msg-link-line');
            line.setAttribute('marker-end', 'url(#th-msg-link-arrow)');
            svg.appendChild(line);
        });

        canvas.appendChild(svg);
    }

    /**
     * A crowding count-badge (v3.78.4) — stands in for CROWD_THRESHOLD+
     * same-day, same-section chips. A native <button> so it's keyboard-
     * and screen-reader-accessible for free (focusable, Enter/Space
     * activates, no extra ARIA wiring needed beyond the label).
     *
     * Clicking posts a message to the parent window rather than navigating
     * — the iframe has no navigation state of its own; TeamView.vue owns
     * timelineViewMode/timelineWindowStart and reacts to this message by
     * switching to 1W, snapped to the week containing dayTs.
     */
    function buildBadge(ev) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ecount-badge';
        btn.style.borderColor = SECTION_ACCENT[ev.source] || 'var(--nc-slate)';
        btn.textContent = String(ev.count);

        var dateLabel = fmtDate(new Date(ev.dayTs * 1000), { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' });
        var label = ev.count + ' items on ' + dateLabel + ' — click to view that week';
        btn.title = label;
        btn.setAttribute('aria-label', label);

        btn.addEventListener('click', function () {
            window.parent.postMessage({ app: 'teamhub', type: 'timeline-navigate', from: ev.dayTs }, window.location.origin);
        });

        return btn;
    }

    function buildChip(ev) {
        var cls = 'echip';

        if (ev.source === 'calendar') {
            cls += ' echip--calendar';
        } else if (ev.source === 'decisions') {
            var map = { proposed: 'd-proposed', decided: 'd-decided', withdrawn: 'd-withdrawn' };
            cls += ' echip--' + (map[ev.type] || 'd-proposed');
        } else if (ev.source === 'deck') {
            var deckCls = { created: 'deck-created', due: 'deck-due', completed: 'deck-completed' };
            cls += ' echip--' + (deckCls[ev.type] || 'deck-created');
            if (ev.meta && ev.meta.overdue) cls += ' echip--overdue';
        } else if (ev.source === 'messages') {
            cls += ' echip--message';
            if (ev.meta && ev.meta.pinned) cls += ' echip--message-pinned';
        }

        // Per-source pill content rules (in order of strictness):
        //   • Calendar (events) + Messages (posts): title only, no time, no badge.
        //     The section label and chip colour already communicate the source
        //     and type. Avoids the busy "test 15:00 Event" pattern that filled
        //     pills with redundant data on small chips.
        //   • Decisions: keep the type badge (Proposed / Decided / Withdrawn)
        //     since the same decision can appear multiple times along the
        //     axis and the badge is the only disambiguation. Drop the time.
        //   • Deck: keep the type badge (Created / Due) and the time when due
        //     so the user can tell "due 14:30" from "due 17:00". Plus the
        //     overdue warning icon.
        var showBadge = (ev.source === 'decisions' || ev.source === 'deck');
        var showTime  = (ev.source === 'deck' && !ev.allDay);

        var badgeText = '';
        if (showBadge) {
            badgeText = {
                event: 'Event', proposed: 'Proposed', decided: 'Decided',
                withdrawn: 'Withdrawn', created: 'Created', due: 'Due',
                completed: 'Completed', posted: 'Post'
            }[ev.type] || ev.type;
        }

        var timeLabel = '';
        if (showTime) {
            timeLabel = fmtDate(new Date(ev.date), { hour: '2-digit', minute: '2-digit' });
        }

        var overdueHtml = (ev.source === 'deck' && ev.type === 'due' && ev.meta && ev.meta.overdue)
            ? '<span class="echip__overdue">⚠</span>'
            : '';

        var pinnedHtml = (ev.source === 'messages' && ev.meta && ev.meta.pinned)
            ? '<span class="echip__pinned" title="Pinned">📌</span>'
            : '';

        var html =
            '<span class="echip__dot"></span>' +
            pinnedHtml +
            '<span class="echip__title" title="' + esc(ev.title) + '">' + esc(ev.title) + '</span>' +
            (timeLabel ? '<span class="echip__time">' + esc(timeLabel) + '</span>' : '') +
            overdueHtml +
            (badgeText ? '<span class="echip__badge">' + esc(badgeText) + '</span>' : '');

        var chipHref = safeHref(ev.url);
        if (chipHref) {
            var a = document.createElement('a');
            a.className = cls + ' echip--url';
            a.href = chipHref;
            // The href + target=_top is retained so middle-click / ctrl-click
            // / cmd-click open the item in the parent window the way users
            // expect from any link. Plain left-click is intercepted below
            // and routed to the item popover instead (v3.85.8).
            a.target = '_top';
            a.rel = 'noopener noreferrer';
            a.title = ev.title + ' — click for details';
            a.innerHTML = html;
            a.addEventListener('click', function (e) {
                // Let modifier-clicks behave natively (new tab / new window /
                // download). Only intercept the plain primary-button click.
                if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) {
                    return;
                }
                e.preventDefault();
                openItemPopover(ev, a);
            });
            return a;
        }

        var div = document.createElement('div');
        div.className = cls;
        div.title = ev.title;
        div.innerHTML = html;
        return div;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Item popover (v3.85.8) — single shared element, anchored to whichever
    // chip was last clicked. Click outside / Esc dismisses.
    // ─────────────────────────────────────────────────────────────────────

    var popoverEl = null;

    function getPopover() {
        if (popoverEl) return popoverEl;
        popoverEl = document.createElement('div');
        popoverEl.className = 'th-popover';
        popoverEl.hidden = true;
        popoverEl.setAttribute('role', 'dialog');
        popoverEl.setAttribute('aria-modal', 'false');
        document.body.appendChild(popoverEl);

        // Click outside (anywhere not inside the popover and not on a chip
        // that's about to open it) closes the popover.
        document.addEventListener('mousedown', function (e) {
            if (popoverEl.hidden) return;
            if (popoverEl.contains(e.target)) return;
            if (e.target.closest && e.target.closest('.echip--url')) return;
            closePopover();
        });

        // Esc closes.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !popoverEl.hidden) {
                closePopover();
            }
        });

        // Re-anchor on scroll/resize so the popover doesn't drift off its
        // chip as the user scrolls the iframe.
        var reposition = function () {
            if (!popoverEl.hidden && popoverEl._anchor) {
                positionPopover(popoverEl, popoverEl._anchor);
            }
        };
        window.addEventListener('scroll', reposition, true);
        window.addEventListener('resize', reposition);

        return popoverEl;
    }

    function closePopover() {
        if (popoverEl) {
            popoverEl.hidden = true;
            popoverEl._anchor = null;
        }
    }

    function openItemPopover(ev, anchorEl) {
        var p = getPopover();
        p.innerHTML = renderPopoverContent(ev);
        p.hidden = false;
        p._anchor = anchorEl;
        positionPopover(p, anchorEl);

        var closeBtn = p.querySelector('.th-popover__close');
        if (closeBtn) {
            closeBtn.addEventListener('click', closePopover);
        }
    }

    function positionPopover(p, anchorEl) {
        var rect = anchorEl.getBoundingClientRect();
        var margin = 8;
        var pw = p.offsetWidth;
        var ph = p.offsetHeight;

        // Default: below the chip aligned to its left edge. Flip up if not
        // enough room below; clamp horizontally so the popover never leaves
        // the viewport.
        var top = rect.bottom + 6;
        if (top + ph > window.innerHeight - margin) {
            top = Math.max(margin, rect.top - ph - 6);
        }
        var left = rect.left;
        if (left + pw > window.innerWidth - margin) {
            left = Math.max(margin, window.innerWidth - pw - margin);
        }
        if (left < margin) left = margin;

        p.style.top  = top + 'px';
        p.style.left = left + 'px';
    }

    // Inline Material Design Icon SVG paths. Keep this list tight — one
    // entry per concept we actually surface in the popover. All glyphs are
    // 24×24 source, rendered at 14×14 with currentColor so they pick up the
    // surrounding text colour automatically.
    var MDI_PATHS = {
        'map-marker':   'M12 11.5A2.5 2.5 0 0 1 9.5 9 2.5 2.5 0 0 1 12 6.5 2.5 2.5 0 0 1 14.5 9a2.5 2.5 0 0 1-2.5 2.5M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7Z',
        'calendar':     'M19 19H5V8h14m0-5h-1V1h-2v2H8V1H6v2H5c-1.11 0-2 .89-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2Z',
        'calendar-clock': 'M15 13h1.5v2.82l2.44 1.41-.75 1.3L15 16.69V13m4-5H5v11h4.67c-.43-.91-.67-1.93-.67-3a7 7 0 0 1 7-7c1.07 0 2.09.24 3 .67V8M5 21a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h1V1h2v2h8V1h2v2h1a2 2 0 0 1 2 2v6.1c1.24 1.26 2 2.99 2 4.9a7 7 0 0 1-7 7c-1.91 0-3.64-.76-4.9-2H5m11-9.85A4.85 4.85 0 0 0 11.15 16c0 2.68 2.17 4.85 4.85 4.85A4.85 4.85 0 0 0 20.85 16c0-2.68-2.17-4.85-4.85-4.85Z',
        'account':      'M12 4a4 4 0 0 1 4 4 4 4 0 0 1-4 4 4 4 0 0 1-4-4 4 4 0 0 1 4-4m0 10c4.42 0 8 1.79 8 4v2H4v-2c0-2.21 3.58-4 8-4Z',
        'account-multiple': 'M16 17v2H2v-2s0-4 7-4 7 4 7 4m-3.5-9.5A3.5 3.5 0 1 0 9 11a3.5 3.5 0 0 0 3.5-3.5m3.44 5.5A5.32 5.32 0 0 1 18 17v2h4v-2s0-3.63-6.06-4M15 4a3.4 3.4 0 0 0-1.93.59 5 5 0 0 1 0 5.82A3.4 3.4 0 0 0 15 11a3.5 3.5 0 0 0 0-7Z',
        'view-dashboard': 'M13 3v6h8V3M13 21h8V11h-8M3 21h8v-6H3M3 13h8V3H3v10Z',
        'list-square':  'M19 19V5H5v14h14M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5c-1.11 0-2-.9-2-2V5a2 2 0 0 1 2-2h14M9 7h8v2H9V7m0 4h8v2H9v-2m0 4h8v2H9v-2M7 7v2H5V7h2m0 4v2H5v-2h2m0 4v2H5v-2h2Z',
        'flash':        'M7 2v11h3v9l7-12h-4l4-8H7Z',
        'target':       'M11 2v2.07A8 8 0 0 0 4.07 11H2v2h2.07A8 8 0 0 0 11 19.93V22h2v-2.07A8 8 0 0 0 19.93 13H22v-2h-2.07A8 8 0 0 0 13 4.07V2m-2 4.08V8h2V6.09A5.99 5.99 0 0 1 17.92 11H16v2h1.91A5.99 5.99 0 0 1 13 17.92V16h-2v1.91A5.99 5.99 0 0 1 6.08 13H8v-2H6.09A5.99 5.99 0 0 1 11 6.08M12 11a1 1 0 0 0-1 1 1 1 0 0 0 1 1 1 1 0 0 0 1-1 1 1 0 0 0-1-1Z',
        'tag':          'M5.5 7A1.5 1.5 0 0 1 4 5.5 1.5 1.5 0 0 1 5.5 4 1.5 1.5 0 0 1 7 5.5 1.5 1.5 0 0 1 5.5 7m15.91 4.58-9-9C12.05 2.22 11.55 2 11 2H4c-1.11 0-2 .89-2 2v7c0 .55.22 1.05.59 1.41l9 9c.36.36.86.59 1.41.59.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.05-.59-1.42Z',
        'check':        'M21 7 9 19l-5.5-5.5 1.41-1.41L9 16.17 19.59 5.59 21 7Z',
        'check-decagram': 'm23 12-2.44-2.78.34-3.68-3.61-.82-1.89-3.18L12 3 8.6 1.54 6.71 4.72l-3.61.81.34 3.68L1 12l2.44 2.78-.34 3.69 3.61.82 1.89 3.18L12 21l3.4 1.46 1.89-3.18 3.61-.82-.34-3.68L23 12m-13 5-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8Z',
        'undo-variant': 'M13.5 7A6.5 6.5 0 0 1 20 13.5a6.5 6.5 0 0 1-6.5 6.5H10v-2h3.5c2.5 0 4.5-2 4.5-4.5S16 9 13.5 9H7.83l3.08 3.09L9.5 13.5 4 8l5.5-5.5 1.42 1.41L7.83 7H13.5M6 18h2v2H6v-2Z',
        'pin':          'M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2Z',
        'alert':        'M13 14h-2v-4h2m0 8h-2v-2h2M1 21h22L12 2 1 21Z',
        'plus-circle':  'M17 13h-4v4h-2v-4H7v-2h4V7h2v4h4m-5-9A10 10 0 0 0 2 12a10 10 0 0 0 10 10 10 10 0 0 0 10-10A10 10 0 0 0 12 2Z',
        'circle-small': 'M12 7a5 5 0 0 1 5 5 5 5 0 0 1-5 5 5 5 0 0 1-5-5 5 5 0 0 1 5-5Z'
    };

    function iconSvg(name) {
        var d = MDI_PATHS[name];
        if (!d) return '';
        return '<svg class="th-popover__svg" viewBox="0 0 24 24" aria-hidden="true">'
            +    '<path d="' + d + '" fill="currentColor"/>'
            +  '</svg>';
    }

    function renderPopoverContent(ev) {
        var meta = ev.meta || {};

        var sourceLabel = ({
            calendar:  'Calendar event',
            decisions: ({ proposed: 'Decision proposed', decided: 'Decision decided', withdrawn: 'Decision withdrawn' })[ev.type] || 'Decision',
            deck:      ({ created: 'Deck card created', due: 'Deck card due', completed: 'Deck card completed' })[ev.type] || 'Deck card',
            messages:  'Message post',
            milestone: 'Milestone'
        })[ev.source] || ev.source;

        var appName = ({
            calendar:  'Calendar',
            decisions: 'TeamHub',
            deck:      'Deck',
            messages:  'TeamHub',
            milestone: 'TeamHub'
        })[ev.source] || 'app';

        // Per-event-type date label. The plain "2 jul 2026" alone was
        // ambiguous in the popover; pairing it with "Due" / "Created" /
        // "Completed" / etc tells the reader what the date represents.
        var whenLabelByType = {
            calendar:  { event:      'When' },
            decisions: { proposed:   'Proposed', decided: 'Decided', withdrawn: 'Withdrawn' },
            deck:      { due:        'Due', created: 'Created', completed: 'Completed' },
            messages:  { posted:     'Posted' },
            milestone: { milestone:  'Date' }
        };
        var whenLabel = (whenLabelByType[ev.source] && whenLabelByType[ev.source][ev.type]) || 'When';
        var whenIcon  = (ev.source === 'deck' && ev.type === 'due') ? 'calendar-clock' : 'calendar';

        var rows = [];

        // Date row first so the popover reads top-to-bottom: "what kind of
        // moment is this?" before the source-specific detail rows.
        rows.push(detailRow(whenIcon, whenLabel, esc(formatWhen(ev))));

        if (ev.source === 'calendar') {
            if (meta.location)              rows.push(detailRow('map-marker',       'Location',  esc(meta.location)));
            if (meta.calendarName)          rows.push(detailRow('calendar',         'Calendar',  esc(meta.calendarName)));
            if (meta.organizer)             rows.push(detailRow('account',          'Organizer', esc(meta.organizer)));
            if (meta.attendeeCount > 0)     rows.push(detailRow('account-multiple', 'Attendees', String(meta.attendeeCount)));
        } else if (ev.source === 'decisions') {
            if (meta.status)                rows.push(detailRow('circle-small',     'Status',    esc(meta.status)));
            if (meta.impact)                rows.push(detailRow('flash',            'Impact',    esc(meta.impact)));
            if (meta.level)                 rows.push(detailRow('target',           'Level',     esc(meta.level)));
            if (meta.category)              rows.push(detailRow('tag',              'Category',  esc(meta.category)));
            var proposedBy = meta.proposedByName || meta.proposedBy;
            if (proposedBy)                 rows.push(detailRow('account',          'Proposed by', esc(proposedBy)));
            var decidedBy = meta.decidedByName || meta.decidedBy;
            if (ev.type !== 'proposed' && decidedBy) {
                rows.push(detailRow(ev.type === 'withdrawn' ? 'undo-variant' : 'check-decagram',
                                    ev.type === 'withdrawn' ? 'Withdrawn by' : 'Decided by',
                                    esc(decidedBy)));
            }
        } else if (ev.source === 'deck') {
            if (meta.boardName)             rows.push(detailRow('view-dashboard',   'Board',    esc(meta.boardName)));
            if (meta.stackName)             rows.push(detailRow('list-square',      'Column',   esc(meta.stackName)));
            // Assignees: prefer the resolved display names, fall back to raw UIDs.
            var assignees = (meta.assigneeNames && meta.assigneeNames.length) ? meta.assigneeNames
                          : (meta.assignees && meta.assignees.length) ? meta.assignees : null;
            if (assignees && assignees.length) {
                var assigneeIcon = assignees.length > 1 ? 'account-multiple' : 'account';
                var assigneeLabel = assignees.length > 1 ? 'Assignees' : 'Assigned to';
                rows.push(detailRow(assigneeIcon, assigneeLabel, esc(assignees.join(', '))));
            }
            if (ev.type === 'due' && meta.overdue)
                                            rows.push(detailRow('alert',            'Status',   'Overdue'));
            if (meta.completed && ev.type !== 'completed')
                                            rows.push(detailRow('check',            'Status',   'Completed'));
        } else if (ev.source === 'messages') {
            var author = meta.authorName || meta.authorId;
            if (author)                     rows.push(detailRow('account',          'Posted by', esc(author)));
            if (meta.pinned)                rows.push(detailRow('pin',              'Pinned',   'Yes'));
        } else if (ev.source === 'milestone') {
            var creator = meta.createdByName || meta.createdBy;
            if (creator)                    rows.push(detailRow('account',          'Created by', esc(creator)));
        }

        var description = meta.description ? esc(meta.description) : '';
        var snippet     = meta.snippet     ? esc(meta.snippet)     : '';

        var openHref = safeHref(ev.url);
        var openBtn = openHref
            ? '<a class="th-popover__open" href="' + esc(openHref) + '" target="_top" rel="noopener noreferrer">'
              + 'Open in ' + esc(appName) + ' →</a>'
            : '';

        return ''
            + '<div class="th-popover__header">'
            +   '<span class="th-popover__source">' + esc(sourceLabel) + '</span>'
            +   '<button class="th-popover__close" type="button" aria-label="Close">✕</button>'
            + '</div>'
            + '<h3 class="th-popover__title">' + esc(ev.title) + '</h3>'
            + '<dl class="th-popover__details">' + rows.join('') + '</dl>'
            + (description    ? '<div class="th-popover__description">' + description + '</div>' : '')
            + (snippet        ? '<div class="th-popover__snippet">'     + snippet     + '</div>' : '')
            + (openBtn        ? '<div class="th-popover__footer">'      + openBtn     + '</div>' : '');
    }

    function detailRow(iconName, label, valueHtml) {
        return '<div class="th-popover__row">'
            +    '<dt>' + iconSvg(iconName) + esc(label) + '</dt>'
            +    '<dd>' + valueHtml + '</dd>'
            +  '</div>';
    }

    function formatWhen(ev) {
        var start = new Date(ev.date);
        var end   = ev.endDate ? new Date(ev.endDate) : null;
        var dOpts = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' };
        var tOpts = { hour: '2-digit', minute: '2-digit' };

        // An all-day event is a calendar day, so it never goes through the
        // reader's zone — see fmtIsoDay.
        if (ev.allDay) return fmtIsoDay(ev.date, dOpts);

        var startDate = fmtDate(start, dOpts);
        var startTime = fmtDate(start, tOpts);
        if (!end) return startDate + ' · ' + startTime;

        var endDate = fmtDate(end, dOpts);
        var endTime = fmtDate(end, tOpts);
        if (startDate === endDate) {
            return startDate + ' · ' + startTime + ' – ' + endTime;
        }
        return startDate + ' ' + startTime + ' – ' + endDate + ' ' + endTime;
    }

    fetchAndRender();
})();
</script>
<?php endif; ?>
</body>
</html>
