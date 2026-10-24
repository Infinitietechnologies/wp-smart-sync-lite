'use strict';

const sidebarToggler = document.getElementById('docs-sidebar-toggler');
const sidebar = document.getElementById('docs-sidebar');
const sidebarLinks = document.querySelectorAll('#docs-sidebar .scrollto');

function setSidebarVisible(visible) {
    if (!sidebar || !sidebarToggler) return;
    sidebar.classList.toggle('sidebar-visible', visible);
    sidebar.classList.toggle('sidebar-hidden', !visible);
    sidebar.setAttribute('aria-hidden', String(!visible));
    sidebar.toggleAttribute('inert', !visible);
    sidebarToggler.setAttribute('aria-expanded', String(visible));
    if (!visible && sidebar.contains(document.activeElement)) sidebarToggler.focus();
}

let previousDesktop;
function responsiveSidebar() {
    const desktop = window.innerWidth >= 1200;
    if (desktop !== previousDesktop) {
        setSidebarVisible(desktop);
        previousDesktop = desktop;
    }
}

window.addEventListener('load', responsiveSidebar);
window.addEventListener('resize', responsiveSidebar);
responsiveSidebar();
if (sidebarToggler) sidebarToggler.addEventListener('click', () => {
    setSidebarVisible(sidebar.classList.contains('sidebar-hidden'));
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && sidebar && sidebar.classList.contains('sidebar-visible') && window.innerWidth < 1200) {
        setSidebarVisible(false);
        sidebarToggler.focus();
    }
});

sidebarLinks.forEach(sidebarLink => {
    sidebarLink.addEventListener('click', event => {
        const href = sidebarLink.getAttribute('href') || '';
        const target = href === '#' ? document.documentElement : document.getElementById(href.slice(1));
        if (!href.startsWith('#') || !target) return;
        event.preventDefault();
        const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        target.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth' });
        if (href !== '#' && window.location.hash !== href) window.history.pushState(null, '', href);
        if (window.innerWidth < 1200) setSidebarVisible(false);
        target.setAttribute('tabindex', '-1');
        target.focus({ preventScroll: true });
    });
});

if (typeof window.Gumshoe === 'function') {
    new window.Gumshoe('#docs-nav a[href^="#"]:not([href="#"])', { offset: 69 });
}
if (typeof window.SimpleLightbox === 'function' && document.querySelector('.simplelightbox-gallery a')) {
    new window.SimpleLightbox('.simplelightbox-gallery a', {});
}
