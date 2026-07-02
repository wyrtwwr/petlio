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

  function getSelectedSize() {
    const selectedOption = document.querySelector('.size-option.is-active') || sizeOptions[1] || sizeOptions[0];

    return {
      key: selectedOption?.dataset.size || 'medium',
      title: selectedOption?.querySelector('span')?.textContent.trim() || 'Средний',
      value: selectedOption?.querySelector('small')?.textContent.trim() || '4 x 2,5 см',
      price: '',
    };
  }

  function saveConstructorOrder() {
    const orderData = {
      pet: {
        name: document.querySelector('#pet-name')?.value.trim() || '',
        birthday: document.querySelector('#pet-birthday')?.value.trim() || '',
        breed: document.querySelector('#pet-breed')?.value.trim() || '',
        address: document.querySelector('#pet-address')?.value.trim() || '',
        phone: document.querySelector('#pet-phone')?.value.trim() || '',
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
      saveConstructorOrder();
    });
  });

  passportButton?.addEventListener('click', () => {
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
