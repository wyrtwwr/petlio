document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('#pet-form');
  const photoInput = document.querySelector('#pet-photo');
  const photoDrop = document.querySelector('.photo-drop');
  const preview = document.querySelector('.tag-preview');
  const previewPhoto = document.querySelector('#preview-photo');
  const sizeOptions = Array.from(document.querySelectorAll('.size-option'));

  const fields = [
    ['#pet-name', '#preview-name', 'Чиж'],
    ['#pet-birthday', '#preview-birthday', 'дд.мм.гггг'],
    ['#pet-breed', '#preview-breed', 'Пудель'],
    ['#pet-address', '#preview-address', 'г. Москва, ул. Ленина'],
    ['#pet-phone', '#preview-phone', '+7 (___) ___ __ __'],
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

  function setPhoto(file) {
    if (!file || !file.type.startsWith('image/')) {
      return;
    }

    const reader = new FileReader();

    reader.addEventListener('load', () => {
      previewPhoto.src = reader.result;
      preview.classList.add('has-photo');
    });

    reader.readAsDataURL(file);
  }

  fields.forEach(([inputSelector, previewSelector, fallback]) => {
    updatePreview(inputSelector, previewSelector, fallback);
  });

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
    });
  });
});
