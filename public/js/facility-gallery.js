(() => {
    const initialized = new WeakSet();

    function initialize() {
        document.querySelectorAll('[data-facility-carousel]').forEach((carousel) => {
            if (initialized.has(carousel)) return;
            initialized.add(carousel);
            const slides = Array.from(carousel.querySelectorAll('[data-carousel-slide]'));
            const indicators = Array.from(carousel.querySelectorAll('[data-carousel-indicator]'));
            if (!slides.length) return;
            let currentIndex = 0;

            const showSlide = (index) => {
                currentIndex = (index + slides.length) % slides.length;
                slides.forEach((slide, slideIndex) => { slide.hidden = slideIndex !== currentIndex; });
                indicators.forEach((indicator, indicatorIndex) => {
                    const active = indicatorIndex === currentIndex;
                    indicator.classList.toggle('active', active);
                    indicator.setAttribute('aria-current', active ? 'true' : 'false');
                });
            };

            carousel.querySelector('[data-carousel-previous]')?.addEventListener('click', () => showSlide(currentIndex - 1));
            carousel.querySelector('[data-carousel-next]')?.addEventListener('click', () => showSlide(currentIndex + 1));
            indicators.forEach((indicator) => {
                indicator.addEventListener('click', () => showSlide(Number(indicator.dataset.carouselIndicator)));
            });
            carousel.addEventListener('keydown', (event) => {
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
                event.preventDefault();
                showSlide(currentIndex + (event.key === 'ArrowLeft' ? -1 : 1));
            });
            carousel.closest('.ereserve-modal')?.addEventListener('modal:reset', () => showSlide(0));
        });
    }

    initialize();
    document.addEventListener('modals:updated', initialize);
})();
