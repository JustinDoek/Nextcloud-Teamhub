<template>
    <span class="room-timeline" :class="{ 'room-timeline--tall': tall }" role="img" :aria-label="label">
        <span
            v-for="(segment, i) in segments"
            :key="`busy-${i}`"
            class="room-timeline__busy"
            :style="{ insetInlineStart: `${segment.offset}%`, width: `${segment.size}%` }" />
        <span
            v-for="(mark, i) in hours"
            :key="`hour-${i}`"
            class="room-timeline__hour"
            :class="{ 'room-timeline__hour--labelled': mark.label }"
            :style="{ insetInlineStart: `${mark.offset}%` }" />
        <span
            v-if="nowAt !== null"
            class="room-timeline__now"
            :style="{ insetInlineStart: `${nowAt}%` }" />
        <span
            v-if="slotSize > 0"
            class="room-timeline__slot"
            :style="{ insetInlineStart: `${slotOffset}%`, width: `${slotSize}%` }" />
    </span>
</template>

<script>
/**
 * RoomFinderTimeline (v4.20.0): one meeting room's day as a line, with its
 * bookings as filled blocks and the meeting being planned as an outlined
 * window on top, so a clash is visible as an overlap rather than read from a
 * label. Geometry comes from `timelineSegments()` and `hourMarks()` in
 * `src/lib/roomFinder.js`; the inline offsets are computed coordinates, not
 * layout.
 *
 * v4.20.7: hour lines cut through the bookings so you can count how late a
 * room is taken, every second one a little stronger to match the labelled
 * scale above the list; a thin line marks now when the day is today; `tall`
 * is the room list's size.
 *
 * Purely presentational. The accessible name carries the window; the chip
 * and the "free until" text beside it carry the verdict, so nothing rests on
 * the drawing alone.
 */
export default {
    name: 'RoomFinderTimeline',
    props: {
        segments: { type: Array, default: () => [] },
        slotOffset: { type: Number, default: 0 },
        slotSize: { type: Number, default: 0 },
        hours: { type: Array, default: () => [] },
        nowAt: { type: Number, default: null },
        tall: { type: Boolean, default: false },
        label: { type: String, default: '' },
    },
}
</script>

<style scoped>
.room-timeline {
    position: relative;
    display: block;
    height: var(--th-space-md);
    border-radius: var(--border-radius-small);
    background: var(--color-background-dark);
    overflow: hidden;
}

.room-timeline--tall {
    height: var(--th-space-xl);
}

.room-timeline__busy {
    position: absolute;
    inset-block: 0;
    background: var(--color-element-error);
    opacity: 0.55;
}

/* Drawn in the canvas colour on top of the bookings: on the empty track it
   reads as a faint tick, across a booking it splits it into hours. */
.room-timeline__hour {
    position: absolute;
    inset-block: 0;
    width: 1px;
    background: var(--color-main-background);
    opacity: 0.6;
}

.room-timeline__hour--labelled {
    opacity: 1;
}

.room-timeline__now {
    position: absolute;
    inset-block: 0;
    width: 2px;
    background: var(--color-main-text);
}

.room-timeline__slot {
    position: absolute;
    inset-block: 0;
    box-sizing: border-box;
    border: 2px solid var(--color-primary-element);
    border-radius: var(--border-radius-small);
}
</style>
