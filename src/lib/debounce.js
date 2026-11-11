/**
 * A cancellable trailing-edge debounce for grid saves.
 *
 * Changing teams, enforcing a team preset and publishing a team layout must
 * cancel pending personal saves. Calling `.cancel()` on a plain function used
 * to throw and leave the layout editor unusable after a team preset was saved.
 */
export function debounce(fn, delay) {
    let timer = null
    const debounced = function (...args) {
        if (timer !== null) clearTimeout(timer)
        timer = setTimeout(() => {
            timer = null
            fn.apply(this, args)
        }, delay)
    }
    debounced.cancel = () => {
        if (timer !== null) clearTimeout(timer)
        timer = null
    }
    return debounced
}
