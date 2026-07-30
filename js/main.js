document.addEventListener('DOMContentLoaded', () => {
  console.log('Address Shop loaded');

  const sizeCards = Array.from(document.querySelectorAll('.size-card'));
  const sectionNavLinks = Array.from(document.querySelectorAll('.main-nav a[href^="#"]'));
  const reviewsCarousel = document.querySelector('[data-reviews-carousel]');

  function getSizeData(card) {
    if (!card) {
      return null;
    }

    return {
      key: card.dataset.size || '',
      title: card.dataset.sizeTitle || card.querySelector('h3')?.textContent.trim() || '',
      value: card.dataset.sizeValue || card.querySelector('.size-card__size')?.textContent.trim() || '',
      price: card.dataset.sizePrice || card.querySelector('.size-card__price')?.textContent.trim() || '',
      userSelected: true,
    };
  }

  function readOrderData() {
    try {
      return JSON.parse(localStorage.getItem('petlioOrder') || '{}');
    } catch (error) {
      return {};
    }
  }

  function selectSize(selectedCard, shouldSave = true) {
    const previousOrder = readOrderData();
    const nextOrder = {
      ...previousOrder,
      size: getSizeData(selectedCard),
    };

    sizeCards.forEach((item) => {
      const isActive = item === selectedCard;

      item.classList.toggle('is-selected', isActive);
      item.setAttribute('aria-pressed', String(isActive));
    });

    if (shouldSave && nextOrder.size) {
      delete nextOrder.checkoutRequestId;
      localStorage.setItem('petlioOrder', JSON.stringify(nextOrder));
    }
  }

  sizeCards.forEach((card) => {
    card.addEventListener('click', (event) => {
      if (event.target.closest('.size-card__wish')) {
        return;
      }

      selectSize(card);
    });

    card.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        selectSize(card);
      }
    });
  });

  const storedSizeKey = readOrderData().size?.userSelected === true
    ? readOrderData().size?.key
    : '';
  const storedSizeCard = sizeCards.find((card) => card.dataset.size === storedSizeKey);

  selectSize(storedSizeCard || null, false);

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

  if (reviewsCarousel) {
    const viewport = reviewsCarousel.querySelector('.client-reviews__viewport');
    const track = reviewsCarousel.querySelector('.client-reviews__track');
    const cards = Array.from(reviewsCarousel.querySelectorAll('.review-card'));
    const previousButton = document.querySelector('.client-reviews__arrow--prev');
    const nextButton = document.querySelector('.client-reviews__arrow--next');
    const dotsContainer = reviewsCarousel.querySelector('.client-reviews__dots');
    const status = reviewsCarousel.querySelector('.client-reviews__status');
    let currentIndex = 0;
    let visibleCount = 1;
    let touchStartX = null;
    let resizeFrame = null;

    function getVisibleCount() {
      if (!viewport || !track || !cards[0]) {
        return 1;
      }

      const gap = Number.parseFloat(window.getComputedStyle(track).columnGap) || 0;
      const cardWidth = cards[0].getBoundingClientRect().width;

      if (cardWidth <= 0) {
        return 1;
      }

      return Math.max(
        1,
        Math.min(cards.length, Math.round((viewport.clientWidth + gap) / (cardWidth + gap))),
      );
    }

    function getMaxIndex() {
      return Math.max(0, cards.length - visibleCount);
    }

    function renderDots() {
      if (!dotsContainer) {
        return;
      }

      dotsContainer.replaceChildren();

      for (let index = 0; index <= getMaxIndex(); index += 1) {
        const dot = document.createElement('button');

        dot.className = 'client-reviews__dot';
        dot.type = 'button';
        dot.setAttribute('aria-label', `Показать отзывы, начиная с ${index + 1}`);
        dot.addEventListener('click', () => {
          currentIndex = index;
          updateCarousel(true);
        });
        dotsContainer.append(dot);
      }
    }

    function updateCardAccessibility() {
      cards.forEach((card, index) => {
        const isVisible = index >= currentIndex && index < currentIndex + visibleCount;
        const moreButton = card.querySelector('.review-card__more');
        const text = card.querySelector('.review-card__text--collapsed');

        card.setAttribute('aria-hidden', String(!isVisible));

        if (moreButton) {
          moreButton.tabIndex = isVisible ? 0 : -1;
        }

        if (!isVisible && moreButton && text) {
          text.classList.remove('is-expanded');
          moreButton.setAttribute('aria-expanded', 'false');
          moreButton.textContent = 'Читать полностью';
        }
      });
    }

    function updateCarousel(announce = false) {
      if (!track || !cards.length) {
        return;
      }

      currentIndex = Math.max(0, Math.min(currentIndex, getMaxIndex()));
      const offset = cards[currentIndex]?.offsetLeft || 0;

      track.style.transform = `translate3d(${-offset}px, 0, 0)`;
      dotsContainer?.querySelectorAll('.client-reviews__dot').forEach((dot, index) => {
        const isActive = index === currentIndex;

        dot.classList.toggle('is-active', isActive);
        dot.setAttribute('aria-current', isActive ? 'true' : 'false');
      });
      updateCardAccessibility();

      if (status) {
        const firstVisible = currentIndex + 1;
        const lastVisible = Math.min(cards.length, currentIndex + visibleCount);

        status.textContent = announce
          ? `Показаны отзывы ${firstVisible}–${lastVisible} из ${cards.length}`
          : '';
      }
    }

    function moveCarousel(direction) {
      const maxIndex = getMaxIndex();

      if (maxIndex === 0) {
        return;
      }

      currentIndex += direction;

      if (currentIndex > maxIndex) {
        currentIndex = 0;
      } else if (currentIndex < 0) {
        currentIndex = maxIndex;
      }

      updateCarousel(true);
    }

    function syncCarouselLayout() {
      visibleCount = getVisibleCount();
      currentIndex = Math.min(currentIndex, getMaxIndex());
      renderDots();
      updateCarousel();
    }

    previousButton?.addEventListener('click', () => moveCarousel(-1));
    nextButton?.addEventListener('click', () => moveCarousel(1));

    viewport?.addEventListener('keydown', (event) => {
      if (event.target !== viewport) {
        return;
      }

      if (event.key === 'ArrowLeft') {
        event.preventDefault();
        moveCarousel(-1);
      } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        moveCarousel(1);
      }
    });

    viewport?.addEventListener('touchstart', (event) => {
      touchStartX = event.changedTouches[0]?.clientX ?? null;
    }, { passive: true });

    viewport?.addEventListener('touchend', (event) => {
      if (touchStartX === null) {
        return;
      }

      const touchEndX = event.changedTouches[0]?.clientX ?? touchStartX;
      const distance = touchEndX - touchStartX;

      touchStartX = null;

      if (Math.abs(distance) >= 45) {
        moveCarousel(distance > 0 ? -1 : 1);
      }
    }, { passive: true });

    reviewsCarousel.querySelectorAll('.review-card__more').forEach((button) => {
      button.addEventListener('click', () => {
        const textId = button.getAttribute('aria-controls');
        const text = textId ? document.getElementById(textId) : null;

        if (!text) {
          return;
        }

        const isExpanded = button.getAttribute('aria-expanded') === 'true';

        text.classList.toggle('is-expanded', !isExpanded);
        button.setAttribute('aria-expanded', String(!isExpanded));
        button.textContent = isExpanded ? 'Читать полностью' : 'Свернуть';
      });
    });

    window.addEventListener('resize', () => {
      if (resizeFrame !== null) {
        window.cancelAnimationFrame(resizeFrame);
      }

      resizeFrame = window.requestAnimationFrame(() => {
        resizeFrame = null;
        syncCarouselLayout();
      });
    });

    window.requestAnimationFrame(syncCarouselLayout);
  }
});
