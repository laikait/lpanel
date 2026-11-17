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

// Forms that change something serious ask first; so does a button of a form
// that does several things, when its own is serious.
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.getAttribute('data-confirm'))) event.preventDefault();
    });
});
document.querySelectorAll('button[data-confirm]').forEach((button) => {
    button.addEventListener('click', (event) => {
        if (!window.confirm(button.getAttribute('data-confirm'))) event.preventDefault();
    });
});

// A "select all" box (data-check-all="ids[]") ticks every box of that name
// in its form.
document.querySelectorAll('input[data-check-all]').forEach((all) => {
    const name = all.getAttribute('data-check-all');
    const boxes = () => (all.form ? all.form.querySelectorAll('input[type="checkbox"]') : []);
    all.addEventListener('change', () => {
        boxes().forEach((box) => { if (box.name === name) box.checked = all.checked; });
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

// "Generate" fills a password field with a strong random password and shows it.
document.querySelectorAll('[data-generate]').forEach((button) => {
    const input = document.getElementById(button.getAttribute('data-generate'));
    if (!input) return;
    button.addEventListener('click', () => {
        const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_.!';
        const bytes = new Uint8Array(20);
        crypto.getRandomValues(bytes);
        input.value = Array.from(bytes, (b) => alphabet[b % alphabet.length]).join('');
        input.type = 'text';
        input.dispatchEvent(new Event('input'));
    });
});

// "All privileges" ticks or clears every privilege beside it.
document.querySelectorAll('input[data-all]').forEach((all) => {
    const boxes = document.querySelectorAll('input[name="' + all.getAttribute('data-all') + '"]');
    const sync = () => { all.checked = Array.from(boxes).every((b) => b.checked); };
    all.addEventListener('change', () => boxes.forEach((b) => { b.checked = all.checked; }));
    boxes.forEach((b) => b.addEventListener('change', sync));
});

// Small panels that open from a button (<details class="fm-pop">): one open
// at a time, closed by a click elsewhere or Escape, focus on their first field.
document.querySelectorAll('details.fm-pop').forEach((pop) => {
    pop.addEventListener('toggle', () => {
        if (!pop.open) return;
        if (pop.querySelector(':scope > summary[aria-disabled="true"]')) { pop.open = false; return; }
        document.querySelectorAll('details.fm-pop[open]').forEach((other) => { if (other !== pop) other.open = false; });
        const field = pop.querySelector('input:not([type="hidden"]):not([type="checkbox"]), select, textarea');
        if (field) setTimeout(() => field.focus(), 0);
    });
});
document.addEventListener('click', (event) => {
    document.querySelectorAll('details.fm-pop[open]').forEach((pop) => { if (!pop.contains(event.target)) pop.open = false; });
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') document.querySelectorAll('details.fm-pop[open]').forEach((pop) => { pop.open = false; });
});

// The file manager's list: what is selected, and which buttons apply to it.
document.querySelectorAll('form[data-fm]').forEach((form) => {
    const count = form.querySelector('[data-fm-count]');
    const boxes = () => Array.from(form.querySelectorAll('input[type="checkbox"][name="paths[]"]'));
    const name = form.querySelector('[data-fm-name]');
    const mode = form.querySelector('[data-fm-mode]');
    const update = () => {
        const chosen = boxes().filter((b) => b.checked);
        if (count) {
            count.textContent = chosen.length ? count.getAttribute('data-some').replace('#', chosen.length) : count.getAttribute('data-none');
            count.classList.toggle('is-active', chosen.length > 0);
        }
        form.querySelectorAll('[data-fm-needs]').forEach((el) => {
            const need = el.getAttribute('data-fm-needs');
            const ok = need === 'one' ? chosen.length === 1 : chosen.length > 0;
            const target = el.tagName === 'SUMMARY' ? el.parentElement : el;
            if (el.tagName === 'BUTTON') el.disabled = !ok;
            else el.setAttribute('aria-disabled', ok ? 'false' : 'true');
            if (!ok && target.tagName === 'DETAILS') target.open = false;
        });
        // Rename starts from the current name; permissions from the current mode.
        if (name && chosen.length === 1 && name.dataset.for !== chosen[0].value) {
            name.value = chosen[0].getAttribute('data-name') || '';
            name.dataset.for = chosen[0].value;
        }
        if (mode && chosen.length >= 1 && !mode.dataset.touched) {
            mode.value = (chosen[0].getAttribute('data-mode') || '').replace(/^0/, '');
        }
    };
    if (mode) mode.addEventListener('input', () => { mode.dataset.touched = '1'; });
    form.addEventListener('change', update);
    update();
});

// The file editor: Tab indents, Ctrl+S (or Cmd+S) saves, and leaving with
// unsaved changes asks first.
document.querySelectorAll('textarea[data-editor]').forEach((editor) => {
    const form = editor.form;
    const dirtyNote = form ? form.querySelector('[data-editor-dirty]') : null;
    const original = editor.value;
    let dirty = false;
    const indent = /^\t/m.test(original) ? '\t' : '    ';
    editor.addEventListener('keydown', (event) => {
        if (event.key === 'Tab' && !event.ctrlKey && !event.metaKey && !event.altKey && !editor.readOnly) {
            event.preventDefault();
            const start = editor.selectionStart;
            const end = editor.selectionEnd;
            if (event.shiftKey) {
                const lineStart = editor.value.lastIndexOf('\n', start - 1) + 1;
                const line = editor.value.slice(lineStart);
                const remove = line.startsWith('\t') ? 1 : (line.match(/^ {1,4}/) || [''])[0].length;
                if (remove) {
                    editor.setRangeText('', lineStart, lineStart + remove, 'preserve');
                    editor.setSelectionRange(Math.max(lineStart, start - remove), Math.max(lineStart, end - remove));
                }
            } else {
                editor.setRangeText(indent, start, end, 'end');
            }
            editor.dispatchEvent(new Event('input'));
        }
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            if (form && !editor.readOnly) { dirty = false; form.requestSubmit(); }
        }
    });
    editor.addEventListener('input', () => {
        dirty = editor.value !== original;
        if (dirtyNote) dirtyNote.hidden = !dirty;
    });
    if (form) form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', (event) => {
        if (dirty) { event.preventDefault(); event.returnValue = editor.getAttribute('data-unsaved') || ''; }
    });
});

// Files dropped on the file manager are uploaded into the folder shown.
document.querySelectorAll('[data-drop-upload]').forEach((zone) => {
    const form = document.getElementById(zone.getAttribute('data-drop-upload'));
    const input = form ? form.querySelector('input[type="file"]') : null;
    if (!form || !input) return;
    let depth = 0;
    const has = (event) => Array.from(event.dataTransfer?.types || []).includes('Files');
    zone.addEventListener('dragenter', (event) => { if (!has(event)) return; depth++; zone.classList.add('is-dropping'); });
    zone.addEventListener('dragleave', () => { depth = Math.max(0, depth - 1); if (!depth) zone.classList.remove('is-dropping'); });
    zone.addEventListener('dragover', (event) => { if (has(event)) event.preventDefault(); });
    zone.addEventListener('drop', (event) => {
        if (!has(event)) return;
        event.preventDefault();
        depth = 0;
        zone.classList.remove('is-dropping');
        if (!event.dataTransfer.files.length) return;
        input.files = event.dataTransfer.files;
        form.requestSubmit();
    });
});
