/* ==========================================================================
   Smart Text: homepage app logic
   Plain vanilla JS. No build step, no framework. Edit this file directly.
   ========================================================================== */

/* ---------- Content -------------------------------------------------------
   All copy lives here. Edit these objects to change what's on the page. */

const INDUSTRY_DATA = {
  automotive: {
    name: 'Automotive', tagline: 'Our flagship market', flagship: true,
    blurb: 'Scheduled texting for service reminders, trade-ins, and sales follow-up.',
    headline: 'Fill your service bays. Sell more cars. All by text.',
    sub: 'Smart Text turns service reminders, recall notices, and trade-in offers into booked appointments, scheduled straight from your customer database.',
    bannerImage: 'https://images.unsplash.com/photo-1487754180451-c456f719a1fc?w=1600&q=80&auto=format&fit=crop',
    stats: [{ n: '98%', l: 'message read rate' }, { n: '45%', l: 'service reminder response' }, { n: '3.2x', l: 'trade-in lead conversion' }, { n: '1,200+', l: 'dealerships' }],
    usecases: [
      { title: 'Service reminders & recalls', desc: 'Schedule texts to go out when service is due or a safety recall is issued, cutting missed appointments.', image: 'https://images.unsplash.com/photo-1615906655593-ad0386982a0f?w=700&q=80&auto=format&fit=crop' },
      { title: 'Trade-in & sales follow-up', desc: 'Re-engage past buyers with trade-in offers timed to their lease or loan cycle.', image: 'https://images.unsplash.com/photo-1625047509248-ec889cbff17f?w=700&q=80&auto=format&fit=crop' },
      { title: 'Sales & service lead routing', desc: 'Route new enquiries and service requests straight to the right team member, so nothing sits waiting.', image: 'https://images.unsplash.com/photo-1645445522156-9ac06bc7a767?w=700&q=80&auto=format&fit=crop' },
      { title: 'Inventory & offer alerts', desc: 'Notify shoppers the moment a matching vehicle hits your lot.', image: 'https://images.unsplash.com/photo-1596986952526-3be237187071?w=700&q=80&auto=format&fit=crop' },
    ],
    quote: '"We cut missed service appointments dramatically once reminders and recall alerts went out by text instead of email."',
    author: 'Service Director, Multi-Point Auto Group',
    samples: [
      { key: 'usedcars', name: 'Used Cars', desc: 'Used Car Sales Event' },
      { key: 'bmw', name: 'BMW', desc: 'Sales Event' },
      { key: 'ford', name: 'Ford', desc: 'PCP Choices' },
      { key: 'volkswagen', name: 'Volkswagen', desc: 'Aftersales Promotion' },
      { key: 'audi', name: 'Audi', desc: 'Service Reminder' },
    ],
  },
  healthcare: {
    name: 'Healthcare & Medical', tagline: 'Patient engagement & scheduling', flagship: false,
    blurb: 'Improve patient engagement and encourage more bookings through personalised mobile communications.',
    headline: 'Fewer no-shows. More patients seen.',
    sub: 'Smart Text schedules appointment reminders, recall outreach, and intake, built around how patients actually respond.',
    bannerImage: 'https://images.unsplash.com/photo-1576091160550-2173dba999ef?w=1600&q=80&auto=format&fit=crop',
    stats: [{ n: '35%', l: 'reduction in no-shows' }, { n: '92%', l: 'reminder read rate' }, { n: '2.5x', l: 'recall response' }, { n: '600+', l: 'practices' }],
    usecases: [
      { title: 'Appointment reminders', desc: 'Cut no-shows with scheduled reminders synced to your booking system.', image: 'https://images.unsplash.com/photo-1758691462878-6edc3d3da1be?w=700&q=80&auto=format&fit=crop' },
      { title: 'Recall & preventive care outreach', desc: 'Re-engage patients due for checkups, cleanings, or screenings.', image: 'https://images.unsplash.com/photo-1758691462413-b07dee2933fe?w=700&q=80&auto=format&fit=crop' },
      { title: 'Patient intake by text', desc: 'Send forms and instructions ahead of the visit, reducing front-desk time.', image: 'https://images.unsplash.com/photo-1758691462814-485c3672e447?w=700&q=80&auto=format&fit=crop' },
      { title: 'Post-visit follow-up', desc: 'Schedule check-ins after appointments, with replies routed to the right staff.', image: 'https://images.unsplash.com/photo-1758691462858-f1286e5daf40?w=700&q=80&auto=format&fit=crop' },
    ],
    quote: '"No-shows dropped within the first month. Patients simply respond better to a text than a phone call."',
    author: 'Practice Manager, Multi-Location Dental Group',
    samples: [
      { key: 'appointment', name: 'Appointment Reminder', desc: 'Reduce missed visits' },
      { key: 'recall', name: 'Recall Outreach', desc: 'Checkups & screenings due' },
      { key: 'intake', name: 'Patient Intake', desc: 'Forms sent ahead of the visit' },
      { key: 'followup', name: 'Post-Visit Follow-Up', desc: 'Check in after care' },
    ],
  },
  realestate: {
    name: 'Property & Real Estate', tagline: 'Listings, showings & lead routing', flagship: false,
    blurb: 'Generate more viewings and property enquiries with interactive campaigns tailored to every buyer.',
    headline: 'Turn database leads into booked showings.',
    sub: 'Smart Text helps agents and brokerages re-engage their contact list and route new inquiries to the right agent, instantly.',
    bannerImage: 'https://images.unsplash.com/photo-1592595896551-12b371d546d5?w=1600&q=80&auto=format&fit=crop',
    stats: [{ n: '40%', l: 'avg. open rate' }, { n: '6%', l: 'avg. booking rate' }, { n: '3x', l: 'listing inquiry conversion' }, { n: '900+', l: 'agencies' }],
    usecases: [
      { title: 'Listing alerts', desc: 'Text new and price-changed listings to buyers matching their saved criteria.', image: 'https://images.unsplash.com/photo-1605146769289-440113cc3d00?w=700&q=80&auto=format&fit=crop' },
      { title: 'Showing scheduling', desc: 'Let leads book a showing time directly from a text, no phone tag.', image: 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=700&q=80&auto=format&fit=crop' },
      { title: 'Database reactivation', desc: 'Re-engage past leads and expired listings with timely, personalized outreach.', image: 'https://images.unsplash.com/photo-1748228885250-49564b614db9?w=700&q=80&auto=format&fit=crop' },
      { title: 'Instant lead routing', desc: 'Route new inquiries to the right agent in one tap, day or night.', image: 'https://images.unsplash.com/photo-1582407947304-fd86f028f716?w=700&q=80&auto=format&fit=crop' },
    ],
    quote: '"We reactivated a database we thought was dead. Showings booked themselves within the first week."',
    author: 'Broker/Owner, Regional Realty Group',
    samples: [
      { key: 'listing', name: 'Listing Alert', desc: 'New matching properties' },
      { key: 'showing', name: 'Showing Reminder', desc: 'Book a viewing by text' },
      { key: 'reactivation', name: 'Database Reactivation', desc: 'Re-engage past leads' },
      { key: 'routing', name: 'Instant Lead Routing', desc: 'New enquiry to the right agent' },
    ],
  },
  retail: {
    name: 'E-commerce & Retail', tagline: 'Cart recovery & promotions', flagship: false,
    blurb: 'Increase customer engagement, repeat purchases and campaign performance through personalised mobile experiences.',
    headline: 'Turn browsers into buyers, one text at a time.',
    sub: 'Smart Text powers cart-recovery, promotions, and loyalty messaging for retailers who want to meet customers where they already are.',
    bannerImage: 'https://images.unsplash.com/photo-1481437156560-3205f6a55735?w=1600&q=80&auto=format&fit=crop',
    stats: [{ n: '45%', l: 'cart recovery lift' }, { n: '30%', l: 'promo redemption rate' }, { n: '3x', l: 'avg. ROAS' }, { n: '700+', l: 'retailers' }],
    usecases: [
      { title: 'Cart & browse recovery', desc: 'Win back customers who left items in their cart with a well-timed text.', image: 'https://images.unsplash.com/photo-1601598851547-4302969d0614?w=700&q=80&auto=format&fit=crop' },
      { title: 'Flash sales & promotions', desc: 'Send time-sensitive offers segmented by purchase history.', image: 'https://images.unsplash.com/photo-1546213290-e1b492ab3eee?w=700&q=80&auto=format&fit=crop' },
      { title: 'Loyalty & VIP messaging', desc: 'Reward repeat customers with early access and exclusive perks.', image: 'https://images.unsplash.com/photo-1441984904996-e0b6ba687e04?w=700&q=80&auto=format&fit=crop' },
      { title: 'In-store pickup alerts', desc: 'Notify shoppers the moment their order is ready.', image: 'https://images.unsplash.com/photo-1472851294608-062f824d29cc?w=700&q=80&auto=format&fit=crop' },
    ],
    quote: '"Cart recovery texts alone paid for the platform in the first month."',
    author: 'eCommerce Director, DTC Apparel Brand',
    samples: [
      { key: 'cartrecovery', name: 'Cart Recovery', desc: 'Win back abandoned carts' },
      { key: 'flashsale', name: 'Flash Sale', desc: 'Time-sensitive offer' },
      { key: 'loyalty', name: 'Loyalty & VIP', desc: 'Early access & perks' },
      { key: 'pickup', name: 'Pickup Alert', desc: 'Order ready notification' },
    ],
  },
  travel: {
    name: 'Travel & Leisure', tagline: 'Bookings, offers & loyalty', flagship: false,
    blurb: 'Promote time-sensitive availability, reward loyal customers and drive direct bookings.',
    headline: 'Turn last-minute availability into direct bookings.',
    sub: 'Smart Text helps travel and leisure businesses promote time-sensitive offers, reward loyal customers, and turn browsers into direct bookings.',
    bannerVideo: 'assets/videos/travel-loyalty-repeat-guest.mp4',
    stats: [{ n: '38%', l: 'direct booking lift' }, { n: '85%', l: 'offer read rate' }, { n: '2.8x', l: 'repeat booking rate' }, { n: '500+', l: 'venues & operators' }],
    usecases: [
      { title: 'Last-minute availability alerts', desc: 'Text time-sensitive offers the moment rooms, seats, or tables open up.', image: 'https://images.unsplash.com/photo-1551882547-ff40c63fe5fa?w=700&q=80&auto=format&fit=crop' },
      { title: 'Loyalty & repeat-guest messaging', desc: 'Reward returning guests with early access, upgrades, and exclusive perks.', image: 'https://images.unsplash.com/photo-1445019980597-93fa8acb246c?w=700&q=80&auto=format&fit=crop' },
      { title: 'Booking confirmations & reminders', desc: 'Cut no-shows with scheduled confirmations and pre-arrival reminders.', image: 'https://images.unsplash.com/photo-1455587734955-081b22074882?w=700&q=80&auto=format&fit=crop' },
      { title: 'Direct booking recovery', desc: "Re-engage browsers who didn't complete a booking with a well-timed follow-up text.", image: 'https://images.unsplash.com/photo-1584132967334-10e028bd69f7?w=700&q=80&auto=format&fit=crop' },
    ],
    quote: '"Direct bookings picked up noticeably once last-minute availability went out by text instead of email."',
    author: 'Revenue Manager, Boutique Hotel Group',
    samples: [
      { key: 'lastminute', name: 'Last-Minute Availability', desc: 'Rooms, seats, or tables open up' },
      { key: 'loyalty', name: 'Loyalty & Repeat Guest', desc: 'Early access & upgrades' },
      { key: 'bookingreminder', name: 'Booking Reminder', desc: 'Pre-arrival confirmation' },
      { key: 'bookingrecovery', name: 'Booking Recovery', desc: 'Re-engage browsers' },
    ],
  },
};

/* Shared integrations list shown on every industry page. UK/IE/EU market only,
   no US-specific systems (no HIPAA, no PMS, no EHR, no MLS). */
const INTEGRATION_BADGES = [
  'Lead Management Software',
  'CRM Integration',
  'Reporting & Analytics',
  'Team & Lead Routing',
  'GDPR Friendly',
];

const FEATURES = [
  { title: 'Database and CRM Upload', desc: 'Your customer database is one of your greatest growth opportunities. Import your CRM or segmented audience and reach the right customers with relevant, personalised communications.', video: 'assets/videos/database-crm-upload.mp4' },
  { title: 'Integrations', desc: 'Connect Smart Text to your existing workflows so enquiries are routed instantly to the right people, helping your team respond faster and convert more opportunities.', video: 'assets/videos/integrations.mp4' },
  { title: 'Analytics and Reporting', desc: 'Understand exactly how customers engage. Track opens, clicks, conversions and campaign performance in real time to optimise every campaign.', video: 'assets/videos/analytics-reporting.mp4' },
  { title: 'Campaign Builder and Templates', desc: 'Launch personalised campaigns in minutes using flexible templates that support every stage of your customer journey, from awareness to conversion.', video: 'assets/videos/campaign-builder.mp4' },
  { title: 'Lead Routing', desc: 'Route responses to the right person or team so every opportunity gets followed up quickly.', video: 'assets/videos/lead-routing.mp4' },
];

const WAYS = [
  { title: 'Upload and segment', desc: 'Build highly targeted audiences using the customer data you already own.' },
  { title: 'Personalised by record', desc: "Deliver relevant communications that reflect each customer's relationship with your business." },
  { title: 'Route to the right person', desc: 'Ensure every enquiry reaches the right team without delay.' },
  { title: 'Every tap tracked to the record', desc: 'Measure engagement, identify opportunities and continuously improve campaign performance.' },
];

const HOME_STATS = [
  { n: '40%', l: 'avg. open rate' },
  { n: '6%', l: 'avg. booking rate' },
  { n: '3x', l: 'avg. ROAS' },
  { n: '90%', l: 'delivery success' },
  { n: '800+', l: 'businesses' },
];

/* Real client logos + quotes, mirroring the "As chosen by leading
   manufacturers and dealers" strip on smarttext.com. Order matches the
   live site. The centred logo's quote is the one shown underneath. */
const CLIENT_LOGOS = [
  {
    name: 'SEAT Ireland', logo: 'assets/logos/seat.png', alt: 'SEAT',
    quote: 'We’ve used Smart Text since May 2017, and it’s now a primary customer contact solution for all SEAT Ireland and SEAT dealers’ sales and aftersales related promotions',
  },
  {
    name: 'Opel Ireland', logo: 'assets/logos/opel.png', alt: 'Opel',
    quote: 'It’s an extremely effective means of communication which is not available from any other providers',
  },
  {
    name: 'Jaguar Ireland', logo: 'assets/logos/jaguar.png', alt: 'Jaguar',
    quote: 'Smart Text has become synonymous with Dealer Marketing and is now included in all our sales related promotions',
  },
  {
    name: 'Renault Ireland', logo: 'assets/logos/renault.png', alt: 'Renault',
    quote: 'Smart Text is our main customer contact solution for all dealer sales events & new product launches and has been for several years. Smart Text delivers instant quantifiable leads',
  },
  {
    name: 'Johnson & Perrott Land Rover', logo: 'assets/logos/landrover.png', alt: 'Land Rover',
    quote: 'Smart Text generates instant sales leads and is used in all our campaigns. The integration into our lead management systems has been seamless',
  },
  {
    name: 'Mooney’s Hyundai', logo: 'assets/logos/hyundai.png', alt: 'Hyundai',
    quote: 'The Smart Text reporting portal makes life and lead follow-up so simple for our sales team',
  },
];

const TESTIMONIALS = [
  { tag: 'Automotive', quote: '"We cut missed service appointments dramatically once reminders and recall alerts went out by text instead of email."', author: 'Service Director, Multi-Point Auto Group' },
  { tag: 'Healthcare', quote: '"No-shows dropped within the first month. Patients simply respond better to a text than a phone call."', author: 'Practice Manager, Multi-Location Dental Group' },
];

/* Sample Smart Texts offered on the "Receive a Smart Text" form, mirroring
   smarttext.com/try-now. Same set on every page for now; once industry-
   specific samples are ready, swap this list per industry. */
const SAMPLE_OPTIONS = [
  { key: 'usedcars', name: 'Used Cars', desc: 'Used Car Sales Event' },
  { key: 'bmw', name: 'BMW', desc: 'Sales Event' },
  { key: 'ford', name: 'Ford', desc: 'PCP Choices' },
  { key: 'volkswagen', name: 'Volkswagen', desc: 'Aftersales Promotion' },
  { key: 'audi', name: 'Audi', desc: 'Service Reminder' },
];

/* ---------- Icons ----------------------------------------------------------
   Small inline SVG set (24x24, stroke-based). Used for homepage industry
   cards and as placeholder art on use-case cards until real photos/screens
   are supplied. */

const ICON_PATHS = {
  car: '<path d="M3 13l1.5-4.5A2 2 0 0 1 6.4 7h11.2a2 2 0 0 1 1.9 1.5L21 13"/><path d="M3 13h18v4a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1v-1H6v1a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-4z"/><circle cx="7.5" cy="17" r="1.5"/><circle cx="16.5" cy="17" r="1.5"/>',
  heartPulse: '<path d="M19 14c1.5-1.5 3-3.2 3-5.5A4.5 4.5 0 0 0 17.5 4c-1.7 0-3 .8-3.9 2.1L12 8l-1.6-1.9C9.5 4.8 8.2 4 6.5 4A4.5 4.5 0 0 0 2 8.5c0 2.3 1.5 4 3 5.5l7 7 7-7z"/><path d="M3.5 12h4l1.5-3 2 5 1.5-3h4.5"/>',
  home: '<path d="M4 11.5L12 4l8 7.5"/><path d="M6 10v9a1 1 0 0 0 1 1h3v-5h4v5h3a1 1 0 0 0 1-1v-9"/>',
  bag: '<path d="M6 8h12l1 12a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1L6 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
  plane: '<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/>',
  bell: '<path d="M12 4a5 5 0 0 0-5 5v3.5L5 15h14l-2-2.5V9a5 5 0 0 0-5-5z"/><path d="M9.5 18a2.5 2.5 0 0 0 5 0"/>',
  calendar: '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 9h16"/><path d="M8 3v4M16 3v4"/><path d="M9 14l2 2 4-4"/>',
  refresh: '<path d="M4 12a8 8 0 0 1 14-5.3L20 8"/><path d="M20 4v4h-4"/><path d="M20 12a8 8 0 0 1-14 5.3L4 16"/><path d="M4 20v-4h4"/>',
  users: '<circle cx="8.5" cy="8" r="3"/><path d="M2.5 19a6 6 0 0 1 12 0"/><circle cx="17" cy="9" r="2.5"/><path d="M15 19a5 5 0 0 1 7-4.5"/>',
  clipboard: '<rect x="6" y="4" width="12" height="17" rx="2"/><rect x="9" y="2.3" width="6" height="3" rx="1"/><path d="M9 11h6M9 15h6"/>',
  cart: '<circle cx="9" cy="20" r="1.3"/><circle cx="17" cy="20" r="1.3"/><path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.6L21 8H6.5"/>',
  mapPin: '<path d="M12 21s7-6.5 7-11.5A7 7 0 0 0 5 9.5C5 14.5 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.3"/>',
  message: '<path d="M4 5h16v11H8l-4 4V5z"/>',
  star: '<path d="M12 3l2.6 5.5 6 .6-4.5 4 1.3 6-5.4-3-5.4 3 1.3-6-4.5-4 6-.6L12 3z"/>',
};

function svgIcon(name, size) {
  const s = size || 24;
  return `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">${ICON_PATHS[name] || ICON_PATHS.message}</svg>`;
}

const INDUSTRY_ICONS = {
  automotive: 'car',
  healthcare: 'heartPulse',
  realestate: 'home',
  retail: 'bag',
  travel: 'plane',
};

function usecaseIconName(title) {
  const t = title.toLowerCase();
  if (/cart|browse/.test(t)) return 'cart';
  if (/pickup|in-store/.test(t)) return 'mapPin';
  if (/loyalty|vip|repeat/.test(t)) return 'star';
  if (/intake|form/.test(t)) return 'clipboard';
  if (/routing|route|team|staff|department/.test(t)) return 'users';
  if (/reminder|confirmation|schedul|appointment/.test(t)) return 'calendar';
  if (/recovery|reactivat|recall|outreach|follow-up/.test(t)) return 'refresh';
  if (/alert|availability|inventory|sales|promotion/.test(t)) return 'bell';
  return 'message';
}

/* ---------- State ---------------------------------------------------------
   Single source of truth for the whole page. */

const state = {
  page: 'home',            // 'home' | 'vertical'
  activeIndustry: 'automotive',
  navOpen: false,
  activeFeature: -1,      // -1 = all collapsed; nothing opens until clicked
  activeUsecase: 0,
  activeWay: 0,
  demoModalOpen: false,
  demoModalIndustry: null,
};

function setState(patch) {
  Object.assign(state, typeof patch === 'function' ? patch(state) : patch);
  render();
}

/* ---------- Actions --------------------------------------------------------
   Everything a click can trigger. */

function goHome() {
  setState({ page: 'home', navOpen: false });
  window.location.hash = '';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function toggleNav() {
  setState((s) => ({ navOpen: !s.navOpen }));
}

function closeNav() {
  if (state.navOpen) setState({ navOpen: false });
}

function selectIndustry(key) {
  setState({ page: 'vertical', activeIndustry: key, navOpen: false, activeUsecase: 0 });
  window.location.hash = 'industry/' + key;
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function toggleFeature(i) {
  setState((s) => ({ activeFeature: s.activeFeature === i ? -1 : i }));
}

function toggleUsecase(i) {
  setState((s) => ({ activeUsecase: s.activeUsecase === i ? -1 : i }));
}

function toggleWay(i) {
  setState((s) => ({ activeWay: s.activeWay === i ? -1 : i }));
}

/* Every section the header links to lives on the homepage, so from an
   industry page there is nothing in the DOM to scroll to. Switch back first;
   setState renders synchronously, so the target exists by the time this
   returns. replaceState (rather than setting location.hash) clears the
   industry hash without firing 'hashchange', which would otherwise bounce
   through the router and render a second time mid-scroll. */
function ensureHomePage() {
  if (state.page === 'home') return;
  setState({ page: 'home' });
  history.replaceState(null, '', window.location.pathname);
}

function scrollToDemoForm() {
  ensureHomePage();
  const form = document.querySelector('.demo-form');
  if (!form) return;
  form.scrollIntoView({ behavior: 'smooth', block: 'center' });
  const firstField = form.querySelector('input');
  if (firstField) setTimeout(() => firstField.focus(), 400);
}

/* Industry page banner CTAs open a popup "Receive a sample Smart Text" form
   (matching smarttext.com/try-now) right on the page, pre-scoped to that
   industry's own sample messages, instead of navigating away. */
function openDemoModal(industryKey) {
  setState({ demoModalOpen: true, demoModalIndustry: industryKey });
}

function closeDemoModal() {
  setState({ demoModalOpen: false, demoModalIndustry: null });
}

function scrollToId(id) {
  ensureHomePage();
  const el = document.getElementById(id);
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/* ---------- Small render helpers ------------------------------------- */

function esc(str) {
  return String(str).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}

function statCard(s) {
  return `<div class="stat-card"><div class="stat-n">${esc(s.n)}</div><div class="stat-l">${esc(s.l)}</div></div>`;
}

function accordionItem({ idx, title, desc, active, action, video }) {
  /* Feature animations are ambient illustration, not media the visitor is
     meant to operate: no controls, muted autoplay, looping. Fresh markup is
     rendered each time an item expands, so autoplay starts it from the top. */
  const media = video
    ? `<video class="accordion-video" src="${esc(video)}" autoplay muted loop playsinline
              preload="auto" disablepictureinpicture tabindex="-1" aria-hidden="true"></video>`
    : (action.startsWith('toggleFeature') ? `<div class="visual-placeholder">[ Visual: ${esc(title)} in the Smart Text dashboard ]</div>` : '');
  return `
    <div class="accordion-item ${active ? 'is-active' : ''}">
      <div class="accordion-head" data-action="${action}">
        <span class="accordion-title"><span class="accordion-badge">${idx}</span>${esc(title)}</span>
        <span class="accordion-chev">${active ? '▲' : '▼'}</span>
      </div>
      ${active ? `<div class="accordion-body"><p>${esc(desc)}</p>${media}</div>` : ''}
    </div>`;
}

/* Client logo strip. The track holds three copies of the list so sliding can
   continue in either direction and be silently rebased to the middle copy
   once a slide finishes, giving an endless loop without a visible jump.
   Behaviour is driven by initLogoCarousel(), deliberately outside the
   setState/render cycle: autoplay must not re-render the whole page. */
function logoCarousel() {
  const slide = (c, i) => `
    <div class="logo-slide" data-logo-index="${i % CLIENT_LOGOS.length}">
      <img src="${esc(c.logo)}" alt="${esc(c.alt)}" loading="lazy">
    </div>`;
  const track = [...CLIENT_LOGOS, ...CLIENT_LOGOS, ...CLIENT_LOGOS].map(slide).join('');
  const first = CLIENT_LOGOS[0];
  return `
    <div class="logo-carousel" data-logo-carousel>
      <div class="logo-strip-label">As chosen by leading manufacturers and dealers</div>
      <div class="logo-stage">
        <button type="button" class="logo-nav logo-nav-prev" aria-label="Previous client">&#8249;</button>
        <div class="logo-viewport">
          <div class="logo-track">${track}</div>
        </div>
        <button type="button" class="logo-nav logo-nav-next" aria-label="Next client">&#8250;</button>
      </div>
      <blockquote class="logo-quote" aria-live="polite">
        <p class="logo-quote-text">${esc(first.quote)}</p>
        <cite class="logo-quote-author">${esc(first.name)}</cite>
      </blockquote>
    </div>`;
}

function usecaseCard(u) {
  const media = u.image
    ? `<img class="usecase-image" src="${esc(u.image)}" alt="${esc(u.title)}" loading="lazy">`
    : `<div class="usecase-image-placeholder">
        <div class="usecase-image-icon">${svgIcon(usecaseIconName(u.title), 32)}</div>
        <span class="usecase-image-label">Image placeholder</span>
      </div>`;
  return `
    <div class="usecase-card">
      ${media}
      <div class="usecase-card-body">
        <h3>${esc(u.title)}</h3>
        <p>${esc(u.desc)}</p>
      </div>
    </div>`;
}

function sampleOptionCard(opt) {
  return `
    <label class="sample-option">
      <input type="radio" name="sampleChoice" value="${esc(opt.key)}" required>
      <span class="sample-option-name">${esc(opt.name)}</span>
      <span class="sample-option-desc">${esc(opt.desc)}</span>
    </label>`;
}

function demoForm() {
  const title = 'Book a Demo';
  const sub = 'Tell us a bit about your business and we’ll show you exactly how Smart Text can work for you.';
  return `
    <div class="demo-copy">
      <div class="demo-title">${title}</div>
      <p class="demo-sub">${esc(sub)}</p>
      <div class="pill-row">
        <span class="pill">GDPR Friendly</span>
        <span class="pill">Real-Time Tracking &amp; Analytics</span>
      </div>
    </div>
    <form class="demo-form" data-action="submitDemoForm" novalidate>
      <div class="form-success" hidden>
        <div class="form-success-icon">✓</div>
        <div class="form-success-title">Thanks, your request is on its way.</div>
        <p>We'll be in touch within one business day.</p>
      </div>
      <div class="form-fields">
        <label class="field">
          <span>I am getting in touch as a</span>
          <select name="contactType" required>
            <option value="" disabled selected>Select one</option>
            <option value="Business">Business</option>
            <option value="Agency">Agency</option>
          </select>
          <span class="field-error"></span>
        </label>
        <div class="field-row">
          <label class="field">
            <span>First name</span>
            <input type="text" name="firstName" required autocomplete="given-name">
            <span class="field-error"></span>
          </label>
          <label class="field">
            <span>Last name</span>
            <input type="text" name="lastName" required autocomplete="family-name">
            <span class="field-error"></span>
          </label>
        </div>
        <label class="field">
          <span>Company</span>
          <input type="text" name="company" required autocomplete="organization">
          <span class="field-error"></span>
        </label>
        <label class="field">
          <span>Phone number</span>
          <input type="tel" name="phone" required autocomplete="tel">
          <span class="field-error"></span>
        </label>
        <label class="field">
          <span>Work email</span>
          <input type="email" name="email" required autocomplete="email">
          <span class="field-error"></span>
        </label>
        <label class="field">
          <span>Anything we should know?</span>
          <textarea name="notes" rows="3"></textarea>
        </label>
        <label class="field checkbox-field">
          <input type="checkbox" name="consent" required>
          <span class="checkbox-label">I am happy to be contacted about Smart Text and receive further information.</span>
          <span class="field-error"></span>
        </label>
        <button type="submit" class="btn btn-primary btn-block">Book a Demo</button>
        <p class="demo-call-alt">OR Call us <a href="tel:+35319073288">+353 (0)1 907 3288</a></p>
      </div>
    </form>`;
}

/* Popup version of the "Receive a sample Smart Text" form, opened from an
   industry page banner CTA. The sample picker is scoped to that industry's
   own use cases only, e.g. a healthcare visitor never sees an automotive
   sample, so the follow-up they receive is actually relevant to them. */
function demoModal() {
  if (!state.demoModalOpen) return '';
  const industry = INDUSTRY_DATA[state.demoModalIndustry];
  const samples = (industry && industry.samples) || SAMPLE_OPTIONS;
  return `
    <div class="modal-overlay">
      <div class="modal-card">
        <button type="button" class="modal-close" data-action="closeDemoModal" aria-label="Close">&times;</button>
        <div class="modal-title">Receive a sample Smart Text</div>
        <p class="modal-sub">${industry ? `See how Smart Text looks for ${esc(industry.name)}.` : 'See exactly how Smart Text looks to your customers.'}</p>
        <form class="demo-form modal-form" data-action="submitDemoForm" novalidate>
          <div class="form-success" hidden>
            <div class="form-success-icon">✓</div>
            <div class="form-success-title">Thanks, your sample text is on its way.</div>
            <p>We'll also follow up within one business day.</p>
          </div>
          <div class="form-fields">
            <label class="field">
              <span>First name</span>
              <input type="text" name="firstName" required autocomplete="given-name">
              <span class="field-error"></span>
            </label>
            <label class="field">
              <span>Last name / company <em>(optional)</em></span>
              <input type="text" name="lastNameCompany" autocomplete="family-name">
            </label>
            <label class="field">
              <span>Email</span>
              <input type="email" name="email" required autocomplete="email">
              <span class="field-error"></span>
            </label>
            <label class="field">
              <span>Mobile number (UK &amp; Irish mobiles only)</span>
              <input type="tel" name="mobile" required autocomplete="tel">
              <span class="field-error"></span>
            </label>
            <label class="field checkbox-field">
              <input type="checkbox" name="consent" required>
              <span class="checkbox-label">I am happy to receive a sample Smart Text and further information. <span class="required-tag">(required)</span></span>
              <span class="field-error"></span>
            </label>
            <div class="field sample-picker">
              <span>Choose your sample</span>
              <div class="sample-options">
                ${samples.map(sampleOptionCard).join('')}
              </div>
              <span class="field-error"></span>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Send me a Smart Text!</button>
          </div>
        </form>
      </div>
    </div>`;
}

/* ---------- Page renderers --------------------------------------------- */

function renderHome() {
  const industries = Object.entries(INDUSTRY_DATA).map(([key, d]) => ({ key, ...d }));

  return `
  <div>
    <section class="hero">
      <div class="hero-glow"></div>
      <div class="hero-inner">
        <h1>Turn Customer Data Into <span class="accent">Business Growth</span></h1>
        <p class="hero-sub">Smart Text transforms customer data into personalised, interactive mobile experiences that generate leads, increase bookings and drive measurable business growth. Every message is designed to encourage action and every interaction is tracked.</p>
        <div class="trust-line">GDPR-friendly customer engagement platform, built for UK &amp; EU businesses.</div>
        <div class="hero-ctas">
          <button class="btn btn-primary btn-lg" data-action="scrollToDemoForm">Book a Demo</button>
        </div>
      </div>
      <div class="stat-grid stat-grid-5">
        ${HOME_STATS.map(statCard).join('')}
      </div>
      <div class="visual-placeholder visual-placeholder-lg">[ Product visual: phone mockup showing an inbound SMS lead flowing into the Smart Text dashboard ]</div>
    </section>

    <section class="section" id="industries">
      <div class="eyebrow">Industries We Serve</div>
      <h2>Built for how your industry connects with customers.</h2>
      <p class="section-lead">The same powerful platform, tailored to the way your customers engage, enquire and buy.</p>
      <div class="card-row">
        ${industries.map((d) => `
          <div class="industry-card" data-action="selectIndustry" data-key="${d.key}">
            <div class="industry-icon">${svgIcon(INDUSTRY_ICONS[d.key], 20)}</div>
            <h3>${esc(d.name)}</h3>
            <p>${esc(d.blurb)}</p>
            <div class="card-cta">Explore ${esc(d.name)} →</div>
          </div>`).join('')}
      </div>
    </section>

    <section class="section" id="platform-features">
      <div class="eyebrow">Core Platform Features</div>
      <h2>Every interaction. One platform.</h2>
      <p class="section-lead">Smart Text helps businesses activate customer data, create engaging mobile journeys and measure every outcome from a single platform.</p>
      <div class="accordion">
        ${FEATURES.map((f, i) => accordionItem({
          idx: i + 1, title: f.title, desc: f.desc, video: f.video,
          active: state.activeFeature === i, action: 'toggleFeature:' + i,
        })).join('')}
      </div>
    </section>

    <section class="section">
      <div class="eyebrow">Trusted Across Industries</div>
      <h2>Trusted by businesses that value customer engagement.</h2>
      <p class="section-lead">From independent businesses to enterprise organisations, Smart Text helps teams build stronger customer relationships and deliver measurable commercial results.</p>
      ${logoCarousel()}
    </section>

    <section class="section">
      <div class="reactivation">
        <div class="eyebrow eyebrow-on-dark">Why Smart Text</div>
        <h2>Unlock the value already sitting in your database.</h2>
        <p class="reactivation-sub">Most businesses invest heavily in acquiring new customers. Smart Text helps you create more value from the customers you already have through personalised engagement and measurable interactions.</p>
        <button class="btn btn-primary" data-action="scrollToDemoForm">Book a Demo</button>
        <div class="reactivation-grid">
          <div class="ways-list">
            ${WAYS.map((w, i) => {
              const active = state.activeWay === i;
              return `
                <div class="way-item ${active ? 'is-active' : ''}" data-action="toggleWay:${i}">
                  <b>${esc(w.title)}</b>
                  ${active ? `<p>${esc(w.desc)}</p>` : ''}
                </div>`;
            }).join('')}
          </div>
          <div class="visual-placeholder visual-placeholder-dark">[ Visual: reactivation flow in action ]</div>
        </div>
      </div>
    </section>

    <section class="section" id="testimonials">
      <h2>Trusted by teams across every industry.</h2>
      <div class="testimonial-grid">
        ${TESTIMONIALS.map((t) => `
          <div class="testimonial-card">
            <div class="testimonial-tag">${esc(t.tag)}</div>
            <p>${esc(t.quote)}</p>
            <div class="testimonial-author">${esc(t.author)}</div>
          </div>`).join('')}
      </div>
    </section>

    <section class="section" id="demo">
      <div class="demo-grid">
        ${demoForm()}
      </div>
    </section>
  </div>`;
}

function renderVertical() {
  const current = INDUSTRY_DATA[state.activeIndustry];
  const showTestimonial = state.activeIndustry === 'automotive';

  return `
  <div>
    <div class="breadcrumb">
      <span class="breadcrumb-link" data-action="goHome">Solutions</span> / ${esc(current.name)}
    </div>

    <section class="section section-tight">
      <div class="hero-video-banner">
        ${current.bannerVideo
          ? `<video class="hero-banner-video" src="${esc(current.bannerVideo)}" muted loop playsinline preload="auto"></video>`
          : current.bannerImage
            ? `<img class="hero-banner-image" src="${esc(current.bannerImage)}" alt="${esc(current.name)}">`
            : `<div class="hero-banner-placeholder-bg">[ Product mock-up: ${esc(current.name)} experience in the Smart Text app ]</div>`}
        <div class="hero-video-scrim"></div>
        <div class="hero-video-overlay">
          <h1>${esc(current.headline)}</h1>
          <p class="hero-sub">${esc(current.sub)}</p>
          <div class="hero-ctas">
            <button class="btn btn-primary" data-action="openDemoModal:${state.activeIndustry}">Receive a Smart Text</button>
          </div>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="eyebrow">Integrations</div>
      <div class="badge-row">
        ${INTEGRATION_BADGES.map((b) => `<span class="badge">${esc(b)}</span>`).join('')}
      </div>
    </section>

    <section class="section">
      <div class="eyebrow">Use Cases</div>
      <h2>How ${esc(current.name)} businesses use Smart Text.</h2>
      <div class="usecase-grid">
        ${current.usecases.map(usecaseCard).join('')}
      </div>
    </section>

    ${showTestimonial ? `
    <section class="section">
      <div class="quote-card">
        <div class="quote-tag">${esc(current.name)}</div>
        <p>${esc(current.quote)}</p>
        <div class="quote-author">${esc(current.author)}</div>
      </div>
    </section>` : ''}
  </div>`;
}

function renderNavDropdown() {
  if (!state.navOpen) return '';
  const industries = Object.entries(INDUSTRY_DATA).map(([key, d]) => ({ key, ...d }));
  return `
    <div class="nav-dropdown">
      ${industries.map((d) => `
        <div class="nav-dropdown-item" data-action="selectIndustry" data-key="${d.key}">
          <div>
            <div class="nav-dropdown-name">${esc(d.name)}</div>
            <div class="nav-dropdown-tagline">${esc(d.tagline)}</div>
          </div>
          ${d.flagship ? '<span class="flagship-badge">Flagship</span>' : ''}
        </div>`).join('')}
    </div>`;
}

/* ---------- Main render --------------------------------------------------- */

function render() {
  document.getElementById('nav-dropdown-mount').innerHTML = renderNavDropdown();
  document.getElementById('app').innerHTML = state.page === 'vertical' ? renderVertical() : renderHome();
  const modalMount = document.getElementById('modal-mount');
  if (modalMount) modalMount.innerHTML = demoModal();
  document.body.classList.toggle('modal-open', state.demoModalOpen);
  initBannerVideo();
  initLogoCarousel();
}

/* Banner video: plays only while in view, pauses (not resets) when scrolled
   away, and resumes from the same point when scrolled back into view. Loops
   naturally via the `loop` attribute while visible. */
function initBannerVideo() {
  const video = document.querySelector('.hero-banner-video');
  if (!video) return;
  video.muted = true;
  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        video.play().catch(() => {});
      } else {
        video.pause();
      }
    });
  }, { threshold: 0.35 });
  observer.observe(video);
}

