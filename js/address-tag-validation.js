(function initAddressTagValidation(root, factory) {
  const validation = factory();

  if (typeof module === 'object' && module.exports) {
    module.exports = validation;
  }

  root.PetlioAddressTagValidation = validation;
}(typeof globalThis !== 'undefined' ? globalThis : window, () => {
  const FIELD_ORDER = ['photo', 'size', 'name', 'birthday', 'breed', 'address', 'phone'];
  const SIZE_KEYS = new Set(['small', 'medium', 'large']);
  const MESSAGES = {
    photo: 'Загрузите фотографию питомца',
    size: 'Выберите размер адресника',
    name: 'Укажите имя питомца',
    birthday: 'Укажите дату рождения',
    birthdayInvalid: 'Укажите корректную дату рождения',
    birthdayFuture: 'Дата рождения не может быть в будущем',
    breed: 'Укажите породу питомца',
    address: 'Укажите место жительства',
    phone: 'Укажите телефон для адресника',
    phoneInvalid: 'Укажите корректный телефон (не менее 10 цифр)',
  };

  function normalizeText(value) {
    return String(value ?? '')
      .replace(/\u00a0/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function isMissing(value) {
    const normalized = normalizeText(value).toLocaleLowerCase('ru-RU');

    return normalized === '' || normalized === 'не указано';
  }

  function parseBirthday(value, today = new Date()) {
    const normalized = normalizeText(value);
    const match = /^(\d{2})\.(\d{2})\.(\d{4})$/.exec(normalized);

    if (!match) {
      return { valid: false, future: false };
    }

    const day = Number(match[1]);
    const month = Number(match[2]);
    const year = Number(match[3]);
    const date = new Date(year, month - 1, day);
    const isRealDate = year >= 1
      && date.getFullYear() === year
      && date.getMonth() === month - 1
      && date.getDate() === day;

    if (!isRealDate) {
      return { valid: false, future: false };
    }

    const currentDate = new Date(today.getFullYear(), today.getMonth(), today.getDate());

    return {
      valid: true,
      future: date.getTime() > currentDate.getTime(),
    };
  }

  function isValidPhone(value) {
    const normalized = normalizeText(value);

    if (isMissing(normalized) || !/^[0-9+\s(),-]+$/.test(normalized)) {
      return false;
    }

    return normalized.split(',').every((phone) => {
      const digits = phone.replace(/\D/g, '');

      return digits.length >= 10 && digits.length <= 15;
    });
  }

  function isUploadedPhoto(value) {
    const photo = normalizeText(value);

    return /^data:image\/(?:png|jpe?g);base64,[A-Za-z0-9+/]+={0,2}$/i.test(photo);
  }

  function hasSelectedSize(size, requireExplicitSelection = true) {
    if (!size || !SIZE_KEYS.has(normalizeText(size.key))) {
      return false;
    }

    return !requireExplicitSelection || size.userSelected === true;
  }

  function validate(orderData, options = {}) {
    const pet = orderData?.pet || {};
    const errors = {};
    const birthday = parseBirthday(pet.birthday, options.today || new Date());

    if (!isUploadedPhoto(pet.photo)) {
      errors.photo = MESSAGES.photo;
    }

    if (!hasSelectedSize(orderData?.size, options.requireExplicitSize !== false)) {
      errors.size = MESSAGES.size;
    }

    if (isMissing(pet.name)) {
      errors.name = MESSAGES.name;
    }

    if (isMissing(pet.birthday)) {
      errors.birthday = MESSAGES.birthday;
    } else if (!birthday.valid) {
      errors.birthday = MESSAGES.birthdayInvalid;
    } else if (birthday.future) {
      errors.birthday = MESSAGES.birthdayFuture;
    }

    if (isMissing(pet.breed)) {
      errors.breed = MESSAGES.breed;
    }

    if (isMissing(pet.address)) {
      errors.address = MESSAGES.address;
    }

    if (isMissing(pet.phone)) {
      errors.phone = MESSAGES.phone;
    } else if (!isValidPhone(pet.phone)) {
      errors.phone = MESSAGES.phoneInvalid;
    }

    return {
      valid: Object.keys(errors).length === 0,
      errors,
      firstInvalidField: FIELD_ORDER.find((field) => errors[field]) || null,
    };
  }

  function formatBirthday(value) {
    const digits = String(value ?? '').replace(/\D/g, '').slice(0, 8);

    if (digits.length <= 2) {
      return digits;
    }

    if (digits.length <= 4) {
      return `${digits.slice(0, 2)}.${digits.slice(2)}`;
    }

    return `${digits.slice(0, 2)}.${digits.slice(2, 4)}.${digits.slice(4)}`;
  }

  return {
    FIELD_ORDER,
    MESSAGES,
    formatBirthday,
    hasSelectedSize,
    isMissing,
    isUploadedPhoto,
    isValidPhone,
    normalizeText,
    parseBirthday,
    validate,
  };
}));
