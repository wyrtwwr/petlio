document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('#pet-form');
  const photoInput = document.querySelector('#pet-photo');
  const photoDrop = document.querySelector('.photo-drop');
  const preview = document.querySelector('.tag-preview');
  const previewPhoto = document.querySelector('#preview-photo');
  const sizeOptions = Array.from(document.querySelectorAll('.size-option'));
  const passportButton = document.querySelector('#passport-button');
  const sectionNavLinks = Array.from(document.querySelectorAll('.main-nav a[href^="#"]'));

  const fields = [
    ['#pet-name', '#preview-name', 'Чиж'],
    ['#pet-birthday', '#preview-birthday', 'дд.мм.гггг'],
    ['#pet-breed', '#preview-breed', 'Пудель'],
    ['#pet-address', '#preview-address', 'г. Москва, ул. Ленина'],
    ['#pet-phone', '#preview-phone', '+7 (___) ___-__-__'],
  ];

  function updatePreview(inputSelector, previewSelector, fallback) {
    const input = document.querySelector(inputSelector);
    const output = document.querySelector(previewSelector);

    if (!input || !output) {
      return;
    }

    output.textContent = input.value.trim() || fallback;
    input.addEventListener('input', () => {
      output.textContent = input.value.trim() || fallback;
    });
  }

  function formatBirthdayInput(input) {
    if (!input) {
      return;
    }

    const digits = input.value.replace(/\D/g, '').slice(0, 8);

    let formatted = digits;

    if (digits.length > 2) {
      formatted = `${digits.slice(0, 2)}.${digits.slice(2)}`;
    }

    if (digits.length > 4) {
      formatted = `${digits.slice(0, 2)}.${digits.slice(2, 4)}.${digits.slice(4)}`;
    }

    input.value = formatted;
  }

  const phoneMask = '+7 (___) ___-__-__';
  const phonePattern = /^\+7 \(\d{3}\) \d{3}-\d{2}-\d{2}(, \+7 \(\d{3}\) \d{3}-\d{2}-\d{2})?$/;
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

  function isValidPhoneValue(value) {
    return phonePattern.test(value);
  }

  function updatePhoneValidity(input) {
    const value = input.value.trim();
    const isEmpty = value === '';
    const isCompleteMask = !value.includes('_');
    const isValid = isEmpty || (isCompleteMask && isValidPhoneValue(value));

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

  function formatPhoneInput(input) {
    if (!input) {
      return;
    }

    setPhoneInputDigits(input, normalizePhoneDigits(input.value), input.value.trim() !== '');
  }

  function updatePhonePreview() {
    const previewPhone = document.querySelector('#preview-phone');

    if (previewPhone && phoneInput) {
      previewPhone.textContent = (phoneInput.value.trim() || phoneMask).replace(', ', '\n');
    }
  }

  function setPhoto(file) {
    if (!file || !file.type.startsWith('image/')) {
      return;
    }

    const reader = new FileReader();

    reader.addEventListener('load', () => {
      previewPhoto.src = reader.result;
      preview.classList.add('has-photo');
      saveConstructorOrder();
    });

    reader.readAsDataURL(file);
  }

  function getSelectedSize() {
    const selectedOption = document.querySelector('.size-option.is-active') || sizeOptions[1] || sizeOptions[0];

    return {
      key: selectedOption?.dataset.size || 'medium',
      title: selectedOption?.querySelector('span')?.textContent.trim() || 'Средний',
      value: selectedOption?.querySelector('small')?.textContent.trim() || '4 x 2,5 см',
      price: '',
    };
  }

  function updatePreviewSize(sizeKey = 'medium') {
    const normalizedSize = sizeKey || 'medium';

    preview?.classList.remove('tag-preview--small', 'tag-preview--medium', 'tag-preview--large');
    preview?.classList.add(`tag-preview--${normalizedSize}`);
    preview?.setAttribute('data-size', normalizedSize);
  }

  function saveConstructorOrder() {
    const orderData = {
      pet: {
        name: document.querySelector('#pet-name')?.value.trim() || '',
        birthday: document.querySelector('#pet-birthday')?.value.trim() || '',
        breed: document.querySelector('#pet-breed')?.value.trim() || '',
        address: document.querySelector('#pet-address')?.value.trim() || '',
        phone: document.querySelector('#pet-phone')?.value.trim() || '',
        photo: previewPhoto?.src?.startsWith('data:image/') ? previewPhoto.src : '',
      },
      size: getSelectedSize(),
    };

    localStorage.setItem('petlioOrder', JSON.stringify(orderData));
  }

  fields.forEach(([inputSelector, previewSelector, fallback]) => {
    updatePreview(inputSelector, previewSelector, fallback);
  });

  fields.forEach(([inputSelector]) => {
    document.querySelector(inputSelector)?.addEventListener('input', saveConstructorOrder);
  });

  const birthdayInput = document.querySelector('#pet-birthday');
  const phoneInput = document.querySelector('#pet-phone');

  birthdayInput?.addEventListener('input', () => {
    formatBirthdayInput(birthdayInput);
    saveConstructorOrder();
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
  });

  phoneInput?.addEventListener('beforeinput', (event) => {
    const currentDigits = phoneInput.dataset.phoneDigits ?? normalizePhoneDigits(phoneInput.value);

    if (event.inputType === 'insertFromPaste') {
      event.preventDefault();
      setPhoneInputDigits(phoneInput, normalizePhoneDigits(event.dataTransfer?.getData('text') || event.data || currentDigits));
      updatePhonePreview();
      saveConstructorOrder();
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
      return;
    }

    if (event.inputType === 'deleteContentBackward' || event.inputType === 'deleteContentForward') {
      event.preventDefault();
      setPhoneInputDigits(phoneInput, currentDigits.slice(0, -1), phoneInput === document.activeElement);
      updatePhonePreview();
      saveConstructorOrder();
    }
  });

  phoneInput?.addEventListener('input', () => {
    formatPhoneInput(phoneInput);
    setPhoneCaretToNextSlot(phoneInput);
    updatePhonePreview();
    saveConstructorOrder();
  });

  updatePreviewSize(document.querySelector('.size-option.is-active')?.dataset.size || 'medium');

  photoInput?.addEventListener('change', () => {
    setPhoto(photoInput.files[0]);
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
    const file = event.dataTransfer.files[0];
    setPhoto(file);
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
    });
  });

  passportButton?.addEventListener('click', () => {
    if (form && !form.reportValidity()) {
      return;
    }

    saveConstructorOrder();
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