/* Client logo carousel: the centred logo shows in full colour at a slight
   scale-up, the rest sit greyed back, and the quote underneath follows
   whichever logo is centred. Advances every 8s, pausing while hovered.

   `logoCarouselState` lives outside `state` on purpose. Autoplay through
   setState would re-render the entire page every 8 seconds, restarting the
   feature videos; keeping it here also lets the strip hold its position
   when an unrelated re-render (e.g. opening an accordion) rebuilds the DOM. */
const logoCarouselState = { index: 0, timer: null, quoteTimer: null, relayout: null };
const LOGO_SLIDE_MS = 1000;

function logoPerView() {
  const w = window.innerWidth;
  if (w >= 1200) return 5;
  if (w >= 992) return 4;
  if (w >= 768) return 3;
  return 1;
}

function initLogoCarousel() {
  clearInterval(logoCarouselState.timer);
  clearTimeout(logoCarouselState.quoteTimer);
  logoCarouselState.relayout = null;

  const root = document.querySelector('[data-logo-carousel]');
  if (!root) return;

  const viewport = root.querySelector('.logo-viewport');
  const track = root.querySelector('.logo-track');
  const slides = [...track.children];
  const n = CLIENT_LOGOS.length;
  /* Start on the middle copy so there's a full list to slide through either way. */
  let pos = n + (logoCarouselState.index % n);

  const quoteEl = root.querySelector('.logo-quote');

  function showQuote() {
    const client = CLIENT_LOGOS[pos % n];
    quoteEl.querySelector('.logo-quote-text').textContent = client.quote;
    quoteEl.querySelector('.logo-quote-author').textContent = client.name;
    quoteEl.classList.remove('is-fading');
  }

  function layout(animate) {
    const perView = logoPerView();
    const slideW = viewport.clientWidth / perView;
    slides.forEach((el) => { el.style.width = slideW + 'px'; });

    track.style.transition = animate ? `transform ${LOGO_SLIDE_MS}ms ease` : 'none';
    track.style.transform = `translate3d(${-(pos - Math.floor(perView / 2)) * slideW}px, 0, 0)`;
    slides.forEach((el, i) => el.classList.toggle('is-center', i === pos));

    logoCarouselState.index = pos % n;
    if (!animate) showQuote();
  }

  function go(dir) {
    pos += dir;
    layout(true);
    /* Swap the quote once the logos have finished moving, not as they start:
       mid-slide the centre logo and the quote would otherwise disagree. It
       fades out over the slide and the new text fades back in. */
    quoteEl.classList.add('is-fading');
    clearTimeout(logoCarouselState.quoteTimer);
    logoCarouselState.quoteTimer = setTimeout(showQuote, LOGO_SLIDE_MS);

    /* Once the slide has played out, jump back to the equivalent slot in the
       middle copy. The copies are identical, so this is invisible. */
    if (pos >= 2 * n || pos < n) {
      setTimeout(() => {
        pos = n + ((pos % n) + n) % n;
        layout(false);
      }, LOGO_SLIDE_MS);
    }
  }

  function startAutoplay() {
    clearInterval(logoCarouselState.timer);
    /* Honour a reduced-motion preference by leaving the strip static. */
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    logoCarouselState.timer = setInterval(() => go(1), 8000);
  }

  root.querySelector('.logo-nav-prev').addEventListener('click', () => { go(-1); startAutoplay(); });
  root.querySelector('.logo-nav-next').addEventListener('click', () => { go(1); startAutoplay(); });
  /* Clicking a logo brings it to the centre. */
  slides.forEach((el, i) => el.addEventListener('click', () => {
    if (i !== pos) { go(i - pos); startAutoplay(); }
  }));

  root.addEventListener('mouseenter', () => clearInterval(logoCarouselState.timer));
  root.addEventListener('mouseleave', startAutoplay);

  logoCarouselState.relayout = () => layout(false);
  layout(false);
  startAutoplay();
}

