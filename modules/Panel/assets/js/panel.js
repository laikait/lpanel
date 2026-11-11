// LPanel shell behaviour. Loaded as a file because the CSP forbids inline
// scripts. Every page works without it; it only adds conveniences.

const root = document.documentElement;

// Theme: the server renders data-theme from the lp_theme cookie, so the
// chosen theme is there on the first paint. "auto" follows the system.
function currentTheme() {
    const set = root.getAttribute('data-theme');
    if (set === 'light' || set === 'dark') return set;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const next = currentTheme() === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        document.cookie = `lp_theme=${next}; path=/; max-age=31536000; SameSite=Lax`;
    });
});

// Sidebar on narrow screens.
const shell = document.querySelector('.shell');
document.querySelectorAll('[data-nav-toggle]').forEach((el) => {
    el.addEventListener('click', () => shell?.classList.toggle('nav-open'));
});

// Dropdowns: one open at a time, closed by a click elsewhere or Escape.
document.querySelectorAll('[data-dropdown]').forEach((button) => {
    const menu = document.getElementById(button.getAttribute('data-dropdown'));
    if (!menu) return;
    button.addEventListener('click', (event) => {
        event.stopPropagation();
        const open = menu.classList.toggle('is-open');
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
});
document.addEventListener('click', () => {
    document.querySelectorAll('.dropdown.is-open').forEach((menu) => menu.classList.remove('is-open'));
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        document.querySelectorAll('.dropdown.is-open').forEach((menu) => menu.classList.remove('is-open'));
        shell?.classList.remove('nav-open');
    }
});

// Forms that change something serious ask first.
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.getAttribute('data-confirm'))) event.preventDefault();
    });
});

// Copy buttons.
document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(button.getAttribute('data-copy'));
            const label = button.textContent;
            button.textContent = button.getAttribute('data-copied') || 'Copied';
            setTimeout(() => { button.textContent = label; }, 1500);
        } catch (_) { /* clipboard refused: nothing to do */ }
    });
});

// New account: offer only the packages the chosen owner may use. Server
// packages (data-owner="") always; a reseller's only for that reseller.
document.querySelectorAll('select[data-owner-select]').forEach((owner) => {
    const packages = document.getElementById(owner.getAttribute('data-owner-select'));
    if (!packages) return;
    const apply = () => {
        let firstVisible = null;
        packages.querySelectorAll('option').forEach((option) => {
            const pkgOwner = option.getAttribute('data-owner') || '';
            const usable = pkgOwner === '' || pkgOwner === owner.value;
            option.hidden = !usable;
            option.disabled = !usable;
            if (usable && firstVisible === null) firstVisible = option;
        });
        if (packages.selectedOptions[0]?.disabled && firstVisible) firstVisible.selected = true;
    };
    owner.addEventListener('change', apply);
    apply();
});

// New account: suggest a username from the domain until one is typed.
document.querySelectorAll('[data-suggest-username]').forEach((domain) => {
    const username = document.getElementById(domain.getAttribute('data-suggest-username'));
    if (!username) return;
    let typed = username.value !== '';
    username.addEventListener('input', () => { typed = username.value !== ''; });
    domain.addEventListener('input', () => {
        if (typed) return;
        const first = domain.value.toLowerCase().replace(/^www\./, '').split('.')[0] || '';
        let name = first.replace(/[^a-z0-9]/g, '').replace(/^[0-9]+/, '').slice(0, 8);
        username.value = name.length >= 2 ? name : '';
    });
});

// Fields that apply to some choices only. A form names the field that
// decides (data-show-from="kind"); an element inside it lists the values it
// applies to (data-show-for="addon sub"). Hidden fields are disabled, so
// they are not sent either.
document.querySelectorAll('form[data-show-from]').forEach((form) => {
    const name = form.getAttribute('data-show-from');
    const apply = () => {
        const chosen = form.querySelector(`[name="${name}"]:checked`) || form.querySelector(`select[name="${name}"]`);
        const value = chosen ? chosen.value : '';
        form.querySelectorAll('[data-show-for]').forEach((el) => {
            const on = el.getAttribute('data-show-for').split(' ').includes(value);
            el.hidden = !on;
            el.querySelectorAll('input, select, textarea').forEach((input) => { input.disabled = !on; });
        });
    };
    form.addEventListener('change', apply);
    apply();
});

// A page showing work in progress (data-reload="seconds" on any element)
// looks again by itself, unless the person is typing in a form.
const reload = document.querySelector('[data-reload]');
if (reload) {
    const seconds = Math.max(3, parseInt(reload.getAttribute('data-reload'), 10) || 5);
    setTimeout(function again() {
        const busy = document.activeElement && document.activeElement.closest('form') && document.activeElement.matches('input, select, textarea');
        if (busy) { setTimeout(again, seconds * 1000); return; }
        window.location.reload();
    }, seconds * 1000);
}
