/**
 * Skyrocket Cookie Consent frontend.
 * Vanilla JavaScript; configuration is fetched from the public REST endpoint.
 */
(() => {
    'use strict';

    const settings = window.cookieConsentSettings || {};
    const STORAGE_KEY = 'skyrocket_cookie_consent';
    const CONSENT_COOKIE = 'skyrocket_cookie_consent';
    const CONSENT_VERSION = 3;
    const CONSENT_MAX_AGE = 60 * 60 * 24 * 180;
    let config;
    let languageConfig;
    let currentLanguage;
    let overlay;
    let panel;
    let choices = {};

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    })[character]);

    const getSavedConsent = () => {
        // The first-party consent cookie is authoritative because PHP integrations
        // must be able to read the same decision on subsequent requests.
        const match = document.cookie.match(new RegExp(`(?:^|; )${CONSENT_COOKIE}=([^;]*)`));
        if (!match) return null;
        try {
            const parsed = JSON.parse(decodeURIComponent(match[1]));
            if (!parsed?.expiresAt || Number(parsed.expiresAt) < Math.floor(Date.now() / 1000)
                || !config?.policy_version || parsed.policyVersion !== config.policy_version) return null;
            try { window.localStorage.setItem(STORAGE_KEY, JSON.stringify(parsed)); } catch (error) { /* Cookie remains authoritative. */ }
            return parsed;
        } catch (error) {
            return null;
        }
    };

    const persistConsent = (consent) => {
        const value = JSON.stringify(consent);
        try { window.localStorage.setItem(STORAGE_KEY, value); } catch (error) { /* Cookie fallback remains available. */ }
        const secure = window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = `${CONSENT_COOKIE}=${encodeURIComponent(value)}; Max-Age=${CONSENT_MAX_AGE}; Path=/; SameSite=Lax${secure}`;
        window.SkyrocketCookieConsentGuard?.update(consent);
    };

    const selectLanguage = (data) => {
        const candidates = Array.isArray(data.language_fallbacks) ? data.language_fallbacks : [];
        const selected = candidates.find((code) => Object.prototype.hasOwnProperty.call(data.languages || {}, code));
        return selected || data.default_language || Object.keys(data.languages || {})[0];
    };

    const getText = (key, fallback) => languageConfig?.[key] || fallback;

    const getCategories = () => languageConfig?.categories || {};

    const defaultChoices = () => {
        const result = {};
        Object.entries(getCategories()).forEach(([id, category]) => {
            result[id] = Boolean(category.required);
        });
        return result;
    };

    const applyRequiredChoices = (selection) => {
        const result = { ...selection };
        Object.entries(getCategories()).forEach(([id, category]) => {
            if (category.required) result[id] = true;
            else result[id] = Boolean(result[id]);
        });
        return result;
    };

    const activateConsentedScripts = (selection) => {
        document.querySelectorAll('script[type="text/plain"][data-cookie-category]').forEach((blockedScript) => {
            const category = blockedScript.dataset.cookieCategory;
            if (!selection[category] || blockedScript.dataset.sccActivated === 'true') return;
            const activeScript = document.createElement('script');
            Array.from(blockedScript.attributes).forEach((attribute) => {
                if (!['type', 'data-cookie-category', 'data-src', 'data-scc-activated'].includes(attribute.name)) {
                    activeScript.setAttribute(attribute.name, attribute.value);
                }
            });
            if (blockedScript.dataset.src) activeScript.src = blockedScript.dataset.src;
            else activeScript.textContent = blockedScript.textContent;
            activeScript.dataset.sccCategory = category;
            blockedScript.dataset.sccActivated = 'true';
            blockedScript.parentNode.insertBefore(activeScript, blockedScript.nextSibling);
        });
    };

    const deactivateRevokedScripts = (previous, next) => {
        Object.keys(previous || {}).forEach((category) => {
            if (previous[category] && !next[category]) {
                document.querySelectorAll('script[data-scc-category]').forEach((script) => {
                    if (script.dataset.sccCategory === category) script.remove();
                });
                document.querySelectorAll('script[type="text/plain"][data-cookie-category]').forEach((script) => {
                    if (script.dataset.cookieCategory === category) script.dataset.sccActivated = 'false';
                });
            }
        });
    };

    const deleteCookie = (name) => {
        const host = window.location.hostname;
        const paths = new Set(['/']);
        let path = window.location.pathname;
        while (path && path !== '/') {
            paths.add(path);
            path = path.substring(0, path.lastIndexOf('/')) || '/';
        }
        paths.forEach((cookiePath) => {
            document.cookie = `${name}=; Max-Age=0; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=${cookiePath}; SameSite=Lax`;
            if (host && host.indexOf('.') !== -1) {
                document.cookie = `${name}=; Max-Age=0; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=${cookiePath}; domain=.${host}; SameSite=Lax`;
            }
        });
    };

    const syncServerCookieCleanup = (selection) => {
        if (!settings.cleanupUrl) return;
        fetch(settings.cleanupUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ categories: applyRequiredChoices(selection) })
        }).catch(() => { /* Client-side cleanup remains available if the request fails. */ });
    };

    const deleteCookiesForRevokedCategories = (previous, next) => {
        Object.entries(getCategories()).forEach(([categoryId, category]) => {
            if (!previous?.[categoryId] || next?.[categoryId] || category.required) return;
            (category.cookies || []).forEach((cookie) => {
                const name = String(cookie.name || '').trim();
                if (name && !name.includes('*')) deleteCookie(name);
            });
        });
    };

    const announceConsent = (selection, action) => {
        const consent = {
            version: CONSENT_VERSION,
            policyVersion: config?.policy_version || '',
            timestamp: new Date().toISOString(),
            expiresAt: Math.floor(Date.now() / 1000) + CONSENT_MAX_AGE,
            language: currentLanguage,
            categories: applyRequiredChoices(selection),
            action
        };
        const previousConsent = getSavedConsent();
        const previousChoices = previousConsent?.categories || {};
        persistConsent(consent);
        choices = consent.categories;
        deleteCookiesForRevokedCategories(previousChoices, choices);
        deactivateRevokedScripts(previousChoices, choices);
        syncServerCookieCleanup(choices);
        document.dispatchEvent(new CustomEvent('skyrocket:cookie-consent-updated', { detail: consent }));
        window.dispatchEvent(new CustomEvent('skyrocket:cookie-consent-updated', { detail: consent }));
        document.documentElement.dataset.cookieConsent = 'saved';
        document.documentElement.dataset.cookieConsentCategories = Object.keys(choices).filter((id) => choices[id]).join(',');
        activateConsentedScripts(choices);
        closePanel();
        return consent;
    };

    const categoryMarkup = () => Object.entries(getCategories()).map(([id, category]) => {
        const required = Boolean(category.required);
        const selected = required || Boolean(choices[id]);
        const cookieRows = Array.isArray(category.cookies) ? category.cookies : [];
        const details = cookieRows.length ? `
            <details class="scc-cookie-details">
                <summary>${escapeHtml(getText('cookie_detail', 'Cookie detail'))} (${cookieRows.length})</summary>
                <div class="scc-cookie-table-wrap"><table class="scc-cookie-table">
                    <thead><tr><th>${escapeHtml(getText('cookie', 'Cookie'))}</th><th>${escapeHtml(getText('duration', 'Duration'))}</th><th>${escapeHtml(getText('about', 'About'))}</th></tr></thead>
                    <tbody>${cookieRows.map((cookie) => `<tr><td>${escapeHtml(cookie.name)}</td><td>${escapeHtml(cookie.expiry)}</td><td>${escapeHtml(cookie.description)}</td></tr>`).join('')}</tbody>
                </table></div>
            </details>` : '';
        return `<section class="scc-category" data-category="${escapeHtml(id)}">
            <div class="scc-category-heading"><div class="scc-category-copy"><h3>${escapeHtml(category.title || id)}</h3><p>${escapeHtml(category.description || '')}</p></div>
            <label class="scc-switch"><input type="checkbox" data-consent-category="${escapeHtml(id)}" ${selected ? 'checked' : ''} ${required ? 'disabled' : ''} aria-label="${escapeHtml(category.title || id)}"><span class="scc-switch-track" aria-hidden="true"></span></label></div>
            ${required ? `<span class="scc-required-label">${escapeHtml(getText('required', 'Always active'))}</span>` : ''}
            ${details}
        </section>`;
    }).join('');

    const render = (mode = 'banner') => {
        if (overlay) overlay.remove();
        const title = getText('popup_title', 'Cookie preferences');
        const description = getText('popup_description', 'We use cookies to optimise your experience. Choose which categories you allow.');
        overlay = document.createElement('div');
        overlay.className = `scc-overlay scc-${mode}`;
        overlay.innerHTML = `<section class="scc-dialog" role="dialog" aria-modal="true" aria-labelledby="scc-title" aria-describedby="scc-description">
            <div class="scc-dialog-header"><div><h2 id="scc-title">${escapeHtml(title)}</h2><p id="scc-description">${escapeHtml(description)}</p></div><button type="button" class="scc-close" aria-label="Close preferences">&times;</button></div>
            <div class="scc-category-list">${categoryMarkup()}</div>
            <div class="scc-actions"><button type="button" class="scc-button scc-button-secondary" data-action="reject">${escapeHtml(getText('btn_reject', 'Reject optional'))}</button><button type="button" class="scc-button scc-button-secondary" data-action="save">${escapeHtml(getText('btn_save', 'Save choices'))}</button><button type="button" class="scc-button scc-button-primary" data-action="accept">${escapeHtml(getText('btn_accept', 'Accept all'))}</button></div>
            <p class="scc-footer-note">${escapeHtml(getText('footer_note', 'You can change your preferences at any time.'))}</p>
        </section>`;
        document.body.appendChild(overlay);
        panel = overlay.querySelector('.scc-dialog');
        overlay.addEventListener('click', (event) => { if (event.target === overlay) closePanel(); });
        overlay.querySelector('.scc-close').addEventListener('click', closePanel);
        overlay.querySelectorAll('[data-consent-category]').forEach((input) => {
            input.addEventListener('change', () => { choices[input.dataset.consentCategory] = input.checked; });
        });
        overlay.querySelector('[data-action="reject"]').addEventListener('click', () => announceConsent(defaultChoices(), 'reject'));
        overlay.querySelector('[data-action="save"]').addEventListener('click', () => announceConsent(choices, 'save'));
        overlay.querySelector('[data-action="accept"]').addEventListener('click', () => {
            const all = {};
            Object.keys(getCategories()).forEach((id) => { all[id] = true; });
            announceConsent(all, 'accept');
        });
        document.addEventListener('keydown', handleEscape);
        const firstControl = overlay.querySelector('button, input');
        if (firstControl) firstControl.focus();
    };

    const handleEscape = (event) => {
        if (event.key === 'Escape' && overlay) closePanel();
    };

    const closePanel = () => {
        if (overlay) overlay.remove();
        overlay = null;
        panel = null;
        document.removeEventListener('keydown', handleEscape);
    };

    const addSettingsButton = () => {
        if (document.querySelector('.scc-settings-launcher')) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'scc-settings-launcher';
        button.textContent = getText('manage_cookies', 'Cookie settings');
        button.addEventListener('click', () => {
            const saved = getSavedConsent();
            choices = applyRequiredChoices(saved?.categories || defaultChoices());
            render('preferences');
        });
        document.body.appendChild(button);
    };

    const initialise = async () => {
        if (!settings.configUrl) return;
        try {
            const response = await fetch(settings.configUrl, { credentials: 'same-origin' });
            if (!response.ok) throw new Error(`Configuration request failed (${response.status}).`);
            config = await response.json();
            if (!config.languages || !Object.keys(config.languages).length) return;
            currentLanguage = selectLanguage(config);
            languageConfig = config.languages[currentLanguage] || config.languages[config.default_language];
            const saved = getSavedConsent();
            const validSavedConsent = saved && saved.version === CONSENT_VERSION && saved.policyVersion === config.policy_version && saved.categories && saved.expiresAt && Number(saved.expiresAt) >= Math.floor(Date.now() / 1000);
            choices = applyRequiredChoices(validSavedConsent ? saved.categories : defaultChoices());
            addSettingsButton();
            if (!validSavedConsent) {
                deleteCookiesForRevokedCategories(
                    Object.fromEntries(Object.keys(getCategories()).map((id) => [id, !getCategories()[id].required])),
                    defaultChoices()
                );
                syncServerCookieCleanup(defaultChoices());
                render('banner');
            }
            else {
                document.documentElement.dataset.cookieConsent = 'saved';
                document.documentElement.dataset.cookieConsentCategories = Object.keys(choices).filter((id) => choices[id]).join(',');
                activateConsentedScripts(choices);
            }
            window.SkyrocketCookieConsent = {
                open: () => { choices = applyRequiredChoices(getSavedConsent()?.categories || defaultChoices()); render('preferences'); },
                getConsent: getSavedConsent,
                hasConsent: (category) => Boolean(getSavedConsent()?.categories?.[category] || getCategories()[category]?.required),
                getConfig: () => config
            };
        } catch (error) {
            // A configuration failure should not break the website.
            console.error('[Skyrocket Cookie Consent]', error);
        }
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialise, { once: true });
    else initialise();
})();
