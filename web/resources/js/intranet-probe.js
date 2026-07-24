const INTRANET_PROBE_URL = document.querySelector('meta[name="intranet-probe-url"]')?.content || '';
const INTRANET_PROBE_TIMEOUT_MS = 900;

let _probeCache = null;

export async function probeIntranet() {
    if (_probeCache !== null) return _probeCache;
    if (!INTRANET_PROBE_URL) {
        _probeCache = false;
        return false;
    }
    try {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), INTRANET_PROBE_TIMEOUT_MS);
        const res = await fetch(INTRANET_PROBE_URL, {
            mode: 'cors',
            signal: controller.signal,
        });
        clearTimeout(timer);
        _probeCache = res.ok;
    } catch {
        _probeCache = false;
    }
    return _probeCache;
}

export function resetProbeCache() {
    _probeCache = null;
}
