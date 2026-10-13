const STORAGE_KEY = 'sidebar-collapsed';

function isDesktopSidebar() {
    return window.matchMedia('(min-width: 992px)').matches;
}

function updateSidebarCollapseUi() {
    const collapsed = document.body.classList.contains('sidebar-collapsed');
    document.querySelectorAll('[data-sidebar-collapse-icon]').forEach((icon) => {
        icon.classList.toggle('bi-arrow-bar-left', !collapsed);
        icon.classList.toggle('bi-arrow-bar-right', collapsed);
    });
    document.querySelectorAll('.js-sidebar-collapse-btn').forEach((btn) => {
        const collapseLabel = btn.getAttribute('data-title-collapse') || 'Collapse menu';
        const expandLabel = btn.getAttribute('data-title-expand') || 'Expand menu';
        const label = collapsed ? expandLabel : collapseLabel;
        btn.setAttribute('title', label);
        btn.setAttribute('aria-label', label);
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    });
}

function applyStoredSidebarCollapse() {
    if (isDesktopSidebar() && localStorage.getItem(STORAGE_KEY) === '1') {
        document.body.classList.add('sidebar-collapsed');
    } else if (!isDesktopSidebar()) {
        document.body.classList.remove('sidebar-collapsed');
    }
    updateSidebarCollapseUi();
}

export function toggleSidebarCollapse() {
    if (!isDesktopSidebar()) {
        return;
    }
    document.body.classList.toggle('sidebar-collapsed');
    localStorage.setItem(STORAGE_KEY, document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
    updateSidebarCollapseUi();
}

export function toggleSidebar() {
    document.getElementById('sidebar')?.classList.toggle('show');
    document.getElementById('sidebarBackdrop')?.classList.toggle('show');
}

export function toggleSubmenu(id, parentEl) {
    const sub = document.getElementById(id);
    if (!sub || !parentEl) {
        return;
    }

    if (document.body.classList.contains('sidebar-collapsed') && isDesktopSidebar()) {
        document.body.classList.remove('sidebar-collapsed');
        localStorage.setItem(STORAGE_KEY, '0');
        updateSidebarCollapseUi();
        sub.style.display = 'block';
        parentEl.classList.add('open');
        return;
    }

    const isOpen = sub.style.display !== 'none';
    sub.style.display = isOpen ? 'none' : 'block';
    parentEl.classList.toggle('open', !isOpen);
}

function bindSidebarCollapseButtons() {
    document.querySelectorAll('.js-sidebar-collapse-btn').forEach((btn) => {
        if (btn.dataset.sidebarBound === '1') {
            return;
        }
        btn.dataset.sidebarBound = '1';
        btn.addEventListener('click', (event) => {
            event.preventDefault();
            toggleSidebarCollapse();
        });
    });
}

function initSidebar() {
    applyStoredSidebarCollapse();
    bindSidebarCollapseButtons();
}

window.toggleSidebar = toggleSidebar;
window.toggleSubmenu = toggleSubmenu;
window.toggleSidebarCollapse = toggleSidebarCollapse;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSidebar);
} else {
    initSidebar();
}

window.addEventListener('resize', applyStoredSidebarCollapse);
