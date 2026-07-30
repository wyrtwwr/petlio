document.addEventListener('DOMContentLoaded', () => {
  const orderForm = document.querySelector('#order-form');
  const deliveryTypeInputs = Array.from(document.querySelectorAll('input[name="deliveryType"]'));
  const pickupAddressInput = document.querySelector('#pickup-address');
  const submitButton = document.querySelector('.order-submit');
  const submitButtonText = submitButton?.querySelector('span');
  const summaryPhotoInput = document.querySelector('#summary-photo-input');
  const summaryPhotoUpload = document.querySelector('.summary-photo-upload');
  const summaryPhotoAction = document.querySelector('#summary-photo-action');
  const summarySizeInput = document.querySelector('#summary-size');
  const summarySizePicker = document.querySelector('#summary-size-picker');
  const summarySizeButtons = Array.from(document.querySelectorAll('#summary-size-picker [data-size]'));
  const paymentFailureMessage = document.querySelector('#payment-failure-message');
  const addressTagRequiredMessage = document.querySelector('#address-tag-required-message');
  const productPriceOutput = document.querySelector('#order-product-price');
  const deliveryPriceOutput = document.querySelector('#order-delivery-price');
  const totalPriceOutput = document.querySelector('#order-total-price');
  const validation = window.PetlioAddressTagValidation;
  const deliveryAmount = 200;
  let isSubmitting = false;
  let validationAttempted = false;
  let submitButtonLabel = 'Перейти к оплате';

  if (!validation) {
    return;
  }

  const sizeOptions = {
    small: {
      key: 'small',
      title: 'Маленький',
      value: '3 x 2 см',
      price: '1099 ₽',
      amount: 1099,
      userSelected: true,
    },
    medium: {
      key: 'medium',
      title: 'Средний',
      value: '4 x 2,5 см',
      price: '1299 ₽',
      amount: 1299,
      userSelected: true,
    },
    large: {
      key: 'large',
      title: 'Большой',
      value: '5 x 3 см',
      price: '1399 ₽',
      amount: 1399,
      userSelected: true,
    },
  };
  const controls = {
    photo: summaryPhotoInput,
    size: summarySizeButtons[0],
    name: document.querySelector('#summary-pet-name'),
    birthday: document.querySelector('#summary-pet-birthday'),
    breed: document.querySelector('#summary-pet-breed'),
    address: document.querySelector('#summary-pet-address'),
    phone: document.querySelector('#summary-pet-phone'),
  };
  const controlContainers = {
    photo: summaryPhotoUpload,
    size: summarySizePicker,
  };
  const returnParams = new URLSearchParams(window.location.search);
  const hasRobokassaReturnParams = returnParams.has('InvId')
    || returnParams.has('OutSum')
    || returnParams.has('SignatureValue');
  const shouldShowFailureMessage = returnParams.get('payment') === 'failed'
    || returnParams.get('fail') === '1'
    || hasRobokassaReturnParams;

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

  function clearCheckoutRequestId(orderData) {
    delete orderData.checkoutRequestId;
    return orderData;
  }

  function setSummaryValue(selector, value, fallback = 'Не указано') {
    const element = document.querySelector(selector);
    const normalized = validation.isMissing(value) ? '' : String(value).trim();

    if (!element) {
      return;
    }

    if (element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement) {
      element.value = normalized;
      element.placeholder = fallback;
      return;
    }

    element.textContent = normalized || fallback;
  }

  function formatPrice(amount) {
    return `${amount} ₽`;
  }

  function renderPricing(orderData) {
    const selectedSize = validation.hasSelectedSize(orderData.size)
      ? sizeOptions[orderData.size.key]
      : null;
    const productAmount = selectedSize?.amount;
    const totalAmount = productAmount ? productAmount + deliveryAmount : null;

    if (productPriceOutput) {
      productPriceOutput.textContent = productAmount ? formatPrice(productAmount) : '—';
    }

    if (deliveryPriceOutput) {
      deliveryPriceOutput.textContent = formatPrice(deliveryAmount);
    }

    if (totalPriceOutput) {
      totalPriceOutput.textContent = totalAmount ? formatPrice(totalAmount) : '—';
    }

    submitButtonLabel = totalAmount
      ? `Перейти к оплате — ${formatPrice(totalAmount)}`
      : 'Перейти к оплате';

    if (submitButtonText && !isSubmitting) {
      submitButtonText.textContent = submitButtonLabel;
    }
  }

  function renderSummary(orderData) {
    const pet = orderData.pet || {};
    const hasSize = validation.hasSelectedSize(orderData.size);
    const size = hasSize ? orderData.size : {};
    const sizeParts = [size.title, size.value, size.price].filter(Boolean);
    const summaryPhoto = document.querySelector('#summary-photo');
    const summaryPetPhoto = document.querySelector('#summary-pet-photo');
    const hasPhoto = validation.isUploadedPhoto(pet.photo);

    setSummaryValue('#summary-size', sizeParts.join(', '), 'Не указано');
    setSummaryValue('#summary-pet-name', pet.name);
    setSummaryValue('#summary-pet-birthday', pet.birthday, 'дд.мм.гггг');
    setSummaryValue('#summary-pet-breed', pet.breed);
    setSummaryValue('#summary-pet-address', pet.address);
    setSummaryValue('#summary-pet-phone', pet.phone, '+7 (999) 999-99-99');

    summarySizeButtons.forEach((button) => {
      const isActive = hasSize && button.dataset.size === size.key;

      button.classList.toggle('is-active', isActive);
      button.setAttribute('aria-pressed', String(isActive));
    });

    if (summaryPhoto && summaryPetPhoto) {
      summaryPhoto.hidden = !hasPhoto;
      summaryPetPhoto.src = hasPhoto ? pet.photo : '';
    }

    if (summaryPhotoAction) {
      summaryPhotoAction.textContent = hasPhoto ? 'Заменить фото' : 'Загрузить фото';
    }

    renderPricing(orderData);
  }

  function updateSummaryFromInputs(orderData, shouldInvalidateRequest = false) {
    document.querySelectorAll('.summary-list [data-summary-field="pet"]').forEach((input) => {
      const key = input.dataset.summaryKey;

      if (!key) {
        return;
      }

      orderData.pet = {
        ...(orderData.pet || {}),
        [key]: input.value.trim(),
      };
    });

    if (shouldInvalidateRequest) {
      clearCheckoutRequestId(orderData);
    }

    saveOrderData(orderData);
    return orderData;
  }

  function setAddressTagRequiredMessage(isVisible) {
    if (addressTagRequiredMessage) {
      addressTagRequiredMessage.hidden = !isVisible;
    }
  }

  function setFieldError(field, message = '') {
    const errorElement = document.querySelector(`[data-validation-error="${field}"]`);
    const control = controls[field];
    const container = controlContainers[field] || control;
    const hasError = Boolean(message);

    if (errorElement) {
      errorElement.textContent = message;
      errorElement.hidden = !hasError;
    }

    control?.setAttribute('aria-invalid', String(hasError));
    container?.classList.toggle('is-invalid', hasError);

    if (field === 'size') {
      summarySizeInput?.setAttribute('aria-invalid', String(hasError));
    }
  }

  function renderAddressValidation(result, focusFirst = false) {
    validation.FIELD_ORDER.forEach((field) => {
      setFieldError(field, result.errors[field] || '');
    });

    setAddressTagRequiredMessage(!result.valid);

    if (!focusFirst || !result.firstInvalidField) {
      return;
    }

    const field = result.firstInvalidField;
    const control = controls[field];
    const scrollTarget = controlContainers[field] || control;

    scrollTarget?.scrollIntoView({ behavior: 'smooth', block: 'center' });

    window.setTimeout(() => {
      control?.focus({ preventScroll: true });
    }, 250);
  }

  function validateAddressTag(orderData, focusFirst = false) {
    const result = validation.validate(orderData);

    renderAddressValidation(result, focusFirst);
    return result;
  }

  function revalidateField(field) {
    if (!validationAttempted) {
      return;
    }

    const result = validation.validate(readOrderData());

    setFieldError(field, result.errors[field] || '');
    setAddressTagRequiredMessage(!result.valid);
  }

  function attachSummaryEditors() {
    document.querySelectorAll('.summary-list [data-summary-field="pet"]').forEach((input) => {
      input.addEventListener('input', () => {
        if (input.dataset.summaryKey === 'birthday') {
          input.value = validation.formatBirthday(input.value);
        }

        updateSummaryFromInputs(readOrderData(), true);
        revalidateField(input.dataset.summaryKey);
      });
    });

    summarySizeButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const orderData = clearCheckoutRequestId(readOrderData());
        const size = sizeOptions[button.dataset.size];

        if (!size) {
          return;
        }

        orderData.size = size;
        saveOrderData(orderData);
        renderSummary(orderData);
        revalidateField('size');
      });
    });

    summaryPhotoInput?.addEventListener('change', () => {
      const file = summaryPhotoInput.files?.[0];

      if (!file || !file.type.startsWith('image/') || file.size > 10 * 1024 * 1024) {
        setFieldError('photo', validation.MESSAGES.photo);
        return;
      }

      const reader = new FileReader();

      reader.addEventListener('load', () => {
        const photo = String(reader.result || '');

        if (!validation.isUploadedPhoto(photo)) {
          setFieldError('photo', validation.MESSAGES.photo);
          return;
        }

        const orderData = clearCheckoutRequestId(readOrderData());

        orderData.pet = {
          ...(orderData.pet || {}),
          photo,
        };

        saveOrderData(orderData);
        renderSummary(orderData);
        setFieldError('photo', '');
        revalidateField('photo');
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
  }

  function collectFormData() {
    const formData = new FormData(orderForm);

    return {
      customer: {
        name: String(formData.get('customerName') || '').trim(),
        address: String(formData.get('customerAddress') || '').trim(),
        email: String(formData.get('customerEmail') || '').trim(),
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

  function createCheckoutRequestId() {
    if (window.crypto?.randomUUID) {
      return window.crypto.randomUUID();
    }

    const randomPart = Math.random().toString(36).slice(2);

    return `${Date.now().toString(36)}-${randomPart}-${Math.random().toString(36).slice(2)}`;
  }

  function buildPaymentPayload(orderData) {
    return JSON.parse(JSON.stringify(orderData));
  }

  function setSubmitting(nextSubmittingState) {
    isSubmitting = nextSubmittingState;

    if (submitButton) {
      submitButton.disabled = isSubmitting;
    }

    if (submitButtonText) {
      submitButtonText.textContent = isSubmitting ? 'Переходим к оплате...' : submitButtonLabel;
    }
  }

  const initialOrderData = readOrderData();

  renderSummary(initialOrderData);
  updateDeliveryView();
  attachSummaryEditors();

  deliveryTypeInputs.forEach((input) => {
    input.addEventListener('change', updateDeliveryView);
  });

  orderForm?.addEventListener('input', () => {
    const orderData = readOrderData();

    if (orderData.checkoutRequestId) {
      clearCheckoutRequestId(orderData);
      saveOrderData(orderData);
    }
  });

  orderForm?.addEventListener('change', () => {
    const orderData = readOrderData();

    if (orderData.checkoutRequestId) {
      clearCheckoutRequestId(orderData);
      saveOrderData(orderData);
    }
  });

  orderForm?.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (isSubmitting) {
      return;
    }

    if (paymentFailureMessage) {
      paymentFailureMessage.hidden = true;
    }

    const previousOrder = updateSummaryFromInputs(readOrderData());
    const nextOrder = {
      ...previousOrder,
      ...collectFormData(),
      checkoutRequestId: previousOrder.checkoutRequestId || createCheckoutRequestId(),
      submittedAt: new Date().toISOString(),
    };

    saveOrderData(nextOrder);
    validationAttempted = true;

    const addressTagResult = validateAddressTag(nextOrder, true);

    if (!addressTagResult.valid) {
      return;
    }

    if (!orderForm.checkValidity()) {
      orderForm.reportValidity();
      return;
    }

    setAddressTagRequiredMessage(false);
    setSubmitting(true);
    let redirectStarted = false;

    try {
      const response = await fetch('backend/create-payment.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Idempotency-Key': nextOrder.checkoutRequestId,
        },
        body: JSON.stringify(buildPaymentPayload(nextOrder)),
      });
      const result = await response.json().catch(() => null);
      const message = result && typeof result.message === 'string' ? result.message : '';

      if (!response.ok) {
        if (result?.errors && typeof result.errors === 'object') {
          const serverErrors = {};

          validation.FIELD_ORDER.forEach((field) => {
            if (typeof result.errors[field] === 'string') {
              serverErrors[field] = result.errors[field];
            }
          });

          if (Object.keys(serverErrors).length > 0) {
            renderAddressValidation({
              valid: false,
              errors: serverErrors,
              firstInvalidField: validation.FIELD_ORDER.find((field) => serverErrors[field]) || null,
            }, true);
          }
        }

        throw new Error(message || 'Не удалось создать платёж.');
      }

      if (!result || typeof result !== 'object') {
        throw new Error('Сервер вернул некорректный ответ.');
      }

      const paymentUrl = typeof result.payment_url === 'string' && result.payment_url.trim()
        ? result.payment_url.trim()
        : String(result.confirmation_url || '').trim();

      if (!paymentUrl) {
        throw new Error('Платёж создан без ссылки на оплату.');
      }

      try {
        if (typeof result.order_uid === 'string' && result.order_uid.trim()) {
          sessionStorage.setItem('petlioLastOrderUid', result.order_uid.trim());
        }
      } catch (storageError) {
        // Проверка статуса необязательна и не должна мешать переходу к оплате.
      }

      redirectStarted = true;
      window.location.assign(paymentUrl);
    } catch (error) {
      alert(error.message || 'Не удалось перейти к оплате. Попробуйте ещё раз.');
    } finally {
      if (!redirectStarted) {
        setSubmitting(false);
      }
    }
  });
});
