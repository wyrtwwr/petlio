(function () {
  const STORAGE_KEY = 'petlioCookieConsent';

  function getStoredConsent() {
    try {
      return localStorage.getItem(STORAGE_KEY);
    } catch (error) {
      return null;
    }
  }

  function saveConsent(value) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({
        value,
        acceptedAt: new Date().toISOString(),
      }));
    } catch (error) {
      return;
    }
  }

  if (getStoredConsent()) {
    return;
  }

  function createBanner() {
    const banner = document.createElement('section');
    banner.className = 'cookie-consent';
    banner.setAttribute('aria-label', 'Уведомление о cookie');
    banner.innerHTML = `
      <h2 class="cookie-consent__title">Cookie-файлы</h2>
      <p class="cookie-consent__text">
        Мы используем обязательные cookie для работы сайта и оформления заказа.
        Аналитические cookie помогают нам улучшать сервис.
      </p>
      <div class="cookie-consent__actions">
        <button class="cookie-consent__button cookie-consent__button--primary" type="button" data-cookie-choice="all">
          Принять все
        </button>
        <button class="cookie-consent__button cookie-consent__button--secondary" type="button" data-cookie-choice="necessary">
          Только обязательные
        </button>
        <a class="cookie-consent__link" href="cookie-consent.html">Подробнее</a>
      </div>
    `;

    banner.addEventListener('click', (event) => {
      const button = event.target.closest('[data-cookie-choice]');

      if (!button) {
        return;
      }

      saveConsent(button.dataset.cookieChoice);
      banner.hidden = true;
    });

    document.body.appendChild(banner);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', createBanner);
    return;
  }

  createBanner();
})();
