(() => {
    if (window.EReserveValidation) return;
    // Presentation only: native constraints and Laravel remain the validators.
    const states = new WeakMap();
    let sequence = 0;
    const normalize = name => (name || '').replace(/\[([^\]]*)\]/g, '.$1').replace(/\.$/, '').replace(/\.\*$/, '');
    const matches = (name, field) => name === field || name.startsWith(field + '.') || field.startsWith(name + '.');
    const controlsFor = (form, field) => [...form.elements].filter(control => {
        const name = normalize(control.name);
        return name && matches(name, normalize(field)) && control.type !== 'hidden'
            && !control.closest('[hidden], fieldset[disabled]');
    });
    const stateFor = form => {
        if (!states.has(form)) states.set(form, {sources: {}, nodes: [], controls: new Set(), signature: ''});
        return states.get(form);
    };
    const detach = state => {
        const ids = new Set(state.nodes.map(node => node.id));
        state.controls.forEach(control => {
            const described = (control.getAttribute('aria-describedby') || '').split(/\s+/).filter(id => id && !ids.has(id));
            if (described.length) control.setAttribute('aria-describedby', described.join(' '));
            else control.removeAttribute('aria-describedby');
            control.removeAttribute('aria-invalid');
        });
        state.nodes.forEach(node => node.remove());
        state.nodes = []; state.controls.clear();
    };
    const place = (controls, error) => {
        const last = controls[controls.length - 1];
        if (['checkbox', 'radio'].includes(last.type)) {
            const group = last.closest('fieldset.modal-form-group, fieldset.reservation-access-options');
            if (group) group.append(error);
            else (last.closest('label') || last).after(error);
        } else (last.closest('.password-field, .search-field') || last).after(error);
    };
    const render = form => {
        const state = stateFor(form), fields = new Map(), general = [];
        // A live validator's current message supersedes native/server messages for the same field.
        for (const source of ['server', 'native', 'schedule', 'photos']) {
            for (const [name, messages] of Object.entries(state.sources[source] || {})) {
                const text = [...new Set([].concat(messages).filter(Boolean))].join(' ');
                if (!text) continue;
                const controls = controlsFor(form, name);
                if (!controls.length) general.push(text);
                else {
                    const key = normalize(controls[0].name), previous = fields.get(key);
                    fields.set(key, {controls, text: previous?.source === source ? [...new Set([previous.text, text])].join(' ') : text, source});
                }
            }
        }
        const signature = JSON.stringify([...fields].map(([name, item]) => [name, item.text])) + JSON.stringify(general);
        if (signature === state.signature) return [...state.controls][0];
        state.signature = signature; detach(state);
        fields.forEach(({controls, text}) => {
            const error = document.createElement('p');
            error.className = 'form-error'; error.dataset.validationError = '';
            error.dataset.validationField = controls[0].name;
            error.id = 'validation-error-' + ++sequence;
            error.setAttribute('role', 'alert'); error.textContent = text;
            place(controls, error); state.nodes.push(error);
            controls.forEach(control => {
                control.setAttribute('aria-invalid', 'true');
                control.setAttribute('aria-describedby', [control.getAttribute('aria-describedby'), error.id].filter(Boolean).join(' '));
                state.controls.add(control);
            });
        });
        let summary = form.querySelector('[data-action-error]');
        if (general.length && !summary) {
            summary = document.createElement('p'); summary.dataset.actionError = '';
            (form.querySelector('.reservation-form-fields, .facility-modal-body') || form).prepend(summary);
        }
        if (summary) {
            summary.classList.add('form-error'); summary.setAttribute('role', 'alert');
            summary.textContent = general.length ? 'Unable to submit: ' + [...new Set(general)].join(' ') : '';
            summary.hidden = !general.length;
        }
        return [...state.controls][0];
    };
    const set = (form, source, errors = {}) => { stateFor(form).sources[source] = errors; return render(form); };
    const clear = (form, source) => {
        const state = stateFor(form);
        if (source) delete state.sources[source]; else state.sources = {};
        state.signature = ''; return render(form);
    };
    const show = (form, errors = {}, message = '') => set(form, 'server', Object.keys(errors || {}).length ? errors : {_form: message});
    const initialize = () => document.querySelectorAll('form').forEach(form => {
        const errors = {};
        form.querySelectorAll('[data-validation-field]:not([data-validation-error])').forEach(node => {
            (errors[node.dataset.validationField] ||= []).push(node.textContent.trim()); node.remove();
        });
        if (Object.keys(errors).length) show(form, errors);
    });
    const changed = event => {
        const control = event.target, form = control.form;
        if (!form || !control.name) return;
        const state = stateFor(form), field = normalize(control.name);
        for (const source of ['server', 'native']) {
            const errors = state.sources[source] || {};
            for (const name of Object.keys(errors)) if (matches(normalize(name), field) || !controlsFor(form, name).length) delete errors[name];
        }
        // Refresh only a native error already shown, without triggering another invalid event.
        if (state.controls.has(control) && control.willValidate && !control.validity.valid && !control.validity.customError) {
            (state.sources.native ||= {})[control.name] = control.validationMessage;
        }
        render(form);
    };
    document.addEventListener('input', changed);
    document.addEventListener('change', changed);
    document.addEventListener('invalid', event => {
        const control = event.target;
        if (!control.form || !control.name) return;
        event.preventDefault();
        if (control.validity.customError && stateFor(control.form).sources.schedule?.[control.name]) return;
        const errors = stateFor(control.form).sources.native ||= {};
        errors[control.name] = control.validationMessage; render(control.form);
        setTimeout(() => [...stateFor(control.form).controls][0]?.focus(), 0);
    }, true);
    document.addEventListener('reset', event => { if (event.target.matches('form')) clear(event.target); });
    for (const name of ['modals:updated', 'reservations:updated']) document.addEventListener(name, initialize);
    window.EReserveValidation = {set, show, clear, initialize};
    initialize();
})();
