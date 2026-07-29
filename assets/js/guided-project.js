/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import { Controller } from "@hotwired/stimulus";
import { buildButton, buildElement, clearHighlight, highlight } from "./guided-ui.js";

// Walks one guided project step by step across several screens, picking back up from what it stored
// Nothing is detected and nothing read from the site: a project is a replayable exercise
export default class extends Controller {
    static values = {
        key: String,
        url: String,
        labels: Object,
    };

    connect() {
        this.project = null;
        this.index = 0;
        this.panel = null;
        this.highlighted = null;
        this.wireList();
        this.resume();
    }

    disconnect() {
        this.closePanel();
    }

    // The dashboard list is part of the page while this element is appended to <body>, so it is wired by hand
    wireList() {
        document.querySelectorAll('[data-guided-project-toggle]').forEach((button) => {
            button.addEventListener('click', () => this.toggleList());
        });
        document.querySelectorAll('[data-guided-project-slug]').forEach((button) => {
            button.addEventListener('click', () => this.open(button.dataset.guidedProjectSlug));
        });

        this.refreshList();
    }

    toggleList() {
        const list = document.querySelector('[data-guided-project-list]');
        if (list) list.hidden = !list.hidden;
    }

    // "Start"/"Resume"/"Replay" and the badge come from this browser's storage, the server rendering neutral
    refreshList() {
        const state = this.readState();
        const done = state.done ?? [];
        const labels = this.labelsValue;

        document.querySelectorAll('[data-guided-project-slug]').forEach((button) => {
            const slug = button.dataset.guidedProjectSlug;
            if (state.active?.slug === slug) button.textContent = labels.resume;
            else if (done.includes(slug)) button.textContent = labels.replay;
            else button.textContent = labels.start;
        });

        document.querySelectorAll('[data-guided-project-badge]').forEach((badge) => {
            badge.hidden = !done.includes(badge.dataset.guidedProjectBadge);
        });
    }

    // Tells the dashboard button apart: it resumes the active project, and only starts the others over
    async open(slug) {
        if (this.readState().active?.slug === slug) {
            await this.resume(true);

            return;
        }

        await this.start(slug);
    }

    // Runs on every admin page, so a left-open project comes back; a slug no provider declares is dropped
    // A paused project only comes back when asked for, hence the flag being honoured unless forced
    async resume(force = false) {
        const active = this.readState().active;
        if (!active || (active.paused && !force)) return;

        const project = await this.fetchProject(active.slug);
        if (!project) {
            this.clearActive();
            this.refreshList();

            return;
        }

        this.project = project;
        this.index = Math.min(active.step, project.steps.length - 1);

        // Rewrites the stored position without the flag, the project running again
        if (active.paused) {
            this.saveActive();
            this.refreshList();
        }

        this.openPanel();
    }

    async start(slug) {
        const project = await this.fetchProject(slug);
        if (!project) return;

        this.project = project;
        this.index = 0;
        this.saveActive();
        this.refreshList();
        this.openPanel();
    }

    async fetchProject(slug) {
        const response = await fetch(this.urlValue.replace('__SLUG__', encodeURIComponent(slug)), {
            headers: { 'Accept': 'application/json' },
        });

        return response.ok ? response.json() : null;
    }

    // A step carrying an url stores the next one before leaving, which is the whole cross-page mechanism
    next() {
        const step = this.project.steps[this.index];
        this.index++;

        if (this.index >= this.project.steps.length) {
            this.finish();

            return;
        }

        this.saveActive();
        if (step.url) {
            window.location.href = step.url;

            return;
        }

        this.render();
    }

    previous() {
        this.index = Math.max(0, this.index - 1);
        this.saveActive();
        this.render();
    }

    // Ends the project; whatever the user created stays on the site, deleting it being their call
    finish() {
        const state = this.readState();
        const done = state.done ?? [];
        if (!done.includes(this.project.slug)) done.push(this.project.slug);

        this.writeState({ done: done });
        this.closePanel();
        this.refreshList();
    }

    // Keeps the stored position and drops the panel, the dashboard button then reading "Resume"
    // The flag is what keeps it closed on the next page, resume() running on every one of them
    pause() {
        const state = this.readState();
        if (state.active) {
            state.active.paused = true;
            this.writeState(state);
        }

        this.closePanel();
        this.refreshList();
    }

    quit() {
        this.clearActive();
        this.closePanel();
        this.refreshList();
    }

    openPanel() {
        if (!this.panel) {
            this.panel = buildElement('div', 'guided-project-panel');
            // "complementary" rather than "dialog": the panel sits beside the work instead of taking it over, and must not trap the focus the user needs in the form underneath
            this.panel.setAttribute('role', 'complementary');
            document.body.append(this.panel);
        }

        this.render();
    }

    closePanel() {
        clearHighlight(this.highlighted);
        this.highlighted = null;
        this.panel?.remove();
        this.panel = null;
    }

    render() {
        clearHighlight(this.highlighted);

        const step = this.project.steps[this.index];
        const total = this.project.steps.length;
        const labels = this.labelsValue;

        this.panel.replaceChildren();
        this.panel.append(
            buildElement('div', 'guided-project-progress', `${this.project.label} — ${this.index + 1} / ${total}`),
            buildElement('h3', null, step.label),
        );
        if (step.description) this.panel.append(buildElement('p', null, step.description));

        this.highlighted = step.highlight ? highlight(step.highlight) : null;

        this.panel.append(this.buildActions(step, total, labels));
    }

    // The two ways out differ only in whether the stored position survives
    buildActions(step, total, labels) {
        const previousButton = buildButton(labels.previous, () => this.previous());
        previousButton.disabled = 0 === this.index;

        // The last step ends the project whatever it declares, so its own url is left out of the label: next() never reads it, nothing following it to go to
        const nextLabel = this.index === total - 1 ? labels.finish : (step.url ? labels.goto : labels.next);

        const navGroup = buildElement('div');
        navGroup.append(previousButton, buildButton(nextLabel, () => this.next()));

        const exitGroup = buildElement('div');
        exitGroup.append(buildButton(labels.quit, () => this.quit()), buildButton(labels.pause, () => this.pause()));

        const actions = buildElement('div', 'guided-project-actions');
        actions.append(exitGroup, navGroup);

        return actions;
    }

    // Browser storage on purpose, not a table: refusing to store costs the resume and the badge, not the parcours
    readState() {
        try {
            return JSON.parse(localStorage.getItem(this.storageKey())) ?? {};
        } catch (error) {
            return {};
        }
    }

    writeState(state) {
        try {
            localStorage.setItem(this.storageKey(), JSON.stringify(state));
        } catch (error) {
            // Nothing to recover: the panel keeps running on its in-memory position for this page
        }
    }

    storageKey() {
        return `c975l.guided-project.${this.keyValue}`;
    }

    saveActive() {
        const state = this.readState();
        state.active = { slug: this.project.slug, step: this.index };
        this.writeState(state);
    }

    clearActive() {
        const state = this.readState();
        delete state.active;
        this.writeState(state);
    }
}
