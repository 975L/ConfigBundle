/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import { Controller } from "@hotwired/stimulus";

// Walks the dashboard's onboardingSteps (see OnboardingStepBuilder), highlighting each sidebar item in
// turn behind a dimmed overlay, with a fixed panel naming it (and explaining it, for a step whose
// "description" - see MenuProviderInterface - isn't empty). Steps are matched against the sidebar's own
// rendered a[href] rather than an invented id - no EasyAdmin Sidebar/Item template override needed. Re-launchable any time via the "Guided tour"
// button (data-action="click->onboarding-tour#start"), see management/index.html.twig. The panel/overlay
// are built and wired by hand (addEventListener, not data-action) since they're appended to <body>, outside
// this controller's own element - Stimulus can't resolve data-action on elements it never scoped
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
        this.clearHighlight();
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
        this.clearHighlight();

        const step = this.stepsValue[this.index];
        const target = document.querySelector(`a[href="${CSS.escape(step.url)}"]`);
        if (target) {
            this.expandAncestorSubmenu(target);
            target.classList.add('onboarding-tour-highlight');
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            this.highlighted = target;
        }

        const total = this.stepsValue.length;
        const labels = this.labelsValue;

        this.panel.replaceChildren();
        this.panel.append(
            this.buildElement('div', 'onboarding-tour-progress', `${this.index + 1} / ${total}`),
            this.buildElement('h3', null, step.label),
        );
        if (step.description) this.panel.append(this.buildElement('p', null, step.description));

        const actions = this.buildElement('div', 'onboarding-tour-actions');
        const closeButton = this.buildButton(labels.close, () => this.stop());
        const previousButton = this.buildButton(labels.previous, () => this.previous());
        previousButton.disabled = 0 === this.index;
        const nextButton = this.buildButton(this.index === total - 1 ? labels.finish : labels.next, () => this.next());

        const navGroup = this.buildElement('div');
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

    // A menu item tagged 'advanced' (see MenuBuilder) sits inside EasyAdmin's collapsed "Avancé" submenu -
    // invisible until opened. Clicks the submenu's own toggle button (EasyAdmin's app.js binds a plain
    // click listener to it, see #createMainMenu()) rather than toggling the 'is-expanded' class by hand,
    // so its single-open-at-a-time accordion behavior and aria-expanded bookkeeping stay in sync
    expandAncestorSubmenu(target) {
        const submenuItem = target.closest('.ea-sidebar-item.has-submenu');
        if (!submenuItem || submenuItem.classList.contains('is-expanded')) return;

        submenuItem.querySelector(':scope > .ea-sidebar-item-link')?.click();
    }

    clearHighlight() {
        this.highlighted?.classList.remove('onboarding-tour-highlight');
        this.highlighted = null;
    }

    buildElement(tag, className, text) {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (undefined !== text) element.textContent = text;

        return element;
    }

    buildButton(label, onClick) {
        const button = this.buildElement('button', null, label);
        button.type = 'button';
        button.addEventListener('click', onClick);

        return button;
    }
}
