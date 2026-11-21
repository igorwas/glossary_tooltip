/**
 * @file
 * Glossary tooltip behaviour: tap to toggle, Escape to close, smart placement.
 *
 * Without JavaScript the tooltip still opens on hover and keyboard focus
 * (see the CSS); this script adds touch support and keeps it on screen.
 */
((Drupal, once) => {
  const MARGIN = 8;

  // The design opens the tooltip below the term on desktop, above on mobile.
  const mobile = window.matchMedia('(max-width: 767px)');

  /**
   * Places the popup above or below the term and keeps it in the viewport.
   *
   * @param {HTMLElement} tooltip
   *   The .glossary-tooltip wrapper.
   */
  function position(tooltip) {
    const term = tooltip.querySelector('.glossary-tooltip__term');
    const popup = tooltip.querySelector('.glossary-tooltip__popup');
    // Closed popups are display: none and cannot be measured.
    if (!popup.offsetWidth) {
      return;
    }
    const termRect = term.getBoundingClientRect();
    const wrapperRect = tooltip.getBoundingClientRect();
    const width = popup.offsetWidth;

    // Use the preferred side, unless only the other side has room.
    const needed = popup.offsetHeight + MARGIN;
    const roomAbove = termRect.top >= needed;
    const roomBelow = window.innerHeight - termRect.bottom >= needed;
    const above = mobile.matches
      ? roomAbove || !roomBelow
      : !roomBelow && roomAbove;
    tooltip.classList.toggle('glossary-tooltip--above', above);

    // Centre on the term, then shift back inside the viewport if needed.
    const center = termRect.left + termRect.width / 2;
    const maxLeft = document.documentElement.clientWidth - width - MARGIN;
    const left = Math.max(MARGIN, Math.min(center - width / 2, maxLeft));
    // The arrow follows the term, but never leaves the rounded box.
    const arrow = Math.max(16, Math.min(center - left, width - 16));

    tooltip.style.setProperty('--glossary-tooltip-x', '0px');
    popup.style.left = `${left - wrapperRect.left}px`;
    tooltip.style.setProperty('--glossary-tooltip-arrow', `${arrow}px`);
  }

  /**
   * Opens or closes a tooltip.
   *
   * @param {HTMLElement} tooltip
   *   The .glossary-tooltip wrapper.
   * @param {boolean} open
   *   Whether to open it.
   */
  function setOpen(tooltip, open) {
    tooltip.classList.toggle('is-open', open);
    if (open) {
      position(tooltip);
    }
    tooltip
      .querySelector('.glossary-tooltip__term')
      .setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  Drupal.behaviors.glossaryTooltip = {
    attach(context) {
      once('glossary-tooltip', '.glossary-tooltip', context).forEach(
        (tooltip) => {
          const term = tooltip.querySelector('.glossary-tooltip__term');

          term.addEventListener('click', () => {
            const open = !tooltip.classList.contains('is-open');
            // Only one tapped tooltip open at a time.
            document
              .querySelectorAll('.glossary-tooltip.is-open')
              .forEach((other) => other !== tooltip && setOpen(other, false));
            tooltip.classList.remove('is-dismissed');
            setOpen(tooltip, open);
          });

          // Hover and focus open the popup via CSS; place it first.
          tooltip.addEventListener('mouseenter', () => position(tooltip));
          tooltip.addEventListener('focusin', () => position(tooltip));

          tooltip.addEventListener('mouseleave', () =>
            tooltip.classList.remove('is-dismissed'),
          );
          tooltip.addEventListener('focusout', (event) => {
            if (!tooltip.contains(event.relatedTarget)) {
              tooltip.classList.remove('is-dismissed');
              setOpen(tooltip, false);
            }
          });

          tooltip.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
              setOpen(tooltip, false);
              tooltip.classList.add('is-dismissed');
              term.focus();
            }
          });
        },
      );

      // Tapping anywhere else closes open tooltips.
      once('glossary-tooltip-outside', 'html').forEach(() => {
        document.addEventListener('click', (event) => {
          document
            .querySelectorAll('.glossary-tooltip.is-open')
            .forEach(
              (tooltip) =>
                !tooltip.contains(event.target) && setOpen(tooltip, false),
            );
        });
      });
    },
  };
})(Drupal, once);
