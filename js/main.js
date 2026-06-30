document.addEventListener('DOMContentLoaded', () => {
  console.log('Address Shop loaded');

  const sizeCards = Array.from(document.querySelectorAll('.size-card'));

  function selectCard(card) {
    sizeCards.forEach((item) => {
      const isActive = item === card;
      item.classList.toggle('is-selected', isActive);
      item.setAttribute('aria-pressed', String(isActive));
    });
  }

  sizeCards.forEach((card) => {
    card.addEventListener('click', (event) => {
      if (event.target.closest('.size-card__wish')) {
        return;
      }

      selectCard(card);
    });

    card.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        selectCard(card);
      }
    });
  });
});
