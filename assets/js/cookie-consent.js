class CookieConsent {
    constructor(configUrl) {
        this.configUrl = configUrl;
        this.consentKey = 'custom_cookie_consent';
        this.wrapper = null;
        this.config = null;
        this.lang = 'en'; // Default fallback
        this.lang_fallback = this.lang;
        this.optional_cookie_choice_exists = false;

        this.init();
    }

    async init() {
        try {
            const response = await fetch(this.configUrl);
            const fullConfig = await response.json();

            // Determine the language and pull the matching configuration block
            this.lang = this.detectLanguage(fullConfig.languages);
            this.config = fullConfig.languages[this.lang];

            // 1. First, check if any non-essential categories exist with cookies
            this.checkOptionalChoices();

            // 2. Only build the interface if a legitimate tracking choice exists
            if (this.optional_cookie_choice_exists) {
                this.renderStructure();
                this.bindEvents();

                const savedCookie = this.getCookie(this.consentKey);
                if (savedCookie) {
                    const parsedConsent = JSON.parse(decodeURIComponent(savedCookie));
                    this.applyConsent(parsedConsent);
                    this.wrapper.classList.add('is-collapsed');
                    this.syncCheckboxesWithConsent(parsedConsent);
                }
            } else {
                // If only strictly necessary cookies exist, implicitly grant them without showing a UI
                this.applyConsent({ strictly_necessary: true });
            }
        } catch (error) {
            console.error('Failed to initialise cookie consent:', error);
        }
    }

    detectLanguage(availableLanguages) {
        const polylangCookie = this.getCookie('pll_language');
        if (polylangCookie && availableLanguages[polylangCookie]) {
            return polylangCookie;
        }

        const path = window.location.pathname;
        const supportedLocales = ['fr', 'de', 'es', 'it', 'pt', 'nl'];

        for (const locale of supportedLocales) {
            if (path.startsWith(`/${locale}/`) || path === `/${locale}`) {
                if (availableLanguages[locale]) {
                    return locale;
                }
            }
        }

        return this.lang_fallback;
    }

    // New helper method to scan the JSON data before writing HTML
    checkOptionalChoices() {
        for (const [key, details] of Object.entries(this.config.categories)) {
            if (key !== 'strictly_necessary' && details.cookies && details.cookies.length > 0) {
                this.optional_cookie_choice_exists = true;
                break;
            }
        }
    }

    renderStructure() {
        // Create the main wrapper
        this.wrapper = document.createElement('div');
        this.wrapper.className = 'cookie-consent-wrapper';

        // 1. Build the trigger icon button safely
        const iconButton = document.createElement('button');
        iconButton.className = 'cookie-trigger-icon';
        iconButton.setAttribute('aria-label', 'Cookie settings');

        // Static SVG is safe to inject via innerHTML as it contains no dynamic user data
        iconButton.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 1 0 10 10c0-1.5-1-2.5-2.5-2.5a1.5 1.5 0 0 1-1.5-1.5c0-1.5-1-2.5-2.5-2.5A1.5 1.5 0 0 1 12 4a1.5 1.5 0 0 1-1.5-1.5C10.5 2.2 11.2 2 12 2z"/></svg>`;
        this.wrapper.appendChild(iconButton);

        // 2. Build the main popup container
        const popup = document.createElement('div');
        popup.className = 'cookie-popup';

        const title = document.createElement('h3');
        title.textContent = this.config.popup_title;
        popup.appendChild(title);

        const description = document.createElement('p');
        description.textContent = this.config.popup_description;
        popup.appendChild(description);

        // 3. Build options container
        const optionsContainer = document.createElement('div');
        optionsContainer.className = 'cookie-options';

        for (const [key, details] of Object.entries(this.config.categories)) {
            if (!details.cookies || details.cookies.length === 0) continue;

            const categoryBlock = document.createElement('div');
            categoryBlock.className = 'cookie-category-block';

            const label = document.createElement('label');
            const input = document.createElement('type', 'checkbox');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.setAttribute('data-category', key);

            if (key === 'strictly_necessary') {
                checkbox.disabled = true;
                checkbox.checked = true;
            }

            const labelTextContainer = document.createElement('span');
            const strongTitle = document.createElement('strong');
            strongTitle.textContent = details.title;

            labelTextContainer.appendChild(strongTitle);
            labelTextContainer.appendChild(document.createTextNode(` - ${details.description}`));

            label.appendChild(checkbox);
            label.appendChild(labelTextContainer);
            categoryBlock.appendChild(label);

            // Build the details table for cookies
            const detailsElement = document.createElement('details');
            const summary = document.createElement('summary');
            summary.textContent = this.config.cookie_detail;
            detailsElement.appendChild(summary);

            const table = document.createElement('table');
            table.className = 'cookie-details-list';

            const thead = document.createElement('thead');
            const headerRow = document.createElement('tr');

            const thName = document.createElement('th');
            thName.textContent = this.config.cookie;
            const thDuration = document.createElement('th');
            thDuration.className = 'c';
            thDuration.textContent = this.config.duration;
            const thAbout = document.createElement('th');
            thAbout.textContent = this.config.about;

            headerRow.appendChild(thName);
            headerRow.appendChild(thDuration);
            headerRow.appendChild(thAbout);
            thead.appendChild(headerRow);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');
            details.cookies.forEach(cookie => {
                const row = document.createElement('tr');

                const tdName = document.createElement('td');
                tdName.textContent = cookie.name;

                const tdDuration = document.createElement('td');
                tdDuration.className = 'c';
                tdDuration.textContent = cookie.expiry;

                const tdAbout = document.createElement('td');
                tdAbout.textContent = cookie.description;

                row.appendChild(tdName);
                row.appendChild(tdDuration);
                row.appendChild(tdAbout);
                tbody.appendChild(row);
            });

            table.appendChild(tbody);
            detailsElement.appendChild(table);
            categoryBlock.appendChild(detailsElement);
            optionsContainer.appendChild(categoryBlock);
        }

        popup.appendChild(optionsContainer);

        // 4. Build buttons container
        const buttonsContainer = document.createElement('div');
        buttonsContainer.className = 'cookie-buttons';

        const saveBtn = document.createElement('button');
        saveBtn.className = 'save-btn';
        saveBtn.textContent = this.config.btn_save;

        const acceptBtn = document.createElement('button');
        acceptBtn.className = 'accept-btn';
        acceptBtn.textContent = this.config.btn_accept;

        buttonsContainer.appendChild(saveBtn);
        buttonsContainer.appendChild(acceptBtn);
        popup.appendChild(buttonsContainer);

        // Append everything to the wrapper, then to the body
        this.wrapper.appendChild(popup);
        document.body.appendChild(this.wrapper);
    }

    bindEvents() {
        const popup = this.wrapper.querySelector('.cookie-popup');
        const icon = this.wrapper.querySelector('.cookie-trigger-icon');

        popup.querySelector('.accept-btn').addEventListener('click', () => this.saveAllConsent(true));
        popup.querySelector('.save-btn').addEventListener('click', () => this.saveAllConsent(false));

        icon.addEventListener('click', () => {
            this.wrapper.classList.remove('is-collapsed');
        });
    }

    saveAllConsent(acceptAll = false) {
        const consentStatus = {};
        const checkboxes = this.wrapper.querySelectorAll('.cookie-options input');

        checkboxes.forEach(checkbox => {
            const category = checkbox.getAttribute('data-category');
            if (acceptAll) {
                consentStatus[category] = true;
                if (category !== 'strictly_necessary') checkbox.checked = true;
            } else {
                consentStatus[category] = checkbox.checked;
            }
        });

        const cookieValue = encodeURIComponent(JSON.stringify(consentStatus));
        const maxAgeSeconds = 31536000;
        document.cookie = `${this.consentKey}=${cookieValue}; max-age=${maxAgeSeconds}; path=/; SameSite=Lax; Secure`;

        this.applyConsent(consentStatus);
        this.wrapper.classList.add('is-collapsed');
    }

    applyConsent(consentStatus) {
        const scripts = document.querySelectorAll('script[type="text/plain"][data-cookie-category]');

        scripts.forEach(oldScript => {
            const category = oldScript.getAttribute('data-cookie-category');

            if (consentStatus[category]) {
                const newScript = document.createElement('script');

                Array.from(oldScript.attributes).forEach(attr => {
                    if (attr.name !== 'type' && attr.name !== 'data-cookie-category') {
                        newScript.setAttribute(attr.name, attr.value);
                    }
                });

                newScript.innerHTML = oldScript.innerHTML;
                oldScript.parentNode.replaceChild(newScript, oldScript);
            }
        });
    }

    syncCheckboxesWithConsent(consentStatus) {
        const checkboxes = this.wrapper.querySelectorAll('.cookie-options input');
        checkboxes.forEach(checkbox => {
            const category = checkbox.getAttribute('data-category');
            if (consentStatus[category] !== undefined) {
                checkbox.checked = consentStatus[category];
            }
        });
    }

    getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return null;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (typeof cookieConsentSettings !== 'undefined' && cookieConsentSettings.configUrl) {
        new CookieConsent(cookieConsentSettings.configUrl);
    }
});