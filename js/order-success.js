document.addEventListener('DOMContentLoaded', () => {
  const title = document.querySelector('#payment-status-title');
  const text = document.querySelector('#payment-status-text');
  const params = new URLSearchParams(window.location.search);

  function readOrderUid() {
    const fromUrl = String(params.get('order') || '').trim();

    if (/^[a-f0-9]{32}$/.test(fromUrl)) {
      return fromUrl;
    }

    try {
      const fromStorage = String(sessionStorage.getItem('petlioLastOrderUid') || '').trim();
      return /^[a-f0-9]{32}$/.test(fromStorage) ? fromStorage : '';
    } catch (error) {
      return '';
    }
  }

  function setStatus(nextTitle, nextText) {
    if (title) {
      title.textContent = nextTitle;
    }

    if (text) {
      text.textContent = nextText;
    }
  }

  async function pollPaymentStatus(orderUid) {
    for (let attempt = 0; attempt < 10; attempt += 1) {
      try {
        const response = await fetch(`backend/order-status.php?order=${encodeURIComponent(orderUid)}`, {
          headers: {
            Accept: 'application/json',
          },
        });
        const result = await response.json().catch(() => null);

        if (response.ok && result?.status === 'paid') {
          setStatus(
            'Оплата подтверждена',
            'Заказ принят. Данные уже переданы владельцу сайта или будут отправлены ближайшей повторной попыткой.'
          );
          return;
        }
      } catch (error) {
        // Keep the user on the waiting screen; Robokassa Result URL remains authoritative.
      }

      await new Promise((resolve) => {
        setTimeout(resolve, 2500);
      });
    }

    setStatus(
      'Подтверждение еще не пришло',
      'Если деньги списались, заказ все равно подтвердится через серверное уведомление Robokassa. Обновите страницу чуть позже.'
    );
  }

  const orderUid = readOrderUid();

  if (!orderUid) {
    setStatus(
      'Ожидаем уведомление Robokassa',
      'Эта страница не отмечает заказ оплаченным. Статус обновится на сервере после Result URL от Robokassa.'
    );
    return;
  }

  pollPaymentStatus(orderUid);
});
