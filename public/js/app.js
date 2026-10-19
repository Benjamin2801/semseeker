document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.hidden = false;
    button.addEventListener('click', () => {
        const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = theme;
        try {
            localStorage.setItem('theme', theme);
        } catch {
        }
    });
});

const STAGE_DELAYS = [0, 2500, 6000];
const STAGE_DURATIONS = [2500, 3500, 12000];
let stageTimers = [];

document.addEventListener('submit', (event) => {
    const form = event.target.closest('.search-form');
    const button = form?.querySelector('button[type="submit"]');
    if (!button) {
        return;
    }

    button.dataset.label = button.textContent;
    button.disabled = true;
    button.classList.add('is-loading');
    document.body.classList.add('is-loading');

    const progress = Object.assign(document.createElement('div'), { className: 'progress-steps' });
    progress.setAttribute('aria-hidden', 'true');
    const segments = STAGE_DURATIONS.map((duration) => {
        const segment = document.createElement('span');
        segment.style.setProperty('--duration', `${duration}ms`);

        return progress.appendChild(segment);
    });
    document.body.append(progress);
    progress.getBoundingClientRect();

    stageTimers = JSON.parse(form.dataset.loadingStages).map((label, i) => setTimeout(() => {
        button.textContent = label;
        segments.forEach((segment, j) => {
            segment.classList.toggle('is-done', j < i);
            segment.classList.toggle('is-active', j === i);
        });
    }, STAGE_DELAYS[i] ?? 0));
});

window.addEventListener('pageshow', () => {
    stageTimers.forEach(clearTimeout);
    document.querySelector('.progress-steps')?.remove();
    document.body.classList.remove('is-loading');
    document.querySelectorAll('.search-form button.is-loading').forEach((button) => {
        button.textContent = button.dataset.label;
        button.disabled = false;
        button.classList.remove('is-loading');
    });
});

document.querySelectorAll('[data-to-form]').forEach((link) => {
    link.addEventListener('click', (event) => {
        event.preventDefault();
        window.scrollTo({ top: 0, behavior: 'smooth' });
        document.getElementById('q')?.focus({ preventScroll: true });
    });
});

const copyText = async (value) => {
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(value);

            return;
        } catch {
        }
    }
    const area = Object.assign(document.createElement('textarea'), { value });
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.append(area);
    area.select();
    document.execCommand('copy');
    area.remove();
};

const confirmOn = (button, message) => {
    const label = button.querySelector('[data-label]');
    const initial = label.textContent;
    label.textContent = message;
    setTimeout(() => { label.textContent = initial; }, 1800);
};

document.querySelectorAll('[data-copy-terms]').forEach((button) => {
    const list = button.closest('.synthesis-card').querySelector('.term-list');
    button.hidden = false;
    button.addEventListener('click', async () => {
        await copyText([...list.querySelectorAll('li > span')].map((term) => term.textContent.trim()).join('\n'));
        confirmOn(button, button.dataset.labelCopied);
    });
});

document.querySelectorAll('[data-copy-theme]').forEach((button) => {
    button.hidden = false;
    button.addEventListener('click', async (event) => {
        event.preventDefault();
        await copyText(button.dataset.copyTheme);
        confirmOn(button, button.dataset.labelCopied);
    });
});

document.addEventListener('click', async (event) => {
    const term = event.target.closest('.row-label, .term-list li > span, .term');
    if (!term) {
        return;
    }
    const text = (term.querySelector('.row-label') ?? term).childNodes;
    const value = [...text].filter((node) => node.nodeType === Node.TEXT_NODE).map((node) => node.textContent).join('').trim();
    if ('' === value) {
        return;
    }
    await copyText(value);
    term.classList.add('is-copied');
    setTimeout(() => term.classList.remove('is-copied'), 700);
});

