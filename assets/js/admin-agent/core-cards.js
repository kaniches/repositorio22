(function(){
    'use strict';

    window.APAI_AGENT_UI = window.APAI_AGENT_UI || {};
    const UI = window.APAI_AGENT_UI;

	// Safety: core-ui exposes this lock globally. In case load order changes or a theme/plugin
	// interferes, define a fallback here so action buttons never break.
	window.__apaiActionLock = window.__apaiActionLock || {
		locked: false,
		key: '',
		lock: function(key){
			if (this.locked) { return false; }
			this.locked = true;
			this.key = key || 'action';
			return true;
		},
		unlock: function(){
			this.locked = false;
			this.key = '';
		}
	};
	var __apaiActionLock = window.__apaiActionLock;

    UI.corecards = UI.corecards || {};

    function closeAllActionCards(label){
        document.querySelectorAll('.apai-agent-action-card').forEach(card => closeActionCard(card, label));
    }

    // Elimina completamente las tarjetas de acción de la UI (para queries / respuestas sin pending).
    // Esto evita estado residual visual (botones/cajas antiguas) cuando el backend ya no tiene pending_action.
    function removeAllActionCards(){
        try{
            document.querySelectorAll('.apai-agent-action-card').forEach(card => {
                const state = card && card.dataset ? String(card.dataset.apaiState || '') : '';
                if(state === 'closed') return; // keep closed cards as history
                try{ card.remove(); }catch(e){ if(card && card.parentNode){ card.parentNode.removeChild(card); } }
            });
        }catch(e){}

        // (No dock) closed cards remain as history; open cards are removed.
    }

    // Stable key for the currently pending action.
    // Used to avoid re-rendering the same card on every response (e.g. A1–A8 queries).
    function apaiPendingKey(pending){
        if(!pending) return '';
        const t = pending.type || '';
        const ca = pending.created_at || '';
        const a = pending.action || {};
        const pid = a.product_id || pending.product_id || '';
        const hs = a.human_summary || pending.human_summary || '';
        return [t, ca, pid, hs].join('|');
    }

    // Tracks which pending action is already rendered in the history.
    // (Keeps behavior deterministic across messages and avoids UI jumps.)
    let apai_last_pending_key = '';


// --- Target selection (2–5 candidates) ---
function apaiTargetSelectionKey(sel){
    if(!sel) return '';
    const kind = sel.kind || '';
    const field = sel.field || '';
    const value = sel.value || '';
    const at = sel.asked_at || '';
    const ids = Array.isArray(sel.candidates) ? sel.candidates.map(c => (c && c.id) ? c.id : '').join(',') : '';
    return [kind, field, value, at, ids].join('|');
}

function removeAllTargetSelectionCards(){
    try{
        document.querySelectorAll('.apai-agent-target-card').forEach(card => {
            try{ card.remove(); }catch(e){ if(card && card.parentNode){ card.parentNode.removeChild(card); } }
        });
    }catch(e){}
    try{ window.__apai_last_target_sel_key = ''; }catch(e){}
}

// Compat helper: some callers expect a "closeAll*" API.
// For target selection we remove the cards entirely (they are not part of chat history).
function closeAllTargetSelectionCards(label){
    removeAllTargetSelectionCards();
}

function ensureTargetSelectionCardFromServerTruth(payload){
    try{
        const pa = (payload && payload.store_state) ? payload.store_state.pending_action : null;
        if(pa){
            // Pending action takes precedence; don't show selection at the same time.
            removeAllTargetSelectionCards();
            return;
        }
        const sel = (payload && payload.store_state) ? payload.store_state.pending_target_selection : null;
        const keyNow = apaiTargetSelectionKey(sel);
        const existing = document.querySelector('.apai-agent-target-card');

        if(!sel){
            removeAllTargetSelectionCards();
            return;
        }

        if(existing && window.__apai_last_target_sel_key && window.__apai_last_target_sel_key === keyNow){
            return;
        }

        removeAllTargetSelectionCards();
        addTargetSelectionCard(sel);
        try{ window.__apai_last_target_sel_key = keyNow; }catch(e){}
    }catch(e){}
}

function addTargetSelectionCard(sel){
    const container = document.getElementById('apai_agent_messages');
    if(!container || !sel) return;

    // Variation selector: multi-select variations for variable product price/sale changes.
    if((sel.kind || '') === 'variation_selector'){
        addVariationSelectionCard(sel);
        return;
    }

    // We can show the selector even with an empty initial candidate list
    // because the user may want to search in a huge catalog.
    const initialCandidates = Array.isArray(sel.candidates) ? sel.candidates : [];
    let totalCount = typeof sel.total === 'number' ? sel.total : (Array.isArray(sel.candidates) ? sel.candidates.length : 0);
    const pageLimit = typeof sel.limit === 'number' && sel.limit > 0 ? sel.limit : 20;

    let currentQuery = (sel.query || '').toString();
    let selectedId = null;
    let loaded = [];

    const card = document.createElement('div');
    card.className = 'apai-agent-action-card apai-agent-target-card';

    const title = document.createElement('div');
    title.className = 'apai-agent-action-title';
    title.textContent = 'Elegí el producto';

    const summary = document.createElement('div');
    summary.className = 'apai-agent-action-summary';
    summary.textContent = 'Marcá un producto en la lista o buscá por nombre.';

    card.appendChild(title);
    card.appendChild(summary);

    // Search bar
    const searchWrap = document.createElement('div');
    searchWrap.className = 'apai-target-search';

    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.value = currentQuery;
    searchInput.placeholder = 'Buscar productos...';
    searchInput.className = 'apai-target-search-input';

    const searchBtn = document.createElement('button');
    searchBtn.type = 'button';
    searchBtn.className = 'button button-secondary apai-target-search-btn';
    searchBtn.textContent = 'Buscar';

    searchWrap.appendChild(searchInput);
    searchWrap.appendChild(searchBtn);
    card.appendChild(searchWrap);

    // Meta (count)
    const meta = document.createElement('div');
    meta.className = 'apai-target-meta';
    card.appendChild(meta);

    // List
    const list = document.createElement('div');
    list.className = 'apai-target-list';
    card.appendChild(list);

    // Footer actions
    const actions = document.createElement('div');
    actions.className = 'apai-target-actions';

    const pickBtn = document.createElement('button');
    pickBtn.type = 'button';
    pickBtn.className = 'button button-primary';
    pickBtn.textContent = 'Seleccionar';
    pickBtn.disabled = true;

    const loadMoreBtn = document.createElement('button');
    loadMoreBtn.type = 'button';
    loadMoreBtn.className = 'button button-secondary';
    loadMoreBtn.textContent = 'Cargar más';

    // Cancel selection (UI-only): clears server-side pending_target_selection.
    // WHY: Users need a one-click escape hatch while browsing candidates.
    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'button button-secondary';
    cancelBtn.textContent = 'Cancelar';

    actions.appendChild(pickBtn);
    actions.appendChild(loadMoreBtn);
    actions.appendChild(cancelBtn);
    card.appendChild(actions);

    const hint = document.createElement('div');
    hint.className = 'apai-target-hint';
    hint.textContent = 'Si preferís, también podés escribir el ID/SKU en el chat.';
    card.appendChild(hint);

    function renderMeta(){
        const shown = loaded.length;
        if(totalCount && totalCount > 0){
            // UX: only say "podés seguir cargando" when there are more results.
            if(shown < totalCount){
                meta.textContent = 'Mostrando ' + shown + ' de ' + totalCount + ' (podés seguir cargando).';
                loadMoreBtn.style.display = '';
            }else{
                meta.textContent = 'Mostrando ' + shown + ' de ' + totalCount + '.';
                loadMoreBtn.style.display = 'none';
            }
        }else{
            meta.textContent = shown > 0 ? ('Mostrando ' + shown + ' resultados.') : 'No hay resultados todavía.';
            loadMoreBtn.style.display = (shown >= pageLimit) ? '' : 'none';
        }
    }

    function renderList(){
        list.innerHTML = '';
        loaded.forEach((c) => {
            if(!c || !c.id) return;

            const row = document.createElement('label');
            row.className = 'apai-target-row';

            const box = document.createElement('input');
            box.type = 'checkbox';
            box.className = 'apai-target-check';
            box.checked = (selectedId !== null && String(selectedId) === String(c.id));

            box.addEventListener('change', () => {
                // Single selection semantics: only one checked.
                if(box.checked){
                    selectedId = c.id;
                    // uncheck others
                    const all = list.querySelectorAll('input.apai-target-check');
                    all.forEach(el => {
                        if(el !== box){ el.checked = false; }
                    });
                    pickBtn.disabled = false;
                }else{
                    selectedId = null;
                    pickBtn.disabled = true;
                }
            });

            const txt = document.createElement('div');
            txt.className = 'apai-target-text';

            // Thumbnail (UI-only): helps disambiguate visually in large catalogs.
            const thumb = document.createElement('div');
            thumb.className = 'apai-target-thumb';
            if(c.thumb_url){
                try{
                    thumb.style.backgroundImage = 'url(' + c.thumb_url + ')';
                    thumb.classList.add('has-img');
                }catch(e){}
            }

            const main = document.createElement('div');
            main.className = 'apai-target-title';
            main.textContent = (c.title || ('Producto #' + c.id));

            const sub = document.createElement('div');
            sub.className = 'apai-target-sub';
            // Badges: ID / SKU / Precio
            const b1 = document.createElement('span');
            b1.className = 'apai-badge';
            b1.textContent = 'ID: ' + c.id;
            sub.appendChild(b1);

            if(c.sku){
                const b2 = document.createElement('span');
                b2.className = 'apai-badge';
                b2.textContent = 'SKU: ' + c.sku;
                sub.appendChild(b2);
            }
            if(c.price !== undefined && c.price !== null && String(c.price) !== ''){
                const b3 = document.createElement('span');
                b3.className = 'apai-badge';
                b3.textContent = 'Precio: ' + c.price;
                sub.appendChild(b3);
            }

            // Categories (UI-only)
            if(Array.isArray(c.categories) && c.categories.length){
                c.categories.slice(0, 2).forEach((cat) => {
                    const b = document.createElement('span');
                    b.className = 'apai-badge apai-badge-cat';
                    b.textContent = String(cat);
                    sub.appendChild(b);
                });
            }

            txt.appendChild(main);
            txt.appendChild(sub);

            row.appendChild(box);
            row.appendChild(thumb);
            row.appendChild(txt);
            list.appendChild(row);
        });
        renderMeta();
    }

    async function fetchPage(opts){
        const q = (opts && typeof opts.q === 'string') ? opts.q : currentQuery;
        const offset = (opts && typeof opts.offset === 'number') ? opts.offset : loaded.length;
        const limit = (opts && typeof opts.limit === 'number') ? opts.limit : pageLimit;

        // REST endpoint registered by the Brain.
        if(!APAI_AGENT_DATA || !APAI_AGENT_DATA.product_search_url){
            return { ok:false, items:[], total:0 };
        }

        const url = new URL(APAI_AGENT_DATA.product_search_url, window.location.origin);
        url.searchParams.set('q', q);
        url.searchParams.set('offset', String(offset));
        url.searchParams.set('limit', String(limit));

        const res = await fetch(url.toString(), {
            method: 'GET',
            headers: {
                'X-WP-Nonce': APAI_AGENT_DATA.nonce,
            },
        });
        return await res.json();
    }

    async function runSearch(reset){
        const q = (searchInput.value || '').toString().trim();
        currentQuery = q;
        if(reset){
            loaded = [];
            selectedId = null;
            pickBtn.disabled = true;
        }
        try{
            searchBtn.disabled = true;
            loadMoreBtn.disabled = true;

            const data = await fetchPage({ q: currentQuery, offset: reset ? 0 : loaded.length, limit: pageLimit });
            if(data && data.ok){
                const items = Array.isArray(data.items) ? data.items : [];
                if(typeof data.total === 'number'){
                    totalCount = data.total;
                }
                // Normalize
                // Keep UI-only fields for a richer selector (thumbnail + categories)
                // without changing backend logic.
                const norm = items.map(it => ({
                    id: it.id,
                    title: it.title,
                    sku: it.sku,
                    price: it.price,
                    thumb_url: it.thumb_url,
                    categories: it.categories,
                })).filter(it => it && it.id);

                loaded = reset ? norm : loaded.concat(norm);
                renderList();
            }
        }catch(e){
            // keep silent
        }finally{
            searchBtn.disabled = false;
            loadMoreBtn.disabled = false;
        }
    }

    // Initial render from server candidates (first page)
    loaded = initialCandidates.map(it => ({
        id: it.id,
        title: it.title,
        sku: it.sku,
        price: it.price,
        thumb_url: it.thumb_url,
        categories: it.categories,
    })).filter(it => it && it.id);
    renderList();

    searchBtn.addEventListener('click', (e) => {
        e.preventDefault();
        runSearch(true);
    });
    searchInput.addEventListener('keydown', (e) => {
        if(e.key === 'Enter'){
            e.preventDefault();
            runSearch(true);
        }
    });
    loadMoreBtn.addEventListener('click', (e) => {
        e.preventDefault();
        runSearch(false);
    });
    cancelBtn.addEventListener('click', (e) => {
        e.preventDefault();
        // Backend already knows how to cancel this sub-flow via text token.
        // We keep it silent to avoid cluttering the chat with UI clicks.
        try{ cancelBtn.disabled = true; }catch(err){}
        UI.sendMessage('cancelar', { silentUser: true });
    });
    pickBtn.addEventListener('click', (e) => {
        e.preventDefault();
        if(!selectedId) return;
        UI.sendMessage('ID ' + selectedId, { silentUser: true });
    });

    container.appendChild(card);
    UI.coreui.scrollMessagesToBottom(true);
}

// --- Variation selector (multi-select) ---
function addVariationSelectionCard(sel){
    const container = document.getElementById('apai_agent_messages');
    if(!container || !sel) return;

    const candidates = Array.isArray(sel.candidates) ? sel.candidates : [];
    const totalCount = typeof sel.total === 'number' ? sel.total : candidates.length;
    const productId = sel.product_id || null;
    const changes = (sel.changes && typeof sel.changes === 'object') ? sel.changes : {};

    const rp = (typeof changes.regular_price !== 'undefined') ? String(changes.regular_price) : '';
    const sp = (typeof changes.sale_price !== 'undefined') ? String(changes.sale_price) : '';

    const card = document.createElement('div');
    card.className = 'apai-agent-action-card apai-agent-target-card apai-agent-variation-card';

    const title = document.createElement('div');
    title.className = 'apai-agent-action-title';
    title.textContent = 'Elegí variaciones';

    const summary = document.createElement('div');
    summary.className = 'apai-agent-action-summary';
    let s = 'Seleccioná las variaciones a las que querés aplicar el cambio.';
    if(productId){ s += ' (Producto #' + productId + ')'; }
    if(rp || sp){
        const parts = [];
        if(rp) parts.push('Precio → ' + (UI && UI.utils && UI.utils.formatPrice ? UI.utils.formatPrice(rp) : ('$' + rp)));
        if(sp) parts.push('Oferta → ' + (UI && UI.utils && UI.utils.formatPrice ? UI.utils.formatPrice(sp) : ('$' + sp)));
        s += '\n' + parts.join(' · ');
    }
    summary.textContent = s;

    card.appendChild(title);
    card.appendChild(summary);

    const meta = document.createElement('div');
    meta.className = 'apai-target-meta';
    meta.textContent = 'Variaciones: ' + candidates.length + (totalCount ? (' / ' + totalCount) : '');
    card.appendChild(meta);

    const list = document.createElement('div');
    list.className = 'apai-target-list';
    card.appendChild(list);

    const selected = new Set();

    function render(){
        list.innerHTML = '';
        if(!candidates.length){
            const empty = document.createElement('div');
            empty.className = 'apai-target-empty';
            empty.textContent = 'No pude listar variaciones. Probá de nuevo o escribí los IDs en el chat.';
            list.appendChild(empty);
            return;
        }

        candidates.forEach(it => {
            const id = it && it.id ? String(it.id) : '';
            if(!id) return;

            const row = document.createElement('label');
            row.className = 'apai-target-item apai-variation-item';

            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = selected.has(id);
            cb.addEventListener('change', () => {
                if(cb.checked) selected.add(id); else selected.delete(id);
                applySelectedBtn.disabled = selected.size === 0;
            });

            const text = document.createElement('div');
            text.className = 'apai-target-item-text';
            const label = it.label || ('#' + id);
            const rp0 = (typeof it.regular_price !== 'undefined') ? String(it.regular_price) : '';
            const sp0 = (typeof it.sale_price !== 'undefined') ? String(it.sale_price) : '';
            const priceBits = [];
            if(rp0){ priceBits.push('Precio: ' + (UI && UI.utils && UI.utils.formatPrice ? UI.utils.formatPrice(rp0) : ('$' + rp0))); }
            if(sp0){ priceBits.push('Oferta: ' + (UI && UI.utils && UI.utils.formatPrice ? UI.utils.formatPrice(sp0) : ('$' + sp0))); }
            text.innerHTML = '<div class="apai-target-item-title">' + escapeHtml(label) + '</div>' +
                (priceBits.length ? ('<div class="apai-target-item-sub">' + escapeHtml(priceBits.join(' · ')) + '</div>') : '');

            row.appendChild(cb);
            row.appendChild(text);
            list.appendChild(row);
        });
    }

    const actions = document.createElement('div');
    actions.className = 'apai-target-actions';

    const applySelectedBtn = document.createElement('button');
    applySelectedBtn.type = 'button';
    applySelectedBtn.className = 'button button-primary';
    applySelectedBtn.textContent = 'Aplicar a seleccionadas';
    applySelectedBtn.disabled = true;

    const applyAllBtn = document.createElement('button');
    applyAllBtn.type = 'button';
    applyAllBtn.className = 'button button-secondary';
    applyAllBtn.textContent = 'Aplicar a todas';

    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'button button-secondary';
    cancelBtn.textContent = 'Cancelar';

    actions.appendChild(applySelectedBtn);
    actions.appendChild(applyAllBtn);
    actions.appendChild(cancelBtn);
    card.appendChild(actions);

    function apiUrl(){
        try{
            if(window.APAI_AGENT_DATA && APAI_AGENT_DATA.variations_apply_url){
                return APAI_AGENT_DATA.variations_apply_url;
            }
        }catch(e){}
        return null;
    }

    async function postApply(payload){
        const url = apiUrl();
        if(!url){
            UI.coreui && UI.coreui.addBotMessage && UI.coreui.addBotMessage('Error: falta endpoint de variaciones.');
            return;
        }
        // Prevent double clicks
        if(!__apaiActionLock.lock('variation_apply')) return;
        try{
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': APAI_AGENT_DATA.nonce },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            // Render similarly to core.js (but without touching history).
            try{
                if(data && data.ok){
                    if(UI && UI.coreui && typeof UI.coreui.addAssistantTyped === 'function'){
                        UI.coreui.addAssistantTyped(data.reply || '(Respuesta vacía)');
                    } else if(UI && UI.coreui && typeof UI.coreui.addMessage === 'function'){
                        UI.coreui.addMessage('assistant', data.reply || '(Respuesta vacía)');
                    }
                    try{ UI && UI.coreui && UI.coreui.fetchDebug && UI.coreui.fetchDebug(); }catch(e){}
                    try{ UI && UI.corecards && UI.corecards.ensurePendingCardFromServerTruth && UI.corecards.ensurePendingCardFromServerTruth(data); }catch(e){}
                    try{ UI && UI.corecards && UI.corecards.ensureTargetSelectionCardFromServerTruth && UI.corecards.ensureTargetSelectionCardFromServerTruth(data); }catch(e){}
                } else {
                    const msg = (data && (data.message || data.error || data.code)) ? String(data.message || data.error || data.code) : 'Error.';
                    if(UI && UI.coreui && typeof UI.coreui.addMessage === 'function'){
                        UI.coreui.addMessage('assistant', 'Error: ' + msg);
                    }
                }
            }catch(e){
                const txt = (data && data.reply) ? data.reply : (data && data.message ? data.message : 'Listo.');
                if(UI && UI.coreui && typeof UI.coreui.addMessage === 'function') UI.coreui.addMessage('assistant', txt);
            }
        }catch(e){
            console.warn('APAI: variations apply failed', e);
            UI.coreui && UI.coreui.addBotMessage && UI.coreui.addBotMessage('Error al aplicar variaciones.');
        }finally{
            __apaiActionLock.unlock();
        }
    }

    applySelectedBtn.addEventListener('click', () => {
        const ids = Array.from(selected).map(x => parseInt(x, 10)).filter(Boolean);
        postApply({ selected_ids: ids, apply_all: false, tab_id: UI && UI.coreui ? UI.coreui.getTabId() : undefined, tab_instance: UI && UI.coreui ? UI.coreui.getTabInstance() : undefined });
    });
    applyAllBtn.addEventListener('click', () => {
        postApply({ selected_ids: [], apply_all: true, tab_id: UI && UI.coreui ? UI.coreui.getTabId() : undefined, tab_instance: UI && UI.coreui ? UI.coreui.getTabInstance() : undefined });
    });
    cancelBtn.addEventListener('click', () => {
        // Reuse clear_pending_url to clear pending_target_selection too.
        try{
            const url = (window.APAI_AGENT_DATA && APAI_AGENT_DATA.clear_pending_url) ? APAI_AGENT_DATA.clear_pending_url : null;
            if(!url){ removeAllTargetSelectionCards(); return; }
            fetch(url, {
                method:'POST',
                headers:{ 'Content-Type':'application/json', 'X-WP-Nonce': APAI_AGENT_DATA.nonce },
                body: JSON.stringify({ tab_id: UI && UI.coreui ? UI.coreui.getTabId() : undefined, tab_instance: UI && UI.coreui ? UI.coreui.getTabInstance() : undefined })
            }).then(r=>r.json()).then(d=>{
                try{
                    if(UI && UI.coreui && typeof UI.coreui.addAssistantTyped === 'function'){
                        UI.coreui.addAssistantTyped((d && d.reply) ? d.reply : 'Listo.');
                    }
                    try{ UI && UI.coreui && UI.coreui.fetchDebug && UI.coreui.fetchDebug(); }catch(e){}
                    try{ UI && UI.corecards && UI.corecards.ensurePendingCardFromServerTruth && UI.corecards.ensurePendingCardFromServerTruth(d); }catch(e){}
                    try{ UI && UI.corecards && UI.corecards.ensureTargetSelectionCardFromServerTruth && UI.corecards.ensureTargetSelectionCardFromServerTruth(d); }catch(e){}
                }catch(e){}
                removeAllTargetSelectionCards();
            }).catch(()=>{ removeAllTargetSelectionCards(); });
        }catch(e){ removeAllTargetSelectionCards(); }
    });

    container.appendChild(card);
    render();
}

function escapeHtml(s){
    try{
        return String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#039;'}[c] || c));
    }catch(e){
        return String(s || '');
    }
}


    
    function closeAllActionCardsBySummary(summary, label){
        try{
            const cards = document.querySelectorAll('.apai-agent-action-card');
            cards.forEach(c => {
                const s = c.querySelector('.apai-agent-action-summary');
                const st = s ? String(s.textContent || '').trim() : '';
                const target = summary ? String(summary).trim() : '';
                if(!target || st === target){
                    closeActionCard(c, label);
                }
            });
        }catch(e){}
    }

	function normalizeActionForUI(action){
		// Some server paths wrap pending_action as an envelope: { type, action:{...}, created_at }
		if(action && typeof action === 'object' && action.action && typeof action.action === 'object'){
			return action.action;
		}
		return action;
	}


    async function fetchProductSummary(productId){
        try{
            if(!productId) return null;
            if(!window.APAI_AGENT_DATA || !APAI_AGENT_DATA.product_summary_url) return null;
            const url = new URL(APAI_AGENT_DATA.product_summary_url, window.location.origin);
            url.searchParams.set('id', String(productId));
            const res = await fetch(url.toString(), {
                method: 'GET',
                headers: { 'X-WP-Nonce': APAI_AGENT_DATA.nonce }
            });
            const data = await res.json();
            if(data && data.ok && data.product){
                return data.product;
            }
        }catch(e){}
        return null;
    }

	// If a response proposed an action but did not include store_state.pending_action
	// (edge paths / caching), fetch the server-truth snapshot (lite) and render from it.
	// @INVARIANT: buttons are shown ONLY when the server says pending_action != null.
	// Keep the pending action card stable in the chat.
	// Important: do NOT re-render (or move) the same pending card on every response (A1–A8),
	// otherwise it jumps to the bottom and looks like it belongs to the last message.
	function ensurePendingCardFromServerTruth(payload) {
  // Source of truth: server-side store_state.pending_action
  try {
    const uiLabels = (payload && payload.ui_labels) ? payload.ui_labels : {};
    const meta     = (payload && payload.meta) ? payload.meta : {};

    const ss = (payload && payload.store_state) || (payload && payload.context_lite && payload.context_lite.store_state) || {};
    const pa = ss.pending_action || null;

    // No pending on server -> remove any local action card
    if (!pa || !pa.action) {
      removeAllActionCards();
      window.apaiLastPendingCreatedAt = null;
      return;
    }

    const createdAt = pa.created_at || null;
    const existing = document.querySelectorAll('.apai-action-card');

    // If already rendered for this exact pending, keep it
    if (createdAt && window.apaiLastPendingCreatedAt === createdAt && existing && existing.length > 0) {
      return;
    }

    // Replace whatever is shown with the latest pending.
    // We also pass the pending id through the action object so the Confirm button
    // can call Brain /confirm deterministically.
    removeAllActionCards();
    try{
      if(pa && pa.id && pa.action && typeof pa.action === 'object'){
        pa.action.__pending_action_id = String(pa.id);
      }
    }catch(e){}
    addActionCard(pa.action, uiLabels, meta);
    window.apaiLastPendingCreatedAt = createdAt;
  } catch (e) {
    // Never break chat UX because of card rendering
    console.warn('APAI: ensurePendingCardFromServerTruth failed', e);
  }
}

	function addActionCard(action, uiLabels, meta){
	    const container = document.getElementById('apai_agent_messages');
	    if(!container || !action) return;
		    action = normalizeActionForUI(action);
		    if(!action) return;



        const card = document.createElement('div');
        card.className = 'apai-agent-action-card';
        try{ card.dataset.apaiState = 'active'; }catch(e){}

        const title = document.createElement('div');
        title.className = 'apai-agent-action-title';
        title.textContent = 'Acción propuesta por el asistente';

        const summary = document.createElement('div');
        summary.className = 'apai-agent-action-summary';
		    summary.textContent = action.human_summary || 'Cambiar un producto en el catálogo.';

        // Product preview (thumbnail/title/price/categories) — UI-only.
        const productPreview = document.createElement('div');
        productPreview.className = 'apai-action-product';
        productPreview.style.display = 'none';

        const pid = action.product_id || action.target_product_id || action.target_id || action.productId || null;
        if(pid){
            productPreview.style.display = '';
            productPreview.innerHTML = '' +
              '<div class="apai-action-thumb" aria-hidden="true"></div>' +
              '<div class="apai-action-meta">' +
                '<div class="apai-action-name">Producto #' + pid + '</div>' +
                '<div class="apai-action-sub">Cargando detalles…</div>' +
                '<div class="apai-action-cats"></div>' +
              '</div>';

            // Fill async (does not affect action logic).
            fetchProductSummary(pid).then((p) => {
                if(!p) return;
                try{
                    const thumb = productPreview.querySelector('.apai-action-thumb');
                    const name = productPreview.querySelector('.apai-action-name');
                    const sub = productPreview.querySelector('.apai-action-sub');
                    const cats = productPreview.querySelector('.apai-action-cats');

                    if(name) name.textContent = p.title || p.name || ('Producto #' + pid);
                    const fmtPrice = (v) => {
                        try {
                            if (UI && UI.utils && typeof UI.utils.formatPrice === 'function') {
                                return UI.utils.formatPrice(v);
                            }
                        } catch(e){}
                        return (v == null) ? '' : String(v);
                    };

                    const pieces = [];
                    if(p.id) pieces.push('ID ' + p.id);

                    // Price: show proposed change if present (avoid preview "desfasado")
                    const currentPriceTxt = fmtPrice(p.price);
                    let proposedPriceRaw = null;
                    try {
                        if (action && action.changes) {
                            if (action.changes.regular_price != null) proposedPriceRaw = action.changes.regular_price;
                            else if (action.changes.price != null) proposedPriceRaw = action.changes.price;
                        }
                    } catch(e){}
                    const proposedPriceTxt = proposedPriceRaw != null ? fmtPrice(proposedPriceRaw) : '';
                    let pricePart = currentPriceTxt;
                    if (proposedPriceTxt) {
                        pricePart = (currentPriceTxt && currentPriceTxt !== proposedPriceTxt)
                            ? (currentPriceTxt + ' → ' + proposedPriceTxt)
                            : proposedPriceTxt;
                    }
                    if(pricePart) pieces.push(pricePart);

                    // Stock: show proposed change if present
                    let curStock = null;
                    try {
                        if (typeof p.stock_quantity !== 'undefined') curStock = p.stock_quantity;
                        else if (typeof p.stock !== 'undefined') curStock = p.stock;
                    } catch(e){}
                    let newStock = null;
                    try {
                        if (action && action.changes && action.changes.stock_quantity != null) newStock = action.changes.stock_quantity;
                    } catch(e){}
                    if (newStock != null) {
                        const stockPart = (curStock != null && curStock !== '')
                            ? ('Stock ' + curStock + ' → ' + newStock)
                            : ('Stock ' + newStock);
                        pieces.push(stockPart);
                    }

                    if(sub) sub.textContent = pieces.join(' · ') || '';

                    if(thumb){
                        if(p.thumb_url){
                            thumb.style.backgroundImage = 'url(' + p.thumb_url + ')';
                            thumb.classList.add('has-img');
                        }else{
                            thumb.classList.remove('has-img');
                        }
                    }

                    if(cats){
                        cats.innerHTML = '';
                        const arr = Array.isArray(p.categories) ? p.categories : [];
                        arr.slice(0, 3).forEach((c) => {
                            const chip = document.createElement('span');
                            chip.className = 'apai-chip';
                            chip.textContent = String(c);
                            cats.appendChild(chip);
                        });
                    }
                }catch(e){}
            }).catch(()=>{});
        }


		// If backend asks for a pending choice (keep pending vs swap to new), render a 2-button choice card.
		if(meta && meta.pending_choice && String(meta.pending_choice) === 'swap_to_deferred'){
			const btnRow = document.createElement('div');
			btnRow.className = 'apai-agent-action-buttons';

			const keepBtn = document.createElement('button');
			keepBtn.className = 'button button-secondary';
			keepBtn.textContent = 'Seguir con la pendiente';
			keepBtn.dataset.apaiAction = 'pending_keep';
			keepBtn.addEventListener('click', function(){
				// Replace this choice UI with the normal pending card (Confirmar/Cancelar).
				try{
					closeAllActionCardsBySummary(action.human_summary || '', null);
				}catch(e){}
				// Force render pending card in normal mode.
				try{ window.__apai_last_pending_mode = ''; }catch(e){}
				ensurePendingCardFromServerTruth({ store_state: { pending_action: { type: action.type || 'update_product', action: action } }, meta: {} });
			});

			const swapBtn = document.createElement('button');
			swapBtn.className = 'button button-primary';
				swapBtn.textContent = 'Reemplazar por la nueva';
			swapBtn.dataset.apaiAction = 'pending_swap';
				swapBtn.addEventListener('click', function(){
					// Cancel current pending and replay the deferred user message to build the new action.
					const deferred = meta && meta.deferred_message ? String(meta.deferred_message) : '';
					// Keep cancel deterministic (backend understands "cancelar").
					const cancelMsg = 'cancelar';

					UI.sendMessage(cancelMsg, { silentUser: true, replayAfter: deferred, replaySilentUser: true, pendingChoice: true });
				});

			btnRow.appendChild(keepBtn);
			btnRow.appendChild(swapBtn);

			card.appendChild(title);
			card.appendChild(summary);
			card.appendChild(btnRow);

			// Importante: esta tarjeta se usa cuando hay una acción pendiente y el usuario pidió otra.
			// Debe renderizarse en el chat (antes se retornaba sin insertarla y desaparecían los botones).
			container.appendChild(card);
			UI.coreui.scrollMessagesToBottom();
			return card;
		}

		const btn = document.createElement('button');
		btn.className = 'button button-secondary';
	        btn.classList.add('apai-confirm-btn');
		btn.type = 'button';
		const defaultConfirmLabel = 'Confirmar y ejecutar acción';
		const confirmLabel = (uiLabels && uiLabels.confirm) ? String(uiLabels.confirm) : defaultConfirmLabel;
		btn.textContent = confirmLabel;
		btn.dataset.apaiAction = 'confirm';
		btn.addEventListener('click', function(e){
			try { e.preventDefault(); e.stopPropagation(); } catch(_e) {}
            // Brain owns the chat; execution depends on selected executor agent.
            const selector = document.getElementById('apai_agent_selector');
            const selected = selector ? selector.value : 'catalog';
            if(selected === 'catalog' && !APAI_AGENT_DATA.has_cat_agent){
                UI.coreui.addMessage('assistant', 'Todavía no puedo ejecutar porque el Agente de Catálogo no está activo. Si lo activás, lo hacemos enseguida 😊');
                return;
            }

	            // Guard against fast double-clicks / confirm+cancel races.
	            if(card.dataset.apaiLocked === '1') return;
	            if(!__apaiActionLock.lock(action.human_summary || 'confirm')) return;
	            UI.coreui.lockActionCard(card, 'confirm');
            // Block chat input until the brain clears pending server-side.
            try{
                const inEl = document.getElementById('apai_agent_input');
                const sb = document.getElementById('apai_agent_send');
                if(inEl) inEl.disabled = true;
                if(sb) sb.disabled = true;
            }catch(e){}

            fetch(APAI_AGENT_DATA.execute_url, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': APAI_AGENT_DATA.nonce,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    // Brain is the source of truth: it confirms and then executes via Agent.
                    pending_action_id: action.__pending_action_id || null,
                    // Back-compat: include the action payload for older pending formats.
                    action: action,
                    session_state: loadSessionState(),
                    tab_id: (typeof getTabId === 'function') ? getTabId() : null,
                    tab_instance: (typeof getTabInstance === 'function') ? getTabInstance() : null
                })
            })
	        .then(r => r.json().then(data => ({ data, headers: r.headers })))
	        .then(({ data, headers }) => {
                if(!data.ok){
                    const msg = data.message || data.error || data.code || 'Error al ejecutar la acción.';
                    // NOOP friendly: si no hay cambios para aplicar, tratamos como éxito y limpiamos pending.
                    if(typeof msg === 'string' && msg.toLowerCase().includes('no hay cambios para aplicar')){
						// Hide action buttons for the *clicked* card even if the summary text mismatches.
						try{ if(typeof closeActionCard === 'function') closeActionCard(card, '✅ Sin cambios.'); }catch(e){}
						// Back-compat fallback (closes other open cards if any).
						closeAllActionCardsBySummary(action.human_summary || '', '✅ Sin cambios.');
                        UI.coreui.addMessage('assistant', 'Listo ✅ No había cambios para aplicar (ya estaba así).');
                        try{
                            if(APAI_AGENT_DATA.clear_pending_url){
                                fetch(APAI_AGENT_DATA.clear_pending_url, {
                                    method: 'POST',
                                    headers: {
                                        'X-WP-Nonce': APAI_AGENT_DATA.nonce,
                                        'Content-Type': 'application/json'
                                    },
                                    body: JSON.stringify({ executed: true, summary: (action && action.human_summary) ? action.human_summary : '', ts: Date.now(), noop: true })
                                }).catch(()=>{});
                            }
                        }catch(e){}
						// unlock UI
						__apaiActionLock.unlock();
						UI.coreui.unlockActionCard(card);
						try{
							const inEl = document.getElementById('apai_agent_input');
							const sb = document.getElementById('apai_agent_send');
							if(inEl) inEl.disabled = false;
							if(sb) sb.disabled = false;
						}catch(e){}
						try{
							btn.disabled = false;
							btn.textContent = 'Confirmar y ejecutar acción';
						}catch(e){}
                        return;
                    }
                    UI.coreui.addMessage('assistant', 'Error al ejecutar la acción: ' + msg);
                    console.error(data);
                    try{
                        const inEl = document.getElementById('apai_agent_input');
                        const sb = document.getElementById('apai_agent_send');
                        if(inEl) inEl.disabled = false;
                        if(sb) sb.disabled = false;
                    }catch(e){}
	                    // unlock UI
	                    __apaiActionLock.unlock();
	                    UI.coreui.unlockActionCard(card);
	                    btn.disabled = false;
	                    btn.textContent = 'Intentar de nuevo';
                    return;
                }
	                // Cerrar UI post-ejecución: Confirmar/Cancelar deben desaparecer siempre.
	                // Importante: cerramos SIEMPRE la card clickeada, aunque el texto del summary no coincida.
	                try{ if(typeof closeActionCard === 'function') closeActionCard(card, '✅ Ejecutada'); }catch(e){}
						// Back-compat fallback (closes other open cards if any).
						closeAllActionCardsBySummary(action.human_summary || '', '✅ Acción ejecutada.');
                try{
                    // If executor returned product info, store it as last_product
                    const prod = (data && data.product) ? data.product : ((data && data.data && data.data.product) ? data.data.product : null);
                    if(prod && prod.id){ setLastProduct(prod); }
                }catch(e){}
                // Persist a stable badge-style message in the chat history.
                let execMsg = data.message || ('Acción ejecutada correctamente: ' + (action && action.human_summary ? action.human_summary : ''));
                if(typeof execMsg === 'string'){
                    execMsg = execMsg.trim();
                    if(execMsg && !execMsg.startsWith('✅')){
                        execMsg = '✅ ' + execMsg;
                    }
                }
                UI.coreui.addMessage('assistant', execMsg || '✅ Acción ejecutada.');
                // Nota: el debug se refresca DESPUÉS de clear_pending (para evitar pending stale en Lite).

                // Limpieza fuerte post-ejecución (PASO 2): limpiar pending_action del lado Brain.
                // Strong server-side cleanup (PASO 2): clear pending_action in Brain.
                // We await this before unblocking chat input to avoid "hola" seeing stale pending.
                let clearPromise = Promise.resolve();
                try{
                    if(APAI_AGENT_DATA.clear_pending_url){
                        clearPromise = fetch(APAI_AGENT_DATA.clear_pending_url, {
                            method: 'POST',
                            headers: {
                                'X-WP-Nonce': APAI_AGENT_DATA.nonce,
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({ executed: true, summary: (action && action.human_summary) ? action.human_summary : '', ts: Date.now() })
                        }).then(()=>{}).catch(()=>{});
                    }
                }catch(e){}

                clearPromise.finally(() => {
                    try{
                        // Refrescar debug LITE luego de clear_pending para evitar mostrar pending stale.
                        try{ fetchDebug(); }catch(e){}
                        const inEl = document.getElementById('apai_agent_input');
                        const sb = document.getElementById('apai_agent_send');
                        if(inEl) inEl.disabled = false;
                        if(sb) sb.disabled = false;
                    }catch(e){}
	                    // unlock global action guard
	                    __apaiActionLock.unlock();
	                    UI.coreui.unlockActionCard(card);
                });

                // pending_action es 100% server-side (PASO 2). La UI no mantiene estado.
            })
            .catch(err => {
                console.error(err);
                try{
                    const inEl = document.getElementById('apai_agent_input');
                    const sb = document.getElementById('apai_agent_send');
                    if(inEl) inEl.disabled = false;
                    if(sb) sb.disabled = false;
                }catch(e){}
	                // unlock UI
	                __apaiActionLock.unlock();
	                UI.coreui.unlockActionCard(card);
                btn.disabled = false;
                btn.textContent = 'Error';
                UI.coreui.addMessage('assistant', 'Error de red al ejecutar la acción.');
            });
        });

        
        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'button button-secondary';
	        cancelBtn.classList.add('apai-cancel-btn');
		cancelBtn.type = 'button';
        const defaultCancelLabel = 'Cancelar';
        const cancelLabel = (uiLabels && uiLabels.cancel) ? String(uiLabels.cancel) : defaultCancelLabel;
        cancelBtn.textContent = cancelLabel;
        cancelBtn.dataset.apaiAction = 'cancel';
        cancelBtn.style.marginLeft = '8px';
		cancelBtn.addEventListener('click', function(e){
			try { e.preventDefault(); e.stopPropagation(); } catch(_e) {}
			// Guard against fast confirm+cancel races.
			if(card.dataset.apaiLocked === '1') return;
			if(!__apaiActionLock.lock(action.human_summary || 'cancel')) return;
			UI.coreui.lockActionCard(card, 'cancel');

			// Cancel MUST be button-driven (no chat keywords). We call the dedicated clear endpoint.
			const clearUrl = (window.APAI_AGENT_DATA && window.APAI_AGENT_DATA.clear_pending_url)
				? String(window.APAI_AGENT_DATA.clear_pending_url)
				: '';
			if(!clearUrl){
				UI.coreui.addMessage('assistant', 'No pude cancelar porque falta clear_pending_url en la configuración.');
				__apaiActionLock.unlock();
				UI.coreui.unlockActionCard(card);
				return;
			}

			try{ window.__apaiCancelInFlight = true; }catch(_e){}
			try{
				const inEl = document.getElementById('apai_agent_input');
				const sb = document.getElementById('apai_agent_send');
				if(inEl) inEl.disabled = true;
				if(sb) sb.disabled = true;
			}catch(_e){}

			fetch(clearUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ((window.APAI_AGENT_DATA && window.APAI_AGENT_DATA.nonce) ? String(window.APAI_AGENT_DATA.nonce) : '') },
				// IMPORTANT: pending_action is scoped by tab_id + tab_instance.
				// If we omit these, the backend may clear a different scope and the card will stay visible.
				body: JSON.stringify({
					reason: 'user_cancel_button',
					tab_id: (window.APAI_AGENT_DATA && window.APAI_AGENT_DATA.tab_id) ? window.APAI_AGENT_DATA.tab_id : (window.UI && UI.coreui && typeof UI.coreui.getTabId === 'function' ? UI.coreui.getTabId() : null),
					tab_instance: (window.APAI_AGENT_DATA && window.APAI_AGENT_DATA.tab_instance) ? window.APAI_AGENT_DATA.tab_instance : (window.UI && UI.coreui && typeof UI.coreui.getTabInstance === 'function' ? UI.coreui.getTabInstance() : null)
				})
			})
			.then(r => r.json())
			.then((data) => {
				// If server cleared pending, close the card and show a single confirmation.
				try{
					const st = (data && data.store_state) ? data.store_state : null;
					if(st && (st.pending_action === null || typeof st.pending_action === 'undefined')){
						try{ if(typeof closeActionCard === 'function') closeActionCard(card, '❌ Cancelada'); }catch(_e){}
						closeAllActionCardsBySummary(action.human_summary || '', '❌ Cancelada');
						UI.coreui.addMessage('assistant', 'He cancelado la acción pendiente.');
					}
				}catch(_e){}
			})
			.catch(() => {
				UI.coreui.addMessage('assistant', 'Error de red al cancelar la acción.');
			})
			.finally(() => {
				try{ window.__apaiCancelInFlight = false; }catch(_e){}
				try{
					const inEl2 = document.getElementById('apai_agent_input');
					const sb2 = document.getElementById('apai_agent_send');
					if(inEl2) inEl2.disabled = false;
					if(sb2) sb2.disabled = false;
				}catch(_e){}
				__apaiActionLock.unlock();
				UI.coreui.unlockActionCard(card);
			});
		});

