document.addEventListener('DOMContentLoaded', () => {
  console.log('Address Shop loaded');

  const sizeCards = Array.from(document.querySelectorAll('.size-card'));
  const orderButton = document.querySelector('.btn_secondary');
  const sectionNavLinks = Array.from(document.querySelectorAll('.main-nav a[href^="#"]'));

  function getSizeData(card) {
    if (!card) {
      return {
        key: 'medium',
        title: 'Средний',
        value: '4 × 2,5 см',
        price: '1299 ₽',
      };
    }

    return {
      key: card.dataset.size || 'medium',
      title: card.dataset.sizeTitle || card.querySelector('h3')?.textContent.trim() || 'Средний',
      value: card.dataset.sizeValue || card.querySelector('.size-card__size')?.textContent.trim() || '4 × 2,5 см',
      price: card.dataset.sizePrice || card.querySelector('.size-card__price')?.textContent.trim() || '1299 ₽',
    };
  }

  function saveSelectedSize() {
    const selectedCard = document.querySelector('.size-card--medium') || sizeCards[1] || sizeCards[0];
    const previousOrder = JSON.parse(localStorage.getItem('petlioOrder') || '{}');
    const nextOrder = {
      ...previousOrder,
      size: getSizeData(selectedCard),
    };

    localStorage.setItem('petlioOrder', JSON.stringify(nextOrder));
  }

  function keepMediumSelected() {
    const mediumCard = document.querySelector('.size-card--medium') || sizeCards[1] || sizeCards[0];

    sizeCards.forEach((item) => {
      const isActive = item === mediumCard;
      item.classList.toggle('is-selected', isActive);
      item.setAttribute('aria-pressed', String(isActive));
    });

    saveSelectedSize();
  }

  sizeCards.forEach((card) => {
    card.addEventListener('click', (event) => {
      if (event.target.closest('.size-card__wish')) {
        return;
      }

      keepMediumSelected();
    });

    card.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        keepMediumSelected();
      }
    });
  });

  keepMediumSelected();
  saveSelectedSize();
  orderButton?.addEventListener('click', saveSelectedSize);

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
