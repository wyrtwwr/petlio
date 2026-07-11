document.addEventListener('DOMContentLoaded', () => {
  const orderForm = document.querySelector('#order-form');
  const deliveryTypeInputs = Array.from(document.querySelectorAll('input[name="deliveryType"]'));
  const customerPhoneInput = document.querySelector('#customer-phone');
  const pickupAddressInput = document.querySelector('#pickup-address');
  const privacyConsentInput = document.querySelector('#privacy-consent');
  const submitButton = document.querySelector('.order-submit');
  const submitButtonText = submitButton?.querySelector('span');
  const summaryPhotoInput = document.querySelector('#summary-photo-input');
  const summarySizeButtons = Array.from(document.querySelectorAll('#summary-size-picker [data-size]'));
  const paymentFailureMessage = document.querySelector('#payment-failure-message');
  let isSubmitting = false;

  const sizeOptions = {
    small: {
      key: 'small',
      title: 'Маленький',
      value: '3 x 2 см',
      price: '1099 ₽',
    },
    medium: {
      key: 'medium',
      title: 'Средний',
      value: '4 x 2,5 см',
      price: '1299 ₽',
    },
    large: {
      key: 'large',
      title: 'Большой',
      value: '5 x 3 см',
      price: '1399 ₽',
    },
  };

  const returnParams = new URLSearchParams(window.location.search);
  const hasRobokassaReturnParams = returnParams.has('InvId') || returnParams.has('OutSum') || returnParams.has('SignatureValue');
  const shouldShowFailureMessage = returnParams.get('payment') === 'failed' || returnParams.get('fail') === '1' || hasRobokassaReturnParams;

  if (paymentFailureMessage && shouldShowFailureMessage) {
    paymentFailureMessage.hidden = false;
  }

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
    const summaryPhoto = document.querySelector('#summary-photo');
    const summaryPetPhoto = document.querySelector('#summary-pet-photo');

    setSummaryValue('#summary-size', sizeParts.join(', ') || 'Средний, 4 x 2,5 см');
    setSummaryValue('#summary-pet-name', pet.name);
    setSummaryValue('#summary-pet-birthday', pet.birthday);
    setSummaryValue('#summary-pet-breed', pet.breed);
    setSummaryValue('#summary-pet-address', pet.address);
    setSummaryValue('#summary-pet-phone', pet.phone);

    summarySizeButtons.forEach((button) => {
      const isActive = button.dataset.size === (size.key || 'medium');

      button.classList.toggle('is-active', isActive);
      button.setAttribute('aria-pressed', String(isActive));
    });

    if (summaryPhoto && summaryPetPhoto) {
      const photo = typeof pet.photo === 'string' && pet.photo.startsWith('data:image/') ? pet.photo : '';

      summaryPhoto.hidden = !photo;
      summaryPetPhoto.src = photo;
    }

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

    summarySizeButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const orderData = readOrderData();
        const size = sizeOptions[button.dataset.size] || sizeOptions.medium;

        orderData.size = size;
        saveOrderData(orderData);
        renderSummary(orderData);
      });
    });

    summaryPhotoInput?.addEventListener('change', () => {
      const file = summaryPhotoInput.files?.[0];

      if (!file || !file.type.startsWith('image/')) {
        return;
      }

      const reader = new FileReader();

      reader.addEventListener('load', () => {
        const orderData = readOrderData();

        orderData.pet = {
          ...(orderData.pet || {}),
          photo: String(reader.result || ''),
        };

        saveOrderData(orderData);
        renderSummary(orderData);
      });

      reader.readAsDataURL(file);
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

    submitButton.disabled = isSubmitting || !orderForm.checkValidity() || !privacyConsentInput?.checked;
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

  function buildPaymentPayload(orderData) {
    const payload = JSON.parse(JSON.stringify(orderData));

    if (payload.pet?.photo) {
      delete payload.pet.photo;
    }

    return payload;
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

  function setSubmitting(nextSubmittingState) {
    isSubmitting = nextSubmittingState;

    if (!submitButton) {
      return;
    }

    submitButton.disabled = isSubmitting || !orderForm.checkValidity() || !privacyConsentInput?.checked;

    if (submitButtonText) {
      submitButtonText.textContent = isSubmitting ? 'Переходим к оплате...' : 'Перейти к оплате';
    }
  }

  orderForm?.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (isSubmitting) {
      return;
    }

    if (paymentFailureMessage) {
      paymentFailureMessage.hidden = true;
    }

    const previousOrder = readOrderData();
    const selectedSizeKey = summarySizeButtons.find((button) => button.classList.contains('is-active'))?.dataset.size || previousOrder.size?.key || 'medium';
    const selectedSize = sizeOptions[selectedSizeKey] || sizeOptions.medium;
    const nextOrder = {
      ...previousOrder,
      ...collectFormData(),
      size: selectedSize,
      submittedAt: new Date().toISOString(),
    };

    saveOrderData(nextOrder);

    setSubmitting(true);

    try {
      const response = await fetch('backend/create-payment.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(buildPaymentPayload(nextOrder)),
      });
      const result = await response.json().catch(() => null);
      const message = result && typeof result.message === 'string' ? result.message : '';

      if (!response.ok) {
        throw new Error(message || 'Не удалось создать платеж.');
      }

      if (!result || typeof result !== 'object') {
        throw new Error('Сервер вернул некорректный ответ.');
      }

      const paymentUrl = typeof result.payment_url === 'string' && result.payment_url.trim()
        ? result.payment_url.trim()
        : String(result.confirmation_url || '').trim();

      if (!paymentUrl) {
        throw new Error('Платеж создан без ссылки на оплату.');
      }

      try {
        if (typeof result.order_uid === 'string' && result.order_uid.trim()) {
          sessionStorage.setItem('petlioLastOrderUid', result.order_uid.trim());
        }
      } catch (storageError) {
        // Status polling is optional; payment redirect must not depend on browser storage.
      }

      window.location.href = paymentUrl;
      return;

    } catch (error) {
      alert(error.message || 'Не удалось перейти к оплате. Попробуйте еще раз.');
    } finally {
      setSubmitting(false);
      updateSubmitState();
    }
  });
});
