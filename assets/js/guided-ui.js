/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

// The DOM helpers and the highlighting the tour and the projects share; their chrome deliberately doesn't

export function buildElement(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (undefined !== text) element.textContent = text;

    return element;
}

export function buildButton(label, onClick) {
    const button = buildElement('button', null, label);
    button.type = 'button';
    button.addEventListener('click', onClick);

    return button;
}

// Clicks EasyAdmin's own submenu toggle rather than setting the class, so its accordion and aria stay in sync
export function expandAncestorSubmenu(target) {
    const submenuItem = target.closest('.ea-sidebar-item.has-submenu');
    if (!submenuItem || submenuItem.classList.contains('is-expanded')) return;

    submenuItem.querySelector(':scope > .ea-sidebar-item-link')?.click();
}

// Outlines the element and returns it; null when there is nothing to point at, costing only the highlight
export function highlight(selector) {
    let target = null;
    try {
        target = document.querySelector(selector);
    } catch {
        return null;
    }
    if (!target) return null;

    expandAncestorSubmenu(target);
    target.classList.add('guided-highlight');
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });

    return target;
}

export function clearHighlight(target) {
    target?.classList.remove('guided-highlight');
}
