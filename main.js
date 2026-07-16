/* Adalyn Technologies - shared site behavior (used on every page) */

document.addEventListener('DOMContentLoaded', () => {

  /* Hamburger menu */
  const hamburgerBtn = document.getElementById('hamburgerBtn');
  const hamburgerIcon = document.getElementById('hamburgerIcon');
  const mobileNavPanel = document.getElementById('mobileNavPanel');
  if (hamburgerBtn && mobileNavPanel) {
    hamburgerBtn.addEventListener('click', () => {
      mobileNavPanel.classList.toggle('open');
      hamburgerIcon.className = mobileNavPanel.classList.contains('open') ? 'fas fa-times' : 'fas fa-bars';
    });
    mobileNavPanel.querySelectorAll('a').forEach(a => {
      a.addEventListener('click', () => {
        mobileNavPanel.classList.remove('open');
        hamburgerIcon.className = 'fas fa-bars';
      });
    });
  }

  /* Active nav link - based on current filename */
  const path = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-link-item, .mobile-nav-panel a').forEach(link => {
    const href = link.getAttribute('href');
    if (!href || href.startsWith('http') || href.startsWith('#')) return;
    const linkFile = href.split('#')[0];
    if (linkFile === path || (path === '' && linkFile === 'index.html')) {
      link.classList.add('active');
    }
  });

  /* FAQ accordion */
  document.querySelectorAll('.faq-q').forEach(q => {
    q.addEventListener('click', () => {
      q.parentElement.classList.toggle('open');
    });
  });

  /* Scroll-in animations */
  const animEls = document.querySelectorAll('.anim');
  if (animEls.length) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry, i) => {
        if (entry.isIntersecting) {
          setTimeout(() => entry.target.classList.add('in'), i * 80);
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.1 });
    animEls.forEach(el => observer.observe(el));
  }

  /* Portfolio / case study filters (visual only) */
  document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const group = btn.closest('.portfolio-filters, .insight-categories') || document;
      group.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
    });
  });

  /* In-page anchor smooth scroll + scroll-margin for sticky nav */
  document.querySelectorAll('section[id], div.hero[id], main[id]').forEach(s => {
    s.style.scrollMarginTop = '90px';
  });
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', function (e) {
      const href = this.getAttribute('href');
      if (href === '#') return;
      const target = document.querySelector(href);
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });
});

/* Contact / Quick Inquiry form -> WhatsApp handoff (used on contact.html and homepage) */
function sendViaWhatsApp() {
  const nameEl = document.getElementById('cName');
  const contactEl = document.getElementById('cContact');
  const messageEl = document.getElementById('cMessage');
  const name = nameEl ? nameEl.value.trim() : '';
  const contact = contactEl ? contactEl.value.trim() : '';
  const message = messageEl ? messageEl.value.trim() : '';
  if (!name || !message) {
    alert('Please add your name and project description.');
    return;
  }
  const text = `Hi Adalyn Technologies,\n\nName: ${name}${contact ? '\nContact: ' + contact : ''}\n\n${message}`;
  window.open(`https://wa.me/254748077609?text=${encodeURIComponent(text)}`, '_blank');
}

  /* Mobile Solutions collapsible group */
  const mSolToggle = document.getElementById('mobileSolutionsToggle');
  const mSolList = document.getElementById('mobileSolutionsList');
  const mSolIcon = document.getElementById('mobileSolutionsIcon');
  if (mSolToggle && mSolList) {
    mSolToggle.addEventListener('click', () => {
      const isOpen = mSolList.style.display === 'block';
      mSolList.style.display = isOpen ? 'none' : 'block';
      mSolIcon.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
    });
  }

  /* Desktop nav dropdowns - chevron click to open, only one at a time. Link text still navigates normally. */
  document.querySelectorAll('.nav-links > li').forEach(li => {
    const chevron = li.querySelector(':scope > a .chevron');
    const menu = li.querySelector('.dropdown-menu');
    if (!chevron || !menu) return;
    chevron.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const isOpen = li.classList.contains('dropdown-open');
      document.querySelectorAll('.nav-links > li.dropdown-open').forEach(openLi => openLi.classList.remove('dropdown-open'));
      if (!isOpen) li.classList.add('dropdown-open');
    });
  });
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.nav-links > li')) {
      document.querySelectorAll('.nav-links > li.dropdown-open').forEach(li => li.classList.remove('dropdown-open'));
    }
  });

  /* Site search */
  const sitePages = [
    { title: "Home", url: "index.html", keywords: "home hero landing" },
    { title: "About", url: "about.html", keywords: "about mission vision values why choose us" },
    { title: "Solutions", url: "solutions.html", keywords: "solutions startup business education enterprise developer cloud mpesa" },
    { title: "Student Services", url: "student_services.html", keywords: "student final year project pricing 4500" },
    { title: "Industries", url: "industries.html", keywords: "industries education healthcare agriculture retail hospitality" },
    { title: "Portfolio", url: "portfolio.html", keywords: "portfolio case studies work projects chuka joan kuku farm testimonials" },
    { title: "Pricing", url: "pricing.html", keywords: "pricing services rate card mpesa integration web development" },
    { title: "Insights", url: "insights.html", keywords: "insights blog articles idempotency" },
    { title: "Contact", url: "contact.html", keywords: "contact email phone whatsapp faq" },
    { title: "Proposal Generator", url: "proposal_generator.html", keywords: "proposal generator pdf export quotation" }
  ];

  function renderSearchResults(list) {
    const resultsEl = document.getElementById("navSearchResults");
    resultsEl.innerHTML = "";
    if (!list.length) {
      resultsEl.innerHTML = "<div style=\"padding:8px 10px; color:#94a3b8; font-size:13px;\">No matches found.</div>";
      return;
    }
    list.forEach(m => {
      const a = document.createElement("a");
      a.href = m.url;
      a.textContent = m.title;
      resultsEl.appendChild(a);
    });
  }

  window.toggleSearch = function() {
    const box = document.getElementById("navSearchBox");
    box.classList.toggle("open");
    if (box.classList.contains("open")) {
      renderSearchResults(sitePages);
      document.getElementById("navSearchInput").focus();
    }
  };

  window.runSiteSearch = function() {
    const query = document.getElementById("navSearchInput").value.toLowerCase().trim();
    if (!query) { renderSearchResults(sitePages); return; }
    const matches = sitePages.filter(p => p.title.toLowerCase().includes(query) || p.keywords.includes(query));
    renderSearchResults(matches);
  };

  document.addEventListener("click", (e) => {
    const searchWrap = document.querySelector(".nav-search");
    if (searchWrap && !searchWrap.contains(e.target)) {
      document.getElementById("navSearchBox")?.classList.remove("open");
    }
  });
