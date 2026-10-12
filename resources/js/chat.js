const root = document.querySelector('[data-chat]');

if (root) {
    startChat(root);
}

function startChat(root) {
    const log = root.querySelector('[data-chat-log]');
    const form = root.querySelector('[data-chat-form]');
    const input = form.querySelector('input');
    const sendButton = form.querySelector('button');
    const status = root.querySelector('[data-chat-status]');
    const suggestions = root.querySelector('[data-chat-suggestions]');
    const sheet = root.querySelector('[data-chat-sheet]');
    const endpoint = root.dataset.endpoint;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    let busy = false;

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        ask(input.value.trim());
    });

    suggestions?.addEventListener('click', (event) => {
        const chip = event.target.closest('[data-suggestion]');

        if (chip) {
            ask(chip.dataset.suggestion);
        }
    });

    sheet.addEventListener('click', (event) => {
        if (event.target === sheet || event.target.closest('[data-sheet-close]')) {
            closeSheet();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !sheet.hidden) {
            closeSheet();
        }
    });

    document.querySelectorAll('a[href="#chat"]').forEach((link) => {
        link.addEventListener('click', () => setTimeout(() => input.focus({ preventScroll: true }), 400));
    });

    async function ask(text, button = null) {
        if (busy || (text === '' && button === null)) {
            return;
        }

        busy = true;
        sendButton.disabled = true;
        input.value = '';
        suggestions?.remove();

        appendBubble(outgoing(text));
        const typing = appendBubble(typingBubble());
        status.textContent = 'escribiendo…';

        try {
            const bubbles = await post({ mensaje: text, boton: button });
            typing.remove();
            bubbles.forEach((bubble) => appendBubble(incoming(bubble)));
        } catch (error) {
            typing.remove();
            appendBubble(incoming({ tipo: 'texto', texto: error.message }));
        } finally {
            busy = false;
            sendButton.disabled = false;
            status.textContent = 'en línea';

            if (window.matchMedia('(pointer: fine)').matches) {
                input.focus({ preventScroll: true });
            }
        }
    }

    async function post(payload) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 90_000);

        let response;

        try {
            response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify(payload),
                signal: controller.signal,
                credentials: 'same-origin',
            });
        } catch {
            throw new Error('No pude conectarme. Revisá tu conexión y probá de nuevo.');
        } finally {
            clearTimeout(timer);
        }

        if (response.status === 429) {
            throw new Error('Recibí muchas consultas seguidas desde tu conexión. Esperá un minuto y volvé a escribirme.');
        }

        // The session expired under an open tab: the token on the page no
        // longer matches, and only a reload hands out a new one.
        if (response.status === 419) {
            throw new Error('La página estuvo abierta mucho tiempo. Recargala y volvé a escribirme.');
        }

        if (!response.ok) {
            throw new Error('Tuve un problema procesando tu consulta. Probá de nuevo en unos minutos.');
        }

        return (await response.json()).mensajes ?? [];
    }

    function appendBubble(node) {
        log.appendChild(node);
        log.scrollTo({ top: log.scrollHeight, behavior: 'smooth' });

        return node;
    }

    function outgoing(text) {
        const row = el('div', 'chat-row chat-row-out');
        const bubble = el('div', 'chat-bubble chat-bubble-out');
        bubble.innerHTML = format(text);
        bubble.appendChild(meta(true));
        row.appendChild(bubble);

        return row;
    }

    function incoming(bubble) {
        const row = el('div', 'chat-row chat-row-in');
        const box = el('div', 'chat-bubble chat-bubble-in');

        if (bubble.tipo === 'documento') {
            box.classList.add('chat-bubble-wide');
            box.appendChild(documentCard(bubble));
        }

        if (bubble.tipo === 'ubicacion') {
            box.classList.add('chat-bubble-wide');
            box.appendChild(locationCard(bubble));
        }

        if (bubble.texto) {
            const text = el('div', 'chat-text');
            text.innerHTML = format(bubble.texto);
            box.appendChild(text);
        }

        box.appendChild(meta(false));
        row.appendChild(box);

        const actions = bubble.botones ?? (bubble.lista ? [{ lista: bubble.lista }] : []);

        if (actions.length > 0) {
            const group = el('div', 'chat-actions');

            actions.forEach((action) => {
                const tap = el('button', 'chat-action');
                tap.type = 'button';

                if (action.lista) {
                    tap.textContent = action.lista.etiqueta;
                    tap.addEventListener('click', () => openSheet(action.lista));
                } else {
                    tap.textContent = action.title;
                    tap.addEventListener('click', () => ask(action.title, action.id));
                }

                group.appendChild(tap);
            });

            row.appendChild(group);
        }

        return row;
    }

    function documentCard(bubble) {
        const link = el('a', 'chat-doc');
        link.href = bubble.url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.innerHTML = '<span class="chat-doc-icon">PDF</span>';

        const name = el('span', 'chat-doc-name');
        name.textContent = bubble.archivo || 'Documento';
        const hint = el('span', 'chat-doc-hint');
        hint.textContent = 'Abrir';

        const label = el('span', 'chat-doc-label');
        label.append(name, hint);
        link.appendChild(label);

        return link;
    }

    function locationCard(bubble) {
        const link = el('a', 'chat-map');
        link.href = `https://www.google.com/maps/search/?api=1&query=${bubble.latitud},${bubble.longitud}`;
        link.target = '_blank';
        link.rel = 'noopener';
        link.innerHTML = '<span class="chat-map-art" aria-hidden="true"><span class="chat-map-pin">📍</span></span>';

        const name = el('b', 'chat-map-name');
        name.textContent = bubble.nombre;
        link.appendChild(name);

        if (bubble.direccion) {
            const address = el('span', 'chat-map-address');
            address.textContent = bubble.direccion;
            link.appendChild(address);
        }

        return link;
    }

    function typingBubble() {
        const row = el('div', 'chat-row chat-row-in');
        row.innerHTML = '<div class="chat-bubble chat-bubble-in chat-typing" aria-label="escribiendo"><span></span><span></span><span></span></div>';

        return row;
    }

    function meta(sent) {
        const time = el('span', 'chat-time');
        time.textContent = new Date().toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });

        if (sent) {
            time.insertAdjacentHTML('beforeend', ' <span class="chat-ticks">✓✓</span>');
        }

        return time;
    }

    function openSheet(list) {
        sheet.querySelector('[data-sheet-title]').textContent = list.etiqueta;
        const rows = sheet.querySelector('[data-sheet-rows]');
        rows.replaceChildren();

        list.filas.forEach((option) => {
            const row = el('button', 'chat-sheet-row');
            row.type = 'button';

            const title = el('b');
            title.textContent = option.title;
            row.appendChild(title);

            if (option.description) {
                const description = el('span');
                description.textContent = option.description;
                row.appendChild(description);
            }

            row.addEventListener('click', () => {
                closeSheet();
                ask(option.title, option.id);
            });

            rows.appendChild(row);
        });

        sheet.hidden = false;
        rows.querySelector('button')?.focus({ preventScroll: true });
    }

    function closeSheet() {
        sheet.hidden = true;
    }
}