card.appendChild(title);
        try{ if(productPreview) card.appendChild(productPreview); }catch(e){}
        card.appendChild(summary);

        // Mini tabla de variaciones (si aplica)
        if(action.ui_preview_table && action.ui_preview_table.headers && action.ui_preview_table.rows){
            const tblWrap = document.createElement('div');
            tblWrap.className = 'apai-agent-variation-preview';

            const tblTitle = document.createElement('div');
            tblTitle.className = 'apai-agent-variation-title';
            tblTitle.textContent = 'Variaciones y precios (preview)';
            tblWrap.appendChild(tblTitle);

            const table = document.createElement('table');
            table.className = 'apai-agent-variation-table';

            const thead = document.createElement('thead');
            const trh = document.createElement('tr');
            (action.ui_preview_table.headers || []).forEach(h => {
                const th = document.createElement('th');
                th.textContent = h;
                trh.appendChild(th);
            });
            const thp = document.createElement('th');
            thp.textContent = 'Precio';
            trh.appendChild(thp);
            thead.appendChild(trh);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');
            (action.ui_preview_table.rows || []).forEach(r => {
                const tr = document.createElement('tr');
                const attrs = (r && r.attrs) ? r.attrs : {};
                (action.ui_preview_table.headers || []).forEach(h => {
                    const td = document.createElement('td');
                    td.textContent = (attrs[h] !== undefined) ? String(attrs[h]) : '';
                    tr.appendChild(td);
                });
                const tdp = document.createElement('td');
                const n = (r && r.price !== undefined) ? Number(r.price) : 0;
                // mostrar sin decimales si es entero
                const isInt = Number.isFinite(n) && Math.abs(n - Math.round(n)) < 1e-9;
                tdp.textContent = Number.isFinite(n) ? ('$' + (isInt ? Math.round(n) : n.toFixed(2))) : '';
                tr.appendChild(tdp);
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            tblWrap.appendChild(table);

            if(action.ui_preview_table.truncated){
                const note = document.createElement('div');
                note.className = 'apai-agent-variation-note';
                note.textContent = 'Mostrando ' + (action.ui_preview_table.rows || []).length + ' de ' + action.ui_preview_table.total + ' variaciones.';
                tblWrap.appendChild(note);
            }

            card.appendChild(tblWrap);
        }

        card.appendChild(btn);
        card.appendChild(cancelBtn);
        container.appendChild(card);
        UI.coreui.scrollMessagesToBottom(true);
    }


    // Exports
    UI.corecards.ensurePendingCardFromServerTruth = ensurePendingCardFromServerTruth;
    UI.corecards.ensureTargetSelectionCardFromServerTruth = ensureTargetSelectionCardFromServerTruth;
    UI.corecards.addActionCard = addActionCard;
    UI.corecards.addTargetSelectionCard = addTargetSelectionCard;

    UI.corecards.closeAllActionCards = closeAllActionCards;
    UI.corecards.closeAllTargetSelectionCards = closeAllTargetSelectionCards;
    UI.corecards.removeAllActionCards = removeAllActionCards;
    UI.corecards.removeAllTargetSelectionCards = removeAllTargetSelectionCards;
})();
