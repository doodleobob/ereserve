(() => {
    const card = document.querySelector('[data-email-verification]');
    if (!card) return;

    const interval = 2500;
    let pollTimer;
    let redirectTimer;
    let inFlight = false;
    let verified = false;
    let stopped = false;

    function showSuccess() {
        if (verified) return;
        verified = true;
        clearTimeout(pollTimer);
        const waiting = card.querySelector('[data-verification-waiting]');
        if (waiting) waiting.hidden = true;
        const success = card.querySelector('[data-verification-success]');
        success.hidden = false;
        success.focus({ preventScroll: true });
        document.title = 'Email Verified - eReserve';
        redirectTimer = setTimeout(() => window.location.replace(card.dataset.destination), 2000);
    }

    async function checkStatus() {
        clearTimeout(pollTimer);
        if (verified || stopped || inFlight || document.hidden) return;
        inFlight = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        let delay = interval;
        try {
            const response = await fetch(card.dataset.statusUrl, {
                // POST bypasses the existing PWA's GET cache; the server only reads status.
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': card.dataset.csrfToken },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
            });
            if (response.redirected || [401, 403, 419].includes(response.status)) {
                stopped = true;
            } else if (response.ok) {
                const status = await response.json();
                if (!stopped && status.verified === true) showSuccess();
            } else if (response.status === 429) {
                delay = 10000;
            }
        } catch {
            // A temporary network failure should not interrupt the verification page.
        } finally {
            clearTimeout(timeout);
            inFlight = false;
            if (!verified && !stopped && !document.hidden) {
                pollTimer = setTimeout(checkStatus, delay);
            }
        }
    }

    document.addEventListener('visibilitychange', () => {
        clearTimeout(pollTimer);
        if (!document.hidden) checkStatus();
    });
    window.addEventListener('pagehide', () => {
        stopped = true;
        clearTimeout(pollTimer);
        clearTimeout(redirectTimer);
    });
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        stopped = false;
        if (verified) {
            redirectTimer = setTimeout(() => window.location.replace(card.dataset.destination), 2000);
        } else {
            checkStatus();
        }
    });

    if (card.dataset.verified === 'true') showSuccess();
    else checkStatus();
})();
