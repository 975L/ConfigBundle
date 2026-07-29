/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import { Controller } from "@hotwired/stimulus";
import { buildButton, buildElement, clearHighlight, highlight } from "./guided-ui.js";

// Walks the dashboard's onboardingSteps, matching them against the sidebar's rendered a[href]
// The panel is wired by hand, being appended to <body> outside this controller's element
export default class extends Controller {
    static values = {
        steps: Array,
        labels: Object,
    };

    connect() {
        this.index = 0;
        this.overlay = null;
        this.panel = null;
        this.highlighted = null;
        this.boundKeydown = this.onKeydown.bind(this);
    }

    disconnect() {
        this.stop();
    }

    start() {
        if (!this.stepsValue.length) return;

        this.index = 0;
        this.buildChrome();
        document.addEventListener('keydown', this.boundKeydown);
        this.render();
    }

    stop() {
        document.removeEventListener('keydown', this.boundKeydown);
        clearHighlight(this.highlighted);
        this.highlighted = null;
        this.overlay?.remove();
        this.panel?.remove();
        this.overlay = null;
        this.panel = null;
    }

    onKeydown(event) {
        if ('Escape' === event.key) this.stop();
        else if ('ArrowRight' === event.key) this.next();
        else if ('ArrowLeft' === event.key) this.previous();
    }

    buildChrome() {
        this.overlay = document.createElement('div');
        this.overlay.className = 'onboarding-tour-overlay';
        this.overlay.addEventListener('click', () => this.stop());

        this.panel = document.createElement('div');
        this.panel.className = 'onboarding-tour-panel';
        this.panel.setAttribute('role', 'dialog');
        this.panel.setAttribute('aria-modal', 'true');

        document.body.append(this.overlay, this.panel);
    }

    render() {
        clearHighlight(this.highlighted);

        const step = this.stepsValue[this.index];
        this.highlighted = highlight(`a[href="${CSS.escape(step.url)}"]`);

        const total = this.stepsValue.length;
        const labels = this.labelsValue;

        this.panel.replaceChildren();
        this.panel.append(
            buildElement('div', 'onboarding-tour-progress', `${this.index + 1} / ${total}`),
            buildElement('h3', null, step.label),
        );
        if (step.description) this.panel.append(buildElement('p', null, step.description));

        const actions = buildElement('div', 'onboarding-tour-actions');
        const closeButton = buildButton(labels.close, () => this.stop());
        const previousButton = buildButton(labels.previous, () => this.previous());
        previousButton.disabled = 0 === this.index;
        const nextButton = buildButton(this.index === total - 1 ? labels.finish : labels.next, () => this.next());

        const navGroup = buildElement('div');
        navGroup.append(previousButton, nextButton);
        actions.append(closeButton, navGroup);
        this.panel.append(actions);
    }

    next() {
        this.index++;
        if (this.index >= this.stepsValue.length) {
            this.stop();

            return;
        }
        this.render();
    }

    previous() {
        this.index = Math.max(0, this.index - 1);
        this.render();
    }
}
