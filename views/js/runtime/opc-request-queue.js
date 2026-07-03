/**
 * Global single-flight queue for OPC ajax calls, with latest-wins coalescing per key.
 *
 * Concurrent OPC requests mutate the same cart row server-side (several through
 * full-row Cart::save() calls — including Core's own carrier computation) from each
 * request's own snapshot, so overlapping requests lose writes: duplicated/orphaned
 * inline addresses, invoice pointer reverted to a stale value. Sending ONE request at
 * a time, built at SEND time, removes the overlap at the source — a request can
 * neither carry nor load a stale snapshot, because the previous write is committed
 * before it is even sent.
 *
 * Latest-wins: a queued (not yet sent) task with the same key is replaced by a newer
 * one, so bursts collapse into the single most recent call per endpoint.
 *
 * Window-level singleton: the checkout ships several webpack bundles, each embedding
 * this module — the state MUST be shared across bundles or serialization is lost.
 *
 * Deliberately NOT covered: multi-tab checkouts (one queue per page), retries,
 * priorities. The watchdog only unblocks the QUEUE when a request never settles
 * (network black hole); it does not abort the request itself.
 */
const QUEUE_WATCHDOG_MS = 20000;

function getQueueState() {
  if (!window.__opcRequestQueueState) {
    window.__opcRequestQueueState = {pending: [], inFlight: false, flushScheduled: false};
  }

  return window.__opcRequestQueueState;
}

// First flush is deferred by one tick: several calls fired by the SAME event cascade
// (e.g. two listeners of a use_same toggle both requesting an address refresh) coalesce
// into the single latest task BEFORE anything is sent. An immediate flush would send the
// first call and demote the burst's fresher calls to a later, different server state —
// and their generation guards would then discard the first response (a surfaced error
// could be silently swallowed as stale).
function scheduleFlush() {
  const state = getQueueState();
  if (state.flushScheduled) {
    return;
  }
  state.flushScheduled = true;
  setTimeout(() => {
    state.flushScheduled = false;
    flushQueue();
  }, 0);
}

function flushQueue() {
  const state = getQueueState();
  if (state.inFlight || state.pending.length === 0) {
    return;
  }

  const task = state.pending.shift();
  state.inFlight = true;

  let watchdog = null;
  let settled = false;
  const settle = () => {
    if (settled) {
      return;
    }
    settled = true;
    if (watchdog) {
      clearTimeout(watchdog);
    }
    state.inFlight = false;
    flushQueue();
  };

  let request = null;
  try {
    request = task.send();
  } catch (error) {
    settle();
    throw error;
  }

  if (request && typeof request.always === 'function') {
    watchdog = setTimeout(settle, QUEUE_WATCHDOG_MS);
    request.always(settle);
  } else {
    // The task decided not to fire (no usable URL, superseded generation): nothing to wait for.
    settle();
  }
}

/**
 * @param {string} key endpoint key — a PENDING task with the same key is replaced
 * @param {() => (object|null)} send builds URL/payload and fires the request at SEND time; returns the jqXHR (or null to skip)
 * @param {() => void} [onDiscard] called when the task is replaced before being sent
 */
export function enqueueOpcRequest(key, send, onDiscard) {
  const state = getQueueState();

  const existingIndex = state.pending.findIndex((task) => task.key === key);
  if (existingIndex !== -1) {
    const [discarded] = state.pending.splice(existingIndex, 1);
    if (typeof discarded.onDiscard === 'function') {
      discarded.onDiscard();
    }
  }

  state.pending.push({key, send, onDiscard});
  scheduleFlush();
}
