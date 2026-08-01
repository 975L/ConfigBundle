/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import { Controller } from "@hotwired/stimulus";

// Follows the run queued by the "Run health check now" button, whose jobs land in a Messenger worker the page knows nothing about, and reloads as each one records its results - the rows are written straight to the database, so a reload is the whole of what's needed to show them, and the banner is rendered again from the run still being followed server-side (see HealthCheckRunProgress)
export default class extends Controller {
    static targets = ['message', 'spinner'];
    static values = {
        url: String,
        // What the page was rendered with: anything above it means results landed since, and the tables below are out of date
        done: Number,
        interval: { type: Number, default: 5000 },
        timedOutMessage: String,
    };

    connect() {
        this.timer = setInterval(() => this.poll(), this.intervalValue);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    async poll() {
        let progress;

        // A failed poll is not worth reporting: the run carries on regardless, and the next poll is 5 seconds away
        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            progress = await response.json();
        } catch {
            return;
        }

        // Given up on server-side, every check still missing having recorded nothing at all - most often a worker that was never started. Tested before the reload below, and not after: the poll tripping the timeout can be one that also brought results in, and reloading then would lose the warning for good, that very poll having cleared the run server-side
        if (progress.timedOut) {
            clearInterval(this.timer);
            this.messageTarget.textContent = this.timedOutMessageValue;
            this.spinnerTarget.remove();
            this.element.classList.replace('alert-info', 'alert-warning');

            return;
        }

        if (progress.done > this.doneValue || progress.finished) {
            window.location.reload();
        }
    }
}
