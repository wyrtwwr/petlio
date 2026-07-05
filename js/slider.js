function initSlider() {
  const gallery = document.querySelector('.constructor-gallery');

  if (!gallery) {
    return;
  }

  const frame = gallery.querySelector('.constructor-gallery__frame');
  const track = gallery.querySelector('.constructor-gallery__track');
  const prevButton = gallery.querySelector('.constructor-gallery__arrow--prev');
  const nextButton = gallery.querySelector('.constructor-gallery__arrow--next');
  const dots = gallery.querySelector('.constructor-gallery__dots');

  if (!frame || !track || !prevButton || !nextButton || !dots) {
    return;
  }

  let slides = [];
  let originalSlides = [];
  let currentIndex = 0;
  let cloneCount = 0;
  let slideStep = 0;
  let startX = 0;
  let dragOffset = 0;
  let isDragging = false;
  let isAnimating = false;
  let visibleCount = 1;

  const getVisibleCount = () => {
    const value = Number.parseInt(window.getComputedStyle(gallery).getPropertyValue('--gallery-visible'), 10);
    return Number.isNaN(value) ? 1 : value;
  };

  const setPosition = (withTransition = true) => {
    track.style.transition = withTransition ? '' : 'none';
    track.style.transform = `translateX(${-(currentIndex * slideStep) + dragOffset}px)`;
  };

  const getRealIndex = () => {
    if (!originalSlides.length) {
      return 0;
    }

    return (currentIndex - cloneCount + originalSlides.length) % originalSlides.length;
  };

  const updateDots = () => {
    const dotButtons = Array.from(dots.querySelectorAll('.constructor-gallery__dot'));
    const activePage = Math.floor(getRealIndex() / visibleCount);

    dotButtons.forEach((dot, index) => {
      dot.classList.toggle('is-active', index === activePage);
      dot.setAttribute('aria-current', index === activePage ? 'true' : 'false');
    });
  };

  const updateStep = () => {
    const slide = slides[currentIndex] || slides[0];

    if (!slide) {
      return;
    }

    const gap = Number.parseFloat(window.getComputedStyle(track).columnGap) || 0;
    slideStep = slide.getBoundingClientRect().width + gap;
    setPosition(false);
  };

  const clearClones = () => {
    track.querySelectorAll('[data-clone="true"]').forEach((slide) => slide.remove());
  };

  const buildLoop = () => {
    clearClones();

    originalSlides = Array.from(track.querySelectorAll('.constructor-gallery__slide'));
    visibleCount = getVisibleCount();
    cloneCount = Math.min(visibleCount, originalSlides.length);

    const beforeClones = originalSlides.slice(-cloneCount).map((slide) => {
      const clone = slide.cloneNode(true);
      clone.dataset.clone = 'true';
      clone.setAttribute('aria-hidden', 'true');
      return clone;
    });

    const afterClones = originalSlides.slice(0, cloneCount).map((slide) => {
      const clone = slide.cloneNode(true);
      clone.dataset.clone = 'true';
      clone.setAttribute('aria-hidden', 'true');
      return clone;
    });

    beforeClones.forEach((slide) => track.insertBefore(slide, track.firstChild));
    afterClones.forEach((slide) => track.appendChild(slide));

    slides = Array.from(track.querySelectorAll('.constructor-gallery__slide'));
    currentIndex = cloneCount;
    isAnimating = false;
    dragOffset = 0;
    buildDots();
    updateStep();
    updateDots();
  };

  const buildDots = () => {
    dots.innerHTML = '';

    const pages = Math.max(1, Math.ceil(originalSlides.length / visibleCount));

    Array.from({ length: pages }).forEach((_, index) => {
      const dot = document.createElement('button');
      dot.className = 'constructor-gallery__dot';
      dot.type = 'button';
      dot.setAttribute('aria-label', `Показать группу ${index + 1}`);
      dot.addEventListener('click', () => {
        const targetIndex = cloneCount + index * visibleCount;

        if (isAnimating || currentIndex === targetIndex) {
          return;
        }

        currentIndex = targetIndex;
        isAnimating = true;
        dragOffset = 0;
        setPosition();
      });

      dots.appendChild(dot);
    });
  };

  const move = (direction) => {
    if (!slideStep || isAnimating) {
      return;
    }

    isAnimating = true;
    dragOffset = 0;
    currentIndex += direction;
    setPosition();
  };

  prevButton.addEventListener('click', () => move(-1));
  nextButton.addEventListener('click', () => move(1));

  track.addEventListener('transitionend', () => {
    if (currentIndex >= originalSlides.length + cloneCount) {
      currentIndex = cloneCount;
      setPosition(false);
    }

    if (currentIndex < cloneCount) {
      currentIndex = originalSlides.length + cloneCount - 1;
      setPosition(false);
    }

    isAnimating = false;
    updateDots();
  });

  frame.addEventListener('pointerdown', (event) => {
    if (event.pointerType === 'mouse' || isAnimating) {
      return;
    }

    isDragging = true;
    startX = event.clientX;
    dragOffset = 0;
    track.classList.add('is-dragging');

    if (frame.setPointerCapture) {
      frame.setPointerCapture(event.pointerId);
    }
  });

  frame.addEventListener('pointermove', (event) => {
    if (!isDragging) {
      return;
    }

    dragOffset = event.clientX - startX;
    setPosition(false);
  });

  const finishDrag = (event) => {
    if (!isDragging) {
      return;
    }

    if (frame.hasPointerCapture && frame.hasPointerCapture(event.pointerId)) {
      frame.releasePointerCapture(event.pointerId);
    }

    track.classList.remove('is-dragging');

    const threshold = slideStep * 0.16;
    const direction = Math.abs(dragOffset) > threshold ? Math.sign(dragOffset) : 0;

    dragOffset = 0;
    isDragging = false;

    if (direction > 0) {
      move(-1);
      return;
    }

    if (direction < 0) {
      move(1);
      return;
    }

    setPosition();
  };

  frame.addEventListener('pointerup', finishDrag);
  frame.addEventListener('pointercancel', finishDrag);
  window.addEventListener('resize', buildLoop);

  buildLoop();
}

initSlider();
