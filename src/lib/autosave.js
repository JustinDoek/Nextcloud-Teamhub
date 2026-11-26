/**
 * One autosaver per form on a page that saves itself (v4.20.12, DESIGN §2.265).
 *
 * The admin panel's Services tab (§2.225) set the pattern: a debounce while
 * somebody types, an immediate write on a discrete action, one quiet status
 * region for the outcome. This is that pattern without the component around
 * it, so the four sections of Settings → Personal → TeamHub share one
 * implementation and a node test can pin it.
 *
 *   const saver = createAutosaver({ save: () => this.save(), delay: 800 })
 *   saver.schedule()   // typing: write once the person pauses
 *   saver.now()        // a switch, a select, a picked workplace: write now
 *   saver.flush()      // leaving the page: write a pending change at once
 *   saver.cancel()     // unmount: drop a pending write
 *
 * **Writes never overlap.** A change made while a save is in flight is not a
 * second concurrent request (two PUTs racing could land in either order and
 * leave the older state on the server); it marks the saver dirty and one
 * more save runs when the first returns, carrying whatever the form holds
 * by then. `save` must therefore read the form when it runs, not when it was
 * scheduled.
 *
 * @param {object} options
 * @param {function(): Promise<unknown>} options.save Reads current state and writes it.
 * @param {number} [options.delay=800] Debounce for `schedule()`, in ms.
 * @return {{schedule: function(): void, now: function(): Promise<void>, flush: function(): Promise<void>, cancel: function(): void, readonly pending: boolean, readonly running: boolean}}
 */
export function createAutosaver({ save, delay = 800 }) {
    let timer = null
    let running = null
    let again = false

    const clear = () => {
        if (timer !== null) {
            clearTimeout(timer)
            timer = null
        }
    }

    const run = async () => {
        clear()
        if (running) {
            again = true
            return running
        }
        running = (async () => {
            try {
                do {
                    again = false
                    try {
                        await save()
                    } catch (e) {
                        // The component reports its own failure; a rejected
                        // save must not stop the follow-up the person asked for.
                    }
                } while (again)
            } finally {
                running = null
            }
        })()
        return running
    }

    return {
        schedule() {
            clear()
            timer = setTimeout(() => {
                timer = null
                run()
            }, delay)
        },
        now: run,
        flush() {
            return timer !== null ? run() : (running || Promise.resolve())
        },
        cancel: clear,
        get pending() {
            return timer !== null
        },
        get running() {
            return running !== null
        },
    }
}
