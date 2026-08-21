document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('#pet-form');
  const photoInput = document.querySelector('#pet-photo');
  const photoDrop = document.querySelector('.photo-drop');
  const preview = document.querySelector('.tag-preview');
  const previewPhoto = document.querySelector('#preview-photo');
  const secondaryPhotoInput = document.querySelector('#pet-photo-secondary');
  const previewSecondaryPhoto = document.querySelector('#preview-photo-secondary');
  const sizePicker = document.querySelector('.size-picker');
  const sizeOptions = Array.from(document.querySelectorAll('.size-option'));
  const designOptions = Array.from(document.querySelectorAll('.design-option'));
  const passportButton = document.querySelector('#passport-button');
  const birthdayInput = document.querySelector('#pet-birthday');
  const phoneInput = document.querySelector('#pet-phone');
  const sectionNavLinks = Array.from(document.querySelectorAll('.main-nav a[href^="#"]'));
  const validation = window.PetlioAddressTagValidation;
  let validationAttempted = false;

  if (!validation) {
    return;
  }

  const fields = [
    ['name', '#pet-name', '#preview-name', 'Чиж'],
    ['birthday', '#pet-birthday', '#preview-birthday', 'дд.мм.гггг'],
    ['breed', '#pet-breed', '#preview-breed', 'Пудель'],
    ['gender', '#pet-gender', '#preview-gender', 'Муж'],
    ['eyeColor', '#pet-eye-color', '#preview-eye-color', 'Карий'],
    ['furColor', '#pet-fur-color', '#preview-fur-color', 'Рыже-белый'],
    ['address', '#pet-address', '#preview-address', 'г. Москва, ул. Ленина'],
    ['phone', '#pet-phone', '#preview-phone', '+7 (___) ___-__-__'],
  ];
  const controls = {
    photo: photoInput,
    size: sizeOptions[0],
    name: document.querySelector('#pet-name'),
    birthday: birthdayInput,
    breed: document.querySelector('#pet-breed'),
    address: document.querySelector('#pet-address'),
    phone: phoneInput,
  };
  const controlContainers = {
    photo: photoDrop,
    size: sizePicker,
  };

  function readStoredOrder() {
    try {
      return JSON.parse(localStorage.getItem('petlioOrder') || '{}');
    } catch (error) {
      return {};
    }
  }

  function updatePreview(inputSelector, previewSelector, fallback) {
    const input = document.querySelector(inputSelector);
    const output = document.querySelector(previewSelector);

    if (!input || !output) {
      return;
    }

    const render = () => {
      output.textContent = input.value.trim() || fallback;
    };

    render();
    input.addEventListener('input', render);
  }

  const phoneMask = '+7 (___) ___-__-__';
  const phoneValidityMessage = 'Введите один номер: +7 (999) 999-99-99 или два номера через запятую и пробел.';

  function normalizePhoneDigits(value) {
    const digits = String(value || '').replace(/\D/g, '');
    let normalizedDigits = '';
    let index = 0;

    while (index < digits.length && normalizedDigits.length < 20) {
      const rest = digits.slice(index);

      if (rest.length >= 11 && (rest.startsWith('7') || rest.startsWith('8'))) {
        normalizedDigits += rest.slice(1, 11);
        index += 11;
      } else {
        normalizedDigits += rest.slice(0, 20 - normalizedDigits.length);
        break;
      }
    }

    return normalizedDigits.slice(0, 20);
  }

  function formatSinglePhoneDigits(digits) {
    const paddedDigits = digits.padEnd(10, '_');

    return `+7 (${paddedDigits.slice(0, 3)}) ${paddedDigits.slice(3, 6)}-${paddedDigits.slice(6, 8)}-${paddedDigits.slice(8, 10)}`;
  }

  function formatPhoneDigits(digits, forceMask = false) {
    if (!digits && !forceMask) {
      return '';
    }

    const firstPhone = formatSinglePhoneDigits(digits.slice(0, 10));

    if (digits.length <= 10) {
      return firstPhone;
    }

    return `${firstPhone}, ${formatSinglePhoneDigits(digits.slice(10, 20))}`;
  }

  function updatePhoneValidity(input) {
    const value = input.value.trim();
    const isEmpty = value === '' || normalizePhoneDigits(value) === '';
    const isValid = isEmpty || (!value.includes('_') && validation.isValidPhone(value));

    input.setCustomValidity(isValid ? '' : phoneValidityMessage);
  }

  function setPhoneCaretToNextSlot(input) {
    window.requestAnimationFrame(() => {
      const nextSlotIndex = input.value.indexOf('_');
      const caretIndex = nextSlotIndex === -1 ? input.value.length : nextSlotIndex;

      input.setSelectionRange(caretIndex, caretIndex);
    });
  }

  function setPhoneInputDigits(input, digits, forceMask = false) {
    if (!input) {
      return;
    }

    const phoneDigits = normalizePhoneDigits(digits);

    input.dataset.phoneDigits = phoneDigits;
    input.value = formatPhoneDigits(phoneDigits, forceMask);
    updatePhoneValidity(input);
    setPhoneCaretToNextSlot(input);
  }

  function showPhoneMask(input) {
    if (!input || input.value.trim() || input.dataset.phoneDigits) {
      return;
    }

    setPhoneInputDigits(input, '', true);
  }

  function updatePhonePreview() {
    const previewPhone = document.querySelector('#preview-phone');

    if (previewPhone && phoneInput) {
      previewPhone.textContent = (phoneInput.value.trim() || phoneMask).replace(', ', '\n');
    }
  }

  function getSelectedSize() {
    const selectedOption = document.querySelector('.size-option.is-active');

    if (!selectedOption) {
      return null;
    }

    return {
      key: selectedOption.dataset.size,
      title: selectedOption.querySelector('span')?.textContent.trim() || '',
      value: selectedOption.querySelector('small')?.textContent.trim() || '',
      price: '',
      userSelected: true,
    };
  }

  function getSelectedDesign() {
    const selectedOption = document.querySelector('.design-option.is-active');

    return {
      key: selectedOption?.dataset.design || 'classic',
      title: selectedOption?.dataset.title || 'Паспорт питомца',
    };
  }

  function updatePreviewDesign(designKey = 'classic') {
    const normalizedDesign = designOptions.some((option) => option.dataset.design === designKey)
      ? designKey
      : 'classic';

    preview?.setAttribute('data-design', normalizedDesign);

    document.querySelectorAll('[data-design-field]').forEach((field) => {
      field.hidden = field.dataset.designField !== normalizedDesign;
    });
  }

  function updatePreviewSize(sizeKey = 'medium') {
    const normalizedSize = ['small', 'medium', 'large'].includes(sizeKey) ? sizeKey : 'medium';

    preview?.classList.remove('tag-preview--small', 'tag-preview--medium', 'tag-preview--large');
    preview?.classList.add(`tag-preview--${normalizedSize}`);
    preview?.setAttribute('data-size', normalizedSize);
  }

  function phoneValueForStorage() {
    const phoneDigits = phoneInput?.dataset.phoneDigits ?? normalizePhoneDigits(phoneInput?.value);

    if (!phoneInput || phoneDigits === '') {
      return '';
    }

    return phoneInput.value.trim();
  }

  function collectConstructorOrder() {
    const previousOrder = readStoredOrder();
    const selectedSize = getSelectedSize();
    const selectedDesign = getSelectedDesign();
    const orderData = {
      ...previousOrder,
      pet: {
        name: document.querySelector('#pet-name')?.value.trim() || '',
        birthday: birthdayInput?.value.trim() || '',
        breed: document.querySelector('#pet-breed')?.value.trim() || '',
        gender: selectedDesign.key === 'petfolio'
          ? document.querySelector('#pet-gender')?.value.trim() || ''
          : '',
        eyeColor: selectedDesign.key === 'pet-id'
          ? document.querySelector('#pet-eye-color')?.value.trim() || ''
          : '',
        furColor: selectedDesign.key === 'pet-id'
          ? document.querySelector('#pet-fur-color')?.value.trim() || ''
          : '',
        address: document.querySelector('#pet-address')?.value.trim() || '',
        phone: phoneValueForStorage(),
        photo: validation.isUploadedPhoto(previewPhoto?.src) ? previewPhoto.src : '',
        secondaryPhoto: selectedDesign.key === 'pet-id' && validation.isUploadedPhoto(previewSecondaryPhoto?.src)
          ? previewSecondaryPhoto.src
          : '',
      },
      design: selectedDesign,
    };

    delete orderData.checkoutRequestId;

    if (selectedSize) {
      orderData.size = selectedSize;
    } else {
      delete orderData.size;
    }

    return orderData;
  }

  function saveConstructorOrder() {
    const orderData = collectConstructorOrder();

    localStorage.setItem('petlioOrder', JSON.stringify(orderData));
    return orderData;
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
  }

  function renderValidation(result, focusFirst = false) {
    validation.FIELD_ORDER.forEach((field) => {
      setFieldError(field, result.errors[field] || '');
    });

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

  function validateConstructor(focusFirst = false) {
    const result = validation.validate(saveConstructorOrder());

    renderValidation(result, focusFirst);
    return result;
  }

  function revalidateField(field) {
    if (!validationAttempted) {
      return;
    }

    const result = validation.validate(saveConstructorOrder());

    setFieldError(field, result.errors[field] || '');
  }

  function setPhoto(file) {
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

      previewPhoto.src = photo;
      preview.classList.add('has-photo');
      photoInput?.setCustomValidity('');
      saveConstructorOrder();
      setFieldError('photo', '');
      revalidateField('photo');
    });

    reader.readAsDataURL(file);
  }

  function setSecondaryPhoto(file) {
    if (!file || !file.type.startsWith('image/') || file.size > 10 * 1024 * 1024) {
      return;
    }

    const reader = new FileReader();

    reader.addEventListener('load', () => {
      const photo = String(reader.result || '');

      if (!validation.isUploadedPhoto(photo) || !previewSecondaryPhoto) {
        return;
      }

      previewSecondaryPhoto.src = photo;
      preview?.classList.add('has-secondary-photo');
      saveConstructorOrder();
    });

    reader.readAsDataURL(file);
  }

  function restoreConstructorOrder() {
    const storedOrder = readStoredOrder();
    const pet = storedOrder.pet || {};

    fields.forEach(([field, inputSelector]) => {
      const input = document.querySelector(inputSelector);

      if (input && !validation.isMissing(pet[field])) {
        input.value = String(pet[field]).trim();
      }
    });

    if (phoneInput?.value) {
      phoneInput.dataset.phoneDigits = normalizePhoneDigits(phoneInput.value);
      updatePhoneValidity(phoneInput);
    }

    if (validation.isUploadedPhoto(pet.photo)) {
      previewPhoto.src = pet.photo;
      preview.classList.add('has-photo');
    }

    if (validation.isUploadedPhoto(pet.secondaryPhoto)) {
      previewSecondaryPhoto.src = pet.secondaryPhoto;
      preview.classList.add('has-secondary-photo');
    }

    const storedSizeKey = validation.hasSelectedSize(storedOrder.size)
      ? storedOrder.size.key
      : '';

    sizeOptions.forEach((option) => {
      const isActive = option.dataset.size === storedSizeKey;

      option.classList.toggle('is-active', isActive);
      option.setAttribute('aria-pressed', String(isActive));
    });

    updatePreviewSize(storedSizeKey || 'medium');

    const requestedDesignKey = typeof storedOrder.design?.key === 'string'
      ? storedOrder.design.key
      : 'classic';
    const storedDesignKey = designOptions.some((option) => option.dataset.design === requestedDesignKey)
      ? requestedDesignKey
      : 'classic';

    designOptions.forEach((option) => {
      const isActive = option.dataset.design === storedDesignKey;

      option.classList.toggle('is-active', isActive);
      option.setAttribute('aria-pressed', String(isActive));
    });

    updatePreviewDesign(storedDesignKey);
  }

  restoreConstructorOrder();

  fields.forEach(([, inputSelector, previewSelector, fallback]) => {
    updatePreview(inputSelector, previewSelector, fallback);
  });

  fields.forEach(([field, inputSelector]) => {
    const input = document.querySelector(inputSelector);

    if (field === 'birthday') {
      return;
    }

    input?.addEventListener('input', () => {
      saveConstructorOrder();
      revalidateField(field);
    });
  });

  birthdayInput?.addEventListener('input', () => {
    birthdayInput.value = validation.formatBirthday(birthdayInput.value);
    const birthdayPreview = document.querySelector('#preview-birthday');

    if (birthdayPreview) {
      birthdayPreview.textContent = birthdayInput.value.trim() || 'дд.мм.гггг';
    }

    saveConstructorOrder();
    revalidateField('birthday');
  });

  phoneInput?.addEventListener('focus', () => {
    showPhoneMask(phoneInput);
  });

  phoneInput?.addEventListener('click', () => {
    showPhoneMask(phoneInput);
    setPhoneCaretToNextSlot(phoneInput);
  });

  phoneInput?.addEventListener('paste', (event) => {
    event.preventDefault();
    setPhoneInputDigits(phoneInput, normalizePhoneDigits(event.clipboardData?.getData('text') || ''));
    updatePhonePreview();
    saveConstructorOrder();
    revalidateField('phone');
  });

  phoneInput?.addEventListener('beforeinput', (event) => {
    const currentDigits = phoneInput.dataset.phoneDigits ?? normalizePhoneDigits(phoneInput.value);

    if (event.inputType === 'insertFromPaste') {
      event.preventDefault();
      setPhoneInputDigits(phoneInput, normalizePhoneDigits(event.dataTransfer?.getData('text') || event.data || currentDigits));
      updatePhonePreview();
      saveConstructorOrder();
      revalidateField('phone');
      return;
    }

    if (event.inputType.startsWith('insert')) {
      event.preventDefault();

      if (!event.data || !/^\d+$/.test(event.data) || currentDigits.length >= 20) {
        return;
      }

      setPhoneInputDigits(phoneInput, currentDigits + event.data);
      updatePhonePreview();
      saveConstructorOrder();
      revalidateField('phone');
      return;
    }

    if (event.inputType === 'deleteContentBackward' || event.inputType === 'deleteContentForward') {
      event.preventDefault();
      setPhoneInputDigits(phoneInput, currentDigits.slice(0, -1), phoneInput === document.activeElement);
      updatePhonePreview();
      saveConstructorOrder();
      revalidateField('phone');
    }
  });

  phoneInput?.addEventListener('blur', () => {
    const phoneDigits = phoneInput.dataset.phoneDigits ?? normalizePhoneDigits(phoneInput.value);

    if (phoneDigits === '') {
      phoneInput.value = '';
      phoneInput.dataset.phoneDigits = '';
      updatePhoneValidity(phoneInput);
      updatePhonePreview();
      saveConstructorOrder();
      revalidateField('phone');
    }
  });

  photoInput?.addEventListener('change', () => {
    setPhoto(photoInput.files?.[0]);
  });

  secondaryPhotoInput?.addEventListener('change', () => {
    setSecondaryPhoto(secondaryPhotoInput.files?.[0]);
  });

  ['dragenter', 'dragover'].forEach((eventName) => {
    photoDrop?.addEventListener(eventName, (event) => {
      event.preventDefault();
      photoDrop.classList.add('is-dragover');
    });
  });

  ['dragleave', 'drop'].forEach((eventName) => {
    photoDrop?.addEventListener(eventName, (event) => {
      event.preventDefault();
      photoDrop.classList.remove('is-dragover');
    });
  });

  photoDrop?.addEventListener('drop', (event) => {
    setPhoto(event.dataTransfer?.files?.[0]);
  });

  sizeOptions.forEach((option) => {
    option.addEventListener('click', () => {
      sizeOptions.forEach((item) => {
        const isActive = item === option;

        item.classList.toggle('is-active', isActive);
        item.setAttribute('aria-pressed', String(isActive));
      });

      form?.setAttribute('data-size', option.dataset.size);
      updatePreviewSize(option.dataset.size);
      saveConstructorOrder();
      revalidateField('size');
    });
  });

  designOptions.forEach((option) => {
    option.addEventListener('click', () => {
      designOptions.forEach((item) => {
        const isActive = item === option;

        item.classList.toggle('is-active', isActive);
        item.setAttribute('aria-pressed', String(isActive));
      });

      updatePreviewDesign(option.dataset.design);
      saveConstructorOrder();
    });
  });

  passportButton?.addEventListener('click', () => {
    validationAttempted = true;
    const result = validateConstructor(true);

    if (!result.valid) {
      return;
    }

    window.location.href = 'order.html';
  });

  if (sectionNavLinks.length) {
    const sections = sectionNavLinks
      .map((link) => document.querySelector(link.getAttribute('href')))
      .filter(Boolean);

    function setActiveNav(id) {
      sectionNavLinks.forEach((link) => {
        link.classList.toggle('is-active', link.getAttribute('href') === `#${id}`);
      });
    }

    const observer = new IntersectionObserver((entries) => {
      const visibleEntry = entries
        .filter((entry) => entry.isIntersecting)
        .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];

      if (visibleEntry) {
        setActiveNav(visibleEntry.target.id);
      }
    }, {
      rootMargin: '-25% 0px -55% 0px',
      threshold: [0.1, 0.35, 0.6],
    });

    sections.forEach((section) => observer.observe(section));
    setActiveNav(window.location.hash.replace('#', '') || sections[0]?.id);
  }
});
