/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import { Controller } from "@hotwired/stimulus";

// STATUS_* from mildest to worst: the order a group's verdict is read off its rows
const STATUSES = ['skipped', 'ok', 'warning', 'error'];

// Hand-rolled sort and filter, no DataTables dependency; rows are hidden, never removed
export default class extends Controller {
    static targets = ['row', 'filter', 'status', 'kind', 'header', 'label'];
    // Off for the "Site" section, whose site-wide checks belong to no one page
    static values = { group: { type: Boolean, default: true } };

    connect() {
        this.sortState = { index: null, ascending: true };
        this.updateGrouping();
    }

    filterRows() {
        const text = this.filterTarget.value.trim().toLowerCase();
        const status = this.statusTarget.value;
        const kind = this.kindTarget.value;

        for (const row of this.rowTargets) {
            const matchesText = !text || row.dataset.searchText.includes(text);
            const matchesStatus = !status || row.dataset.status === status;
            const matchesKind = !kind || row.dataset.kind === kind;
            row.hidden = !(matchesText && matchesStatus && matchesKind);
        }

        this.updateGrouping();
    }

    sort(event) {
        const header = event.currentTarget;
        const index = this.headerTargets.indexOf(header);
        const ascending = this.sortState.index === index ? !this.sortState.ascending : true;
        this.sortState = { index, ascending };

        const rows = [...this.rowTargets];
        rows.sort((a, b) => {
            const cellA = a.children[index].textContent.trim();
            const cellB = b.children[index].textContent.trim();

            return ascending ? cellA.localeCompare(cellB) : cellB.localeCompare(cellA);
        });

        for (const row of rows) {
            row.parentElement.append(row);
        }

        for (const th of this.headerTargets) th.removeAttribute('aria-sort');
        header.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');

        this.updateGrouping();
    }

    // Blanks a repeated page cell and tints the row keeping it, re-run after every sort or filter
    updateGrouping() {
        if (!this.groupValue) {
            return;
        }

        const verdicts = this.verdictByUrl();
        let previousUrl = null;

        this.rowTargets.forEach((row, index) => {
            if (!row.hidden) {
                const isFirstOfGroup = row.dataset.url !== previousUrl;
                this.labelTargets[index].hidden = !isFirstOfGroup;
                row.classList.toggle('health-check-row-first', isFirstOfGroup);
                for (const status of STATUSES) {
                    row.classList.toggle(
                        `health-check-row-first--${status}`,
                        isFirstOfGroup && verdicts.get(row.dataset.url) === status,
                    );
                }
                previousUrl = row.dataset.url;
            }
        });
    }

    // The worst status among every visible row sharing that url, a sort scattering a page's rows
    verdictByUrl() {
        const verdicts = new Map();

        for (const row of this.rowTargets) {
            if (row.hidden) {
                continue;
            }

            const current = verdicts.get(row.dataset.url);
            if (undefined === current || STATUSES.indexOf(row.dataset.status) > STATUSES.indexOf(current)) {
                verdicts.set(row.dataset.url, row.dataset.status);
            }
        }

        return verdicts;
    }
}
