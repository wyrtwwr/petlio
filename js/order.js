document.addEventListener('DOMContentLoaded', () => {
  const orderForm = document.querySelector('#order-form');
  const deliveryTypeInputs = Array.from(document.querySelectorAll('input[name="deliveryType"]'));
  const customerPhoneInput = document.querySelector('#customer-phone');
  const pickupAddressInput = document.querySelector('#pickup-address');
  const privacyConsentInput = document.querySelector('#privacy-consent');
  const submitButton = document.querySelector('.order-submit');
  const submitButtonText = submitButton?.querySelector('span');

  function readOrderData() {
    try {
      return JSON.parse(localStorage.getItem('petlioOrder') || '{}');
    } catch (error) {
      return {};
    }
  }

  function saveOrderData(orderData) {
    localStorage.setItem('petlioOrder', JSON.stringify(orderData));
  }

  function textOrFallback(value, fallback = 'Не указано') {
    return value && String(value).trim() ? value : fallback;
  }

  function setSummaryValue(selector, value, fallback = 'Не указано') {
    const element = document.querySelector(selector);

    if (!element) {
      return;
    }

    if (element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement) {
      element.value = value && String(value).trim() ? String(value).trim() : '';
      element.placeholder = fallback;
      return;
    }

    element.textContent = textOrFallback(value, fallback);
  }

  function renderSummary(orderData) {
    const size = orderData.size || {};
    const pet = orderData.pet || {};
    const sizeParts = [size.title, size.value, size.price].filter(Boolean);

    setSummaryValue('#summary-size', sizeParts.join(', ') || 'Средний, 4 x 2,5 см');
    setSummaryValue('#summary-pet-name', pet.name);
    setSummaryValue('#summary-pet-birthday', pet.birthday);
    setSummaryValue('#summary-pet-breed', pet.breed);
    setSummaryValue('#summary-pet-address', pet.address);
    setSummaryValue('#summary-pet-phone', pet.phone);

    if (customerPhoneInput) {
      customerPhoneInput.value = pet.phone ? String(pet.phone).trim() : customerPhoneInput.value;
    }
  }

  function updateSummaryFromInputs(orderData) {
    const summaryInputs = Array.from(document.querySelectorAll('.summary-list [data-summary-field]'));

    summaryInputs.forEach((input) => {
      const field = input.dataset.summaryField;
      const key = input.dataset.summaryKey;

      if (!field || !key) {
        return;
      }

      if (field === 'size') {
        orderData.size = {
          ...(orderData.size || {}),
          [key]: input.value.trim(),
        };
        return;
      }

      if (field === 'pet') {
        orderData.pet = {
          ...(orderData.pet || {}),
          [key]: input.value.trim(),
        };
      }
    });

    if (customerPhoneInput && orderData.pet?.phone) {
      customerPhoneInput.value = String(orderData.pet.phone).trim();
    }

    saveOrderData(orderData);
    return orderData;
  }

  function attachSummaryEditors() {
    document.querySelectorAll('.summary-list [data-summary-field]').forEach((input) => {
      input.addEventListener('input', () => {
        const orderData = updateSummaryFromInputs(readOrderData());
        renderSummary(orderData);
      });
    });
  }

  function updateDeliveryView() {
    const selectedType = document.querySelector('input[name="deliveryType"]:checked')?.value || 'standard';
    orderForm?.setAttribute('data-delivery', selectedType);

    if (pickupAddressInput) {
      pickupAddressInput.required = selectedType === 'standard';
    }

    updateSubmitState();
  }

  function updateSubmitState() {
    if (!orderForm || !submitButton) {
      return;
    }

    submitButton.disabled = !orderForm.checkValidity() || !privacyConsentInput?.checked;
  }

  function collectFormData() {
    const formData = new FormData(orderForm);

    return {
      customer: {
        name: String(formData.get('customerName') || '').trim(),
        address: String(formData.get('customerAddress') || '').trim(),
        phone: String(formData.get('customerPhone') || '').trim(),
      },
      delivery: {
        type: String(formData.get('deliveryType') || 'standard'),
        service: String(formData.get('deliveryService') || ''),
        pickupAddress: String(formData.get('pickupAddress') || '').trim(),
      },
      consent: {
        privacyPolicy: formData.get('privacyConsent') === 'on',
      },
    };
  }

  const initialOrderData = readOrderData();
  renderSummary(initialOrderData);
  updateDeliveryView();
  attachSummaryEditors();

  deliveryTypeInputs.forEach((input) => {
    input.addEventListener('change', updateDeliveryView);
  });

  orderForm?.addEventListener('input', updateSubmitState);
  orderForm?.addEventListener('change', updateSubmitState);
  updateSubmitState();

  function setSubmitting(isSubmitting) {
    if (!submitButton) {
      return;
    }

    submitButton.disabled = isSubmitting || !orderForm.checkValidity() || !privacyConsentInput?.checked;

    if (submitButtonText) {
      submitButtonText.textContent = isSubmitting ? 'Отправляем...' : 'Подтвердить заказ';
    }
  }

  orderForm?.addEventListener('submit', async (event) => {
    event.preventDefault();

    const previousOrder = readOrderData();
    const nextOrder = {
      ...previousOrder,
      ...collectFormData(),
      submittedAt: new Date().toISOString(),
    };

    saveOrderData(nextOrder);

    setSubmitting(true);

    try {
      const response = await fetch('backend/order.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(nextOrder),
      });
      const result = await response.json().catch(() => ({}));

      if (!response.ok) {
        throw new Error(result.message || 'Не удалось отправить заявку.');
      }

      orderForm.classList.add('is-submitted');
      alert(result.message || 'Заявка отправлена. Мы свяжемся с вами для подтверждения.');
    } catch (error) {
      alert(error.message || 'Не удалось отправить заявку. Попробуйте еще раз.');
    } finally {
      setSubmitting(false);
      updateSubmitState();
    }
  });
});
