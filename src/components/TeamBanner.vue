<template>
    <!-- v4.13.1 — the team's banner (DESIGN §2.185).

         Renders only when the team actually has one: a team with no banner
         gets no strip and loses no height, which matters because this sits in
         a fixed-height column above tabs that embed other apps in iframes.

         The picture is decorative — everything it says in words (the team's
         name, its description) is in the text on top of it — so `alt` is
         deliberately empty rather than repeating the name to a screen reader
         that has just read it from the heading. -->
    <section
        v-if="bannerUrl"
        class="th-banner"
        :class="{ 'th-banner--compact': compact }">
        <img class="th-banner__img" :src="bannerUrl" alt="" />

        <!-- The gradient that makes the name legible over an arbitrary
             photograph. aria-hidden: it is paint, not content. -->
        <div class="th-banner__scrim" aria-hidden="true"></div>

        <!-- v4.13.60 — the description is no longer a flex sibling of the
             name. Sharing one row made the two compete for a single
             width: a long description squeezed the name into an ellipsis
             and a short one left a gap in the middle of the strip. It is
             its own box against the inline-end edge now (Justin), so the
             name has the whole bottom line and the description's length
             changes nothing but its own height. -->
        <aside v-if="description" class="th-banner__aside">
            <p class="th-banner__description">{{ description }}</p>
        </aside>

        <div class="th-banner__overlay">
            <h1 class="th-banner__name" :title="teamName">{{ teamName }}</h1>
        </div>
    </section>
</template>

<script>
import { mapGetters, mapState } from 'vuex'

export default {
    name: 'TeamBanner',

    props: {
        /**
         * Shorter strip for narrow viewports. The parent decides, because it
         * is the one that already knows whether it is in tablet or mobile
         * layout — this component never measures the window itself.
         */
        compact: { type: Boolean, default: false },
    },

    computed: {
        ...mapState(['teamBanner']),
        ...mapGetters(['currentTeam']),

        /**
         * The banner URL comes from the layout bundle rather than the teams
         * list, so it is the current team's by construction. It already
         * carries the stored file's version as a query parameter, so
         * replacing a banner changes this string and the browser reloads it
         * without any cache-busting here.
         */
        bannerUrl() {
            return this.teamBanner?.url || null
        },

        teamName() {
            return this.currentTeam?.name || ''
        },

        description() {
            const d = this.currentTeam?.description
            return typeof d === 'string' && d.trim() !== '' ? d.trim() : null
        },
    },
}
</script>

<style scoped>
.th-banner {
    position: relative;
    flex: 0 0 auto;
    height: var(--th-banner-height);
    overflow: hidden;
    background: var(--color-background-dark);
    border-bottom: 1px solid var(--color-border);
}

.th-banner--compact {
    height: var(--th-banner-height-compact);
}

.th-banner__img {
    display: block;
    width: 100%;
    height: 100%;
    /* The stored file keeps the uploaded aspect ratio (the server scales but
       never crops), so the crop to this strip happens here. Centred, because
       nothing in the upload says which part matters. */
    object-fit: cover;
    object-position: center;
}

.th-banner__scrim {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        to bottom,
        var(--th-banner-scrim-from) 30%,
        var(--th-banner-scrim-mid) 65%,
        var(--th-banner-scrim-to) 100%
    );
    pointer-events: none;
}

/* The name's own band along the bottom. It keeps the full width: the
   description has left this row (v4.13.60), so nothing here shortens the
   name but the name itself. The description box now shares this bottom band
   (v4.13.61) but not this element: it is positioned against the inline-end
   edge, and the name's own `padding-inline-end` is what keeps a very long
   name from running under it. */
.th-banner__overlay {
    position: absolute;
    inset-inline: 0;
    inset-block-end: 0;
    display: flex;
    align-items: flex-end;
    padding: var(--th-space-md) var(--th-space-xl);
    /* min(), so a narrow banner does not give the whole row away to a
       reservation for a box that is itself capped at 45%. */
    padding-inline-end: min(36ch, 45%);
}

.th-banner__name {
    margin: 0;
    min-width: 0;
    max-width: 100%;
    font-size: var(--th-font-title);
    font-weight: var(--th-font-weight-bold);
    line-height: var(--th-line-height-tight);
    color: var(--th-banner-ink);
    /* The name sits on the darkest part of the gradient, but a banner can be
       a photograph of anything: the shadow is what keeps it readable over a
       bright patch rather than trusting the scrim alone. */
    text-shadow: 0 1px 3px var(--th-banner-scrim-to);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* The description's box, stuck to the banner's inline-end edge (v4.13.60).
   Positioned rather than laid out in a row, because its height now depends
   on how much there is to say and nothing else on the strip may move when
   that changes. It sits on the same bottom line as the name — one block of
   padding up from the edge, the overlay's own — so the two read as one band
   across the strip. Centring it (the first attempt) left it floating in the
   middle of the picture with the name far below, which is not what the two
   halves of one caption should look like; sharing a *row* was the thing that
   went wrong before, and that is a width problem this box no longer has.

   `max-width` in ch, not a percentage of the banner: the measure that keeps
   a sentence readable is a character count, and the old 45% gave a wide
   screen a 600px line and a laptop a cramped one. */
.th-banner__aside {
    position: absolute;
    inset-inline-end: 0;
    inset-block-end: var(--th-space-md);
    max-width: min(36ch, 45%);
    display: flex;
}

/* The card itself — themed surface rather than a literal white, so it is
   white on the default theme and readable on a dark one, and its text keeps
   NC's own contrast guarantee instead of a hand-picked ink. Square against
   the edge it sits on and rounded on the other side: the shape is what says
   "attached to the banner" rather than "floating on it". */
.th-banner__description {
    margin: 0;
    padding: var(--th-space-sm) var(--th-space-md);
    background: var(--color-main-background);
    color: var(--color-main-text);
    border-start-start-radius: var(--border-radius-container);
    border-end-start-radius: var(--border-radius-container);
    box-shadow: 0 1px 4px var(--color-box-shadow);
    font-size: var(--th-font-meta);
    line-height: var(--th-line-height-body);
    /* Four lines, then ellipsis — one more than the shared row could hold,
       which is what the taller strip bought. The full description is on
       Manage team; the point here is a glance, not a read. */
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 4;
    overflow: hidden;
}

/* Narrow viewports: the description card would leave the name a few
   characters, so the name keeps the strip to itself. */
@media (max-width: 1024px) {
    .th-banner__aside {
        display: none;
    }

    .th-banner__name {
        font-size: var(--th-font-heading-lg);
    }

    /* The reservation goes with the box it was reserving for: the name has
       the whole strip here. `padding-inline` writes both sides, which is what
       also clears the `padding-inline-end` set above. */
    .th-banner__overlay {
        padding-inline: var(--th-space-lg);
    }
}
</style>