document.querySelectorAll('[data-structure]').forEach((structure) => {
    const tree = structure.querySelector('[data-tree]');
    structure.querySelector('[data-hide-low]').addEventListener('change', (event) => {
        tree.classList.toggle('hide-low', event.target.checked);
    });

    const keywords = JSON.parse(structure.querySelector('[data-keywords]').textContent);

    const copyKeywords = structure.querySelector('[data-copy-keywords]');
    copyKeywords?.addEventListener('click', async () => {
        await copyText(structure.querySelector('[data-keywords-text]').content.textContent.trim());
        confirmOn(copyKeywords, structure.dataset.labelKeywordsCopied.replace('%count%', keywords.length));
    });

    structure.querySelector('[data-export]').addEventListener('click', () => {
        const csv = JSON.parse(structure.querySelector('[data-csv]').textContent);
        const cell = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
        const lines = [csv.header, ...csv.rows].map((row) => row.map(cell).join(';'));

        const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const link = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: structure.dataset.filename });
        link.click();
        URL.revokeObjectURL(link.href);
    });

    const intentChips = [...structure.querySelectorAll('[data-intent-filter]')];
    intentChips.forEach((chip) => {
        chip.addEventListener('click', () => {
            chip.setAttribute('aria-pressed', String(chip.getAttribute('aria-pressed') !== 'true'));
            const selected = intentChips.filter((c) => c.getAttribute('aria-pressed') === 'true').map((c) => c.dataset.intentFilter);
            tree.querySelectorAll('.tree-group').forEach((group) => {
                const pages = [...group.querySelectorAll('.tree-page')];
                pages.forEach((page) => { page.hidden = selected.length > 0 && !selected.includes(page.dataset.intent); });
                group.hidden = selected.length > 0 && pages.every((page) => page.hidden);
            });
        });
    });

    const categoryChips = [...structure.querySelectorAll('[data-category-filter]')];
    categoryChips.forEach((chip) => {
        chip.addEventListener('click', () => {
            chip.setAttribute('aria-pressed', String(chip.getAttribute('aria-pressed') !== 'true'));
            const selected = categoryChips.filter((c) => c.getAttribute('aria-pressed') === 'true').map((c) => c.dataset.categoryFilter);
            const kept = (term) => selected.length === 0 || selected.includes(term.dataset.category);
            tree.querySelectorAll('[data-category]').forEach((term) => {
                const title = term.classList.contains('section-title');
                term.classList.toggle('is-filtered-out', !title && !kept(term));
                term.classList.toggle('is-filtered-dim', title && !kept(term));
            });
            tree.querySelectorAll('.page-sections > li, .tree-page, .tree-group').forEach((block) => {
                block.classList.toggle('is-filtered-out', selected.length > 0 && ![...block.querySelectorAll('[data-category]')].some(kept));
            });
        });
    });

    const printButton = structure.querySelector('[data-print]');
    printButton.hidden = false;
    printButton.addEventListener('click', () => {
        structure.querySelectorAll('details').forEach((details) => { details.open = true; });
        window.print();
    });

    const copyPlan = structure.querySelector('[data-copy-plan]');
    copyPlan?.addEventListener('click', async () => {
        await copyText(structure.querySelector('[data-plan-text]').content.textContent.trim());
        confirmOn(copyPlan, structure.dataset.labelCopied);
    });
});

document.querySelectorAll('[data-autocomplete]').forEach((input) => {
    const list = document.getElementById(input.getAttribute('aria-controls'));
    const lang = input.form?.querySelector('[name="lang"]');
    let timer;
    let controller;
    let active = -1;

    const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };
    const highlight = (index) => {
        const items = [...list.children];
        active = (index + items.length) % items.length;
        items.forEach((item, i) => item.setAttribute('aria-selected', String(i === active)));
        input.setAttribute('aria-activedescendant', items[active].id);
    };
    const choose = (text) => {
        input.value = text;
        close();
        input.form?.requestSubmit();
    };
    const show = (suggestions, query) => {
        list.replaceChildren(...suggestions.map((text, i) => {
            const item = Object.assign(document.createElement('li'), {id: `q-suggestion-${i}`, role: 'option'});
            item.setAttribute('aria-selected', 'false');
            const start = text.toLowerCase().startsWith(query.toLowerCase()) ? query.length : 0;
            item.append(Object.assign(document.createElement('mark'), {textContent: text.slice(0, start)}), text.slice(start));
            item.addEventListener('mousedown', (event) => {
                event.preventDefault();
                choose(text);
            });
            return item;
        }));
        list.hidden = suggestions.length === 0;
        input.setAttribute('aria-expanded', String(!list.hidden));
        active = -1;
    };

    input.addEventListener('input', () => {
        clearTimeout(timer);
        const query = input.value.trim();
        if (query.length < 2) {
            close();
            return;
        }
        timer = setTimeout(async () => {
            controller?.abort();
            controller = new AbortController();
            const url = `${input.dataset.autocomplete}?${new URLSearchParams({q: query, lang: lang?.value ?? ''})}`;
            try {
                show(await (await fetch(url, {signal: controller.signal})).json(), query);
            } catch {
            }
        }, 200);
    });
    input.addEventListener('keydown', (event) => {
        if (list.hidden) {
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            highlight(active + (event.key === 'ArrowDown' ? 1 : -1));
        } else if (event.key === 'Enter' && active >= 0) {
            event.preventDefault();
            choose(list.children[active].textContent);
        } else if (event.key === 'Escape') {
            close();
        }
    });
    input.addEventListener('blur', close);
});