/**
 * WhatsApp's own markup — *bold*, _italic_, ~strike~, ```mono``` and `code` —
 * over text escaped first, so nothing the bot relays from ANAC or the SMN can
 * ever be read as HTML.
 */
function format(text) {
    let html = escapeHtml(text);

    // Set aside before the markup runs, so an underscore in a URL or an
    // asterisk in a raw report is never read as formatting.
    const kept = [];
    const keep = (fragment) => `\u0000${kept.push(fragment) - 1}\u0000`;

    html = html
        .replace(/\n?```([\s\S]+?)```\n?/g, (_, code) => keep(`<code class="chat-code">${code.replace(/^\n|\n$/g, '')}</code>`))
        .replace(/`([^`\n]+)`/g, (_, code) => keep(`<code class="chat-inline-code">${code}</code>`))
        .replace(/https?:\/\/[^\s<]+[^\s<.,;:!?)"']/g, (url) => keep(`<a href="${url}" target="_blank" rel="noopener">${url}</a>`));

    html = html
        .replace(/(^|[\s(¿¡;])\*(?!\s)([^*\n]+?)\*(?=$|[\s.,;:!?)&])/gm, '$1<strong>$2</strong>')
        .replace(/(^|[\s(¿¡;])_(?!\s)([^_\n]+?)_(?=$|[\s.,;:!?)&])/gm, '$1<em>$2</em>')
        .replace(/(^|[\s(¿¡;])~(?!\s)([^~\n]+?)~(?=$|[\s.,;:!?)&])/gm, '$1<s>$2</s>')
        .replace(/\n/g, '<br>');

    return html.replace(/\u0000(\d+)\u0000/g, (_, i) => kept[Number(i)]);
}

function escapeHtml(text) {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function el(tag, className = '') {
    const node = document.createElement(tag);

    if (className) {
        node.className = className;
    }

    return node;
}
