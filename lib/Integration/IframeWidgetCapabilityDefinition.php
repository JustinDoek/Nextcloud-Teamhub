<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

use OCA\TeamHub\Exception\ValidationException;

/**
 * A blank-canvas widget — one card in a team's widget grid whose body the
 * owning app draws itself (v4.13.24).
 *
 * ## What this is for
 *
 * {@see WidgetCapabilityDefinition} names a PHP class and gets one content
 * shape back: `items`, each flattened to a label, a value, an icon and a URL,
 * twenty at most. That is the right contract for a list of things and it was
 * the only contract there was, so an app whose card is a chart, a board, a
 * small form or a floor plan had two choices — emit rows that are not what it
 * means, or register a whole tab for something that wanted a card.
 *
 * This is the card with a free interior. TeamHub keeps what the grid needs to
 * stay a grid:
 *
 *  - the position, the size and the resize handles, which the team arranges;
 *  - the header, the title, the app's icon and the collapse control;
 *  - the team administrator's shown/hidden preference;
 *  - the administrator's enablement and the template inclusion above it.
 *
 * The app gets everything inside the body, on the same terms the iframe tab
 * already has: `{@see $iframeUrl}` is loaded in a sandboxed frame with
 * TeamHub's own verified team id appended as `?teamId=`.
 *
 * ## What it costs, and what to put in it
 *
 * A frame is a document: its own stylesheet, its own scripts, its own request.
 * A card is small and several of them may be on one home view, so a canvas
 * widget is the wrong choice for three numbers and a link — that is what the
 * row contract is for, and it costs one JSON payload shared with every other
 * widget on the view. Reach for a canvas when the content genuinely is not a
 * list.
 *
 * Render for the size: the card is a few hundred pixels wide and a team can
 * collapse it to its header. A page that needs a scrollbar at card size
 * belongs on a tab.
 *
 * ## Actions: links only (v4.13.29)
 *
 * Until v4.13.29 there were deliberately none, and the argument is worth
 * keeping because it is what shapes the ones there are now: a row widget's
 * actions open a TeamHub modal built from `getActionForm()`, because TeamHub
 * draws that card and owns its controls; a canvas owns its interior, so a
 * second set of controls in the header that the frame knows nothing about
 * would be two apps drawing one card.
 *
 * What changed is a real integration. TOPdesk's canvas drew its own action
 * menu inside the body, where it reads as part of the content rather than as
 * the card's controls, and sat a second menu beside the collapse control
 * TeamHub already draws. Justin's call, 2026-10-25.
 *
 * The objection is answered by the restriction rather than waved away: a
 * declared action is **a label, an optional icon and a URL**. Nothing is
 * posted into the frame and nothing is read out of it, so the two can never
 * disagree about state — TeamHub renders a menu of destinations it knew at
 * registration and sends the person to one. Anything that acts on what the
 * canvas is currently showing stays a control inside the canvas, where it has
 * the state to do it.
 *
 * So: put a destination here (`New ticket`, `All my assets`, `Open in
 * TOPdesk`). Do not put a verb that operates on the frame's contents.
 *
 * Ten at most — the same ceiling the row widget's `actions` have, for the same
 * reason: it is a 3-dot menu on a card, not a navigation tree.
 *
 * Each URL goes through {@see IframeUrl}, exactly like `$iframeUrl`. A link
 * TeamHub renders is a link TeamHub is responsible for, so `javascript:`,
 * `data:` and plain `http://` are refused at registration rather than at the
 * click.
 *
 * ## Security
 *
 * The URL goes through {@see IframeUrl} at registration and again every time
 * the card is rendered, because a stored row can predate a tightening of the
 * rule. The frame is sandboxed, and the page must send
 * `Content-Security-Policy: frame-ancestors 'self'`.
 *
 * TeamHub appends a team id it has verified for the signed-in viewer, but the
 * page is an ordinary route and anybody can call it with any team id — so it
 * checks membership itself. The same obligation the tab has, in `developers.md`
 * under "A team tab", and it is not optional at card size.
 */
final class IframeWidgetCapabilityDefinition extends CapabilityDefinition {

    /** The 3-dot menu is a card's menu, not a navigation tree. */
    public const MAX_ACTIONS = 10;

    /** Long enough for a short verb phrase, short enough to stay on one line. */
    public const MAX_ACTION_LABEL = 64;

    /**
     * @param string                    $iframeUrl    `/apps/…`, `/index.php/…` or `https://…`
     * @param array<string,mixed>       $presentation optional render hints; `size` ∈ small|medium|large
     * @param list<array<string,mixed>> $actions      header links; each `['label' => …, 'url' => …, 'icon' => …?]`
     *
     * @throws ValidationException
     */
    public function __construct(
        string                 $key,
        string                 $title,
        public readonly string $iframeUrl,
        ?string                $description = null,
        ?string                $icon = null,
        int                    $defaultOrder = 0,
        array                  $surfaces = [],
        array                  $presentation = [],
        public readonly array  $actions = [],
    ) {
        parent::__construct($key, $title, $description, $icon, $defaultOrder, $surfaces, $presentation);

        IframeUrl::require($iframeUrl, "Iframe widget capability '{$key}' iframeUrl");

        $this->normalisedActions = self::normaliseActions($actions, $key);
    }

    /** @var list<array{label: string, url: string, icon: ?string}> */
    private readonly array $normalisedActions;

    public function type(): string {
        return CapabilityType::WIDGET_IFRAME;
    }

    public function iframeUrl(): ?string {
        return trim($this->iframeUrl);
    }

    /**
     * The actions as they are stored and rendered: trimmed, validated, in the
     * order declared.
     *
     * @return list<array{label: string, url: string, icon: ?string}>
     */
    public function actions(): array {
        return $this->normalisedActions;
    }

    /**
     * Validate and normalise at registration, so a malformed action is a
     * registration failure the app's developer sees rather than a broken menu
     * item a team admin reports months later.
     *
     * @param list<array<string,mixed>> $actions
     * @return list<array{label: string, url: string, icon: ?string}>
     * @throws ValidationException
     */
    private static function normaliseActions(array $actions, string $key): array {
        if ($actions === []) {
            return [];
        }

        if (count($actions) > self::MAX_ACTIONS) {
            throw new ValidationException(
                "Iframe widget capability '{$key}' declares " . count($actions)
                . ' actions; at most ' . self::MAX_ACTIONS . ' are allowed.',
            );
        }

        $out = [];
        foreach (array_values($actions) as $i => $action) {
            if (!is_array($action)) {
                throw new ValidationException(
                    "Iframe widget capability '{$key}' action #{$i} must be an array.",
                );
            }

            $label = trim((string)($action['label'] ?? ''));
            if ($label === '') {
                throw new ValidationException(
                    "Iframe widget capability '{$key}' action #{$i} needs a label.",
                );
            }
            if (mb_strlen($label) > self::MAX_ACTION_LABEL) {
                throw new ValidationException(
                    "Iframe widget capability '{$key}' action '{$label}' exceeds "
                    . self::MAX_ACTION_LABEL . ' characters.',
                );
            }

            // The same rule as the frame's own URL, and for a stronger reason:
            // this one is followed in the person's own tab, not in a sandbox.
            $url = IframeUrl::require(
                (string)($action['url'] ?? ''),
                "Iframe widget capability '{$key}' action '{$label}' url",
            );

            $icon = isset($action['icon']) ? trim((string)$action['icon']) : '';

            $out[] = [
                'label' => $label,
                'url'   => $url,
                'icon'  => $icon === '' ? null : $icon,
            ];
        }

        return $out;
    }
}
