import { onBeforeUnmount, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';

const INTERVAL_MS = 30 * 1000;
// Each refresh renews the session, so stop while nobody is at the screen and let it expire as usual.
const IDLE_LIMIT_MS = 10 * 60 * 1000;
const ACTIVITY_EVENTS = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart'];

// Shared props behind the bell, the sidebar badges and the safety alert banner.
// realtime: a fresh WebSocket token, so a long session can reconnect.
const SHARED_PROPS = ['unreadNotificationsCount', 'queueCounts', 'pendingSafetyAlert', 'realtime'];

/**
 * Quietly reloads the badge counts every 30 seconds, plus any of the current
 * page's own props named in pageProps (e.g. a list). Skips hidden tabs and
 * idle users; catches up as soon as the tab is shown again.
 */
export function useAutoRefresh(pageProps = () => []) {
    let timer = null;
    let lastActivity = Date.now();

    const markActive = () => {
        lastActivity = Date.now();
    };

    function refresh() {
        if (document.visibilityState !== 'visible' || Date.now() - lastActivity > IDLE_LIMIT_MS) {
            return;
        }

        router.reload({
            only: [...SHARED_PROPS, ...pageProps()],
            async: true,
            showProgress: false,
        });
    }

    function onVisibilityChange() {
        if (document.visibilityState === 'visible') {
            refresh();
        }
    }

    onMounted(() => {
        ACTIVITY_EVENTS.forEach((event) => window.addEventListener(event, markActive, { passive: true }));
        document.addEventListener('visibilitychange', onVisibilityChange);
        timer = window.setInterval(refresh, INTERVAL_MS);
    });

    onBeforeUnmount(() => {
        ACTIVITY_EVENTS.forEach((event) => window.removeEventListener(event, markActive));
        document.removeEventListener('visibilitychange', onVisibilityChange);
        window.clearInterval(timer);
    });

    // A real-time push calls this to update the badges and lists at once.
    return { refresh };
}
