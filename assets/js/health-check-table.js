/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import { Controller } from "@hotwired/stimulus";

// HealthCheckResult::STATUS_*, from the mildest to the worst - the order the verdict of a whole group is read
// off its rows, and the list of modifier classes to keep in sync on the row carrying it (see sass/management.scss)
const STATUSES = ['skipped', 'ok', 'warning', 'error'];

// Client-side sort (click a <th data-action="click->health-check-table#sort">) and filter (free-text + status +
// kind) for the Health check table - hand-rolled rather than a DataTables/jQuery dependency, this ecosystem
// has none and the actual need (sort N rows, filter by 3 criteria) doesn't warrant one. Rows never leave the
// DOM (row.hidden, not removal) so nothing here can lose a row on a filter/sort round-trip
export default class extends Controller {
    static targets = ['row', 'filter', 'status', 'kind', 'header', 'label'];
    // Off for the "Site" section's table (site-wide checks, eg. security-headers/ssl-certificate, don't belong
    // to any one page) - see _table.html.twig's group param and index.html.twig, which passes group: false there
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

    // Blanks a row's page name/edit-link cell when it repeats the previous VISIBLE row's url, and highlights the
    // row that keeps it (health-check-row-first, see sass/management.scss) so groups of rows for the same page
    // stay visually separated without it - re-run after every sort/filter (rather than baked in server-side)
    // since a static rowspan couldn't survive rows being reordered/hidden by the above. That highlight is
    // painted with the group's own verdict, so a page reads as ok/warning/error at a glance without adding up
    // its rows' own status pills
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

    // One page's verdict: the worst status among the rows currently listed for its url. Read over every visible
    // row sharing that url rather than the ones sitting next to it - a sort by status scatters a page's own rows
    // across the table, and a filter can leave only some of them
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