/* ---------- Event delegation ------------------------------------------- */

const ACTIONS = { goHome, toggleNav, scrollToDemoForm, closeDemoModal };

function handleClick(e) {
  /* Clicking the dark backdrop (not the card itself) closes the modal. */
  if (e.target.classList && e.target.classList.contains('modal-overlay')) {
    closeDemoModal();
    return;
  }

  const target = e.target.closest('[data-action]');
  if (!target) return;
  const raw = target.getAttribute('data-action');
  const [name, arg] = raw.split(':');

  if (name === 'selectIndustry') {
    selectIndustry(target.getAttribute('data-key'));
    return;
  }
  if (name === 'toggleFeature') { toggleFeature(Number(arg)); return; }
  if (name === 'toggleUsecase') { toggleUsecase(Number(arg)); return; }
  if (name === 'toggleWay') { toggleWay(Number(arg)); return; }
  if (name === 'scrollToId') { scrollToId(arg); return; }
  if (name === 'openDemoModal') { openDemoModal(arg); return; }
  if (ACTIONS[name]) ACTIONS[name]();
}

function validateForm(form) {
  let valid = true;
  form.querySelectorAll('.field-error').forEach((el) => { el.textContent = ''; });
  form.querySelectorAll('.field.has-error').forEach((el) => el.classList.remove('has-error'));

  const seenRadioGroups = new Set();
  const requiredFields = form.querySelectorAll('input[required], select[required]');
  requiredFields.forEach((input) => {
    const field = input.closest('.field');
    const errorEl = field.querySelector('.field-error');
    let message = '';

    if (input.type === 'radio') {
      if (seenRadioGroups.has(input.name)) return;
      seenRadioGroups.add(input.name);
      const anyChecked = form.querySelector(`input[name="${input.name}"]:checked`);
      if (!anyChecked) message = 'Please choose an option.';
    } else if (input.type === 'checkbox') {
      if (!input.checked) message = 'Please confirm to continue.';
    } else if (!input.value.trim()) {
      message = input.tagName === 'SELECT' ? 'Please choose an option.' : 'This field is required.';
    } else if (input.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value)) {
      message = 'Enter a valid email address.';
    } else if (input.type === 'tel' && input.value.replace(/[^0-9]/g, '').length < 7) {
      message = 'Enter a valid phone number.';
    }

    if (message) {
      valid = false;
      field.classList.add('has-error');
      errorEl.textContent = message;
    }
  });
  return valid;
}

