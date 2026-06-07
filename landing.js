/**
 * FocusTrack — landing.js
 * ─────────────────────────
 * Handles: nav scroll, mobile menu, scroll reveals,
 *          hero text swap, ring animation on load
 */

'use strict';

/* ── Nav: add .scrolled class on scroll ─────────────────── */
const nav = document.getElementById('nav');
if (nav) {
  window.addEventListener('scroll', () => {
    nav.classList.toggle('scrolled', window.scrollY > 20);
  }, { passive: true });
}

/* ── Mobile menu toggle ──────────────────────────────────── */
const burger     = document.getElementById('burger');
const mobileMenu = document.getElementById('mobileMenu');
if (burger && mobileMenu) {
  burger.addEventListener('click', () => {
    mobileMenu.classList.toggle('open');
    // Animate burger → X
    const spans = burger.querySelectorAll('span');
    if (mobileMenu.classList.contains('open')) {
      spans[0].style.transform = 'translateY(7px) rotate(45deg)';
      spans[1].style.opacity   = '0';
      spans[2].style.transform = 'translateY(-7px) rotate(-45deg)';
    } else {
      spans[0].style.transform = '';
      spans[1].style.opacity   = '';
      spans[2].style.transform = '';
    }
  });

  // Close on link click
  mobileMenu.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => {
      mobileMenu.classList.remove('open');
      burger.querySelectorAll('span').forEach(s => { s.style.transform = ''; s.style.opacity = ''; });
    });
  });
}

/* ── Scroll reveal ───────────────────────────────────────── */
const reveals = document.querySelectorAll(
  '.stat-card, .bento-card, .step, .test-card, .sci-card, ' +
  '.story-card, .big-story, .walk-step, .compare-table, .rm-step'
);

reveals.forEach(el => el.classList.add('reveal'));

const revealObserver = new IntersectionObserver((entries) => {
  entries.forEach((entry, i) => {
    if (entry.isIntersecting) {
      // Stagger siblings
      const siblings = [...entry.target.parentElement.children];
      const idx = siblings.indexOf(entry.target);
      entry.target.style.transitionDelay = `${idx * 60}ms`;
      entry.target.classList.add('visible');
      revealObserver.unobserve(entry.target);
    }
  });
}, { threshold: 0.12 });

reveals.forEach(el => revealObserver.observe(el));

/* ── Hero headline word swap ─────────────────────────────── */
const swapEl = document.getElementById('swapWord');
if (swapEl) {
  const words = [
    'deserves better',
    'was made for this',
    'is worth protecting',
    'can do this',
    'has real goals',
  ];
  let idx = 0;
  setInterval(() => {
    idx = (idx + 1) % words.length;
    swapEl.style.opacity   = '0';
    swapEl.style.transform = 'translateY(-8px)';
    setTimeout(() => {
      swapEl.textContent     = words[idx];
      swapEl.style.opacity   = '1';
      swapEl.style.transform = 'translateY(0)';
    }, 300);
  }, 2800);

  // Smooth transition on the element
  swapEl.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
}

/* ── Animate hero ring on load ───────────────────────────── */
const heroRing = document.getElementById('heroRing');
if (heroRing) {
  // Start at full offset (empty), animate to 80 (68%)
  heroRing.style.strokeDashoffset = '251';
  heroRing.style.transition = 'stroke-dashoffset 1.4s cubic-bezier(0.22, 1, 0.36, 1) 0.5s';
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      heroRing.style.strokeDashoffset = '80';
    });
  });
}

/* ── Ticker: pause on hover ──────────────────────────────── */
const ticker = document.querySelector('.ticker');
if (ticker) {
  ticker.addEventListener('mouseenter', () => ticker.style.animationPlayState = 'paused');
  ticker.addEventListener('mouseleave', () => ticker.style.animationPlayState = 'running');
}

/* ── Smooth anchor scroll ────────────────────────────────── */
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const target = document.querySelector(a.getAttribute('href'));
    if (target) {
      e.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });
});