function handleSubmit(e) {
  const form = e.target.closest('form[data-action="submitDemoForm"]');
  if (!form) return;
  e.preventDefault();

  if (!validateForm(form)) return;

  const fields = form.querySelector('.form-fields');
  const success = form.querySelector('.form-success');
  fields.hidden = true;
  success.hidden = false;
  form.reset();

  /* Give the thank-you state its own URL so it can be wired up as a GA4
     conversion destination. pushState (not location.hash=) is deliberate:
     it doesn't fire 'hashchange', so the app's hash router won't re-render
     the page and wipe out the success message we just showed. */
  history.pushState(null, '', '#thank-you');
}

document.addEventListener('click', handleClick);
document.addEventListener('submit', handleSubmit);
document.addEventListener('click', (e) => {
  if (!state.navOpen) return;
  if (e.target.closest('.nav-solutions') || e.target.closest('.nav-dropdown')) return;
  closeNav();
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && state.navOpen) closeNav();
});
/* Registered once, not per render, so re-renders don't stack up listeners.
   Slide widths are pixel values derived from viewport width, so they need
   recalculating whenever it changes. */
window.addEventListener('resize', () => {
  if (logoCarouselState.relayout) logoCarouselState.relayout();
});

/* ---------- Boot + hash routing ----------------------------------------- */

function applyHash() {
  const hash = window.location.hash.replace(/^#\/?/, '');
  const m = /^industry\/([a-z]+)$/.exec(hash);
  if (m && INDUSTRY_DATA[m[1]]) {
    setState({ page: 'vertical', activeIndustry: m[1] });
  } else {
    setState({ page: 'home' });
    /* Sections are rendered by JS, so by the time the browser would have
       handled #foo natively the target didn't exist yet. Scroll to it here
       instead, which is what makes cross-page links like
       agents.html -> index.html#platform-features work. */
    if (hash === 'demo') setTimeout(scrollToDemoForm, 0);
    else if (hash && document.getElementById(hash)) setTimeout(() => scrollToId(hash), 0);
  }
}

window.addEventListener('hashchange', applyHash);
applyHash();
render();
