/* ==========================================================================
   Smart Text: homepage app logic
   Plain vanilla JS. No build step, no framework. Edit this file directly.
   ========================================================================== */

/* ---------- Content -------------------------------------------------------
   All copy lives here. Edit these objects to change what's on the page. */

const INDUSTRY_DATA = {
  automotive: {
    name: 'Automotive', tagline: 'Our flagship market', flagship: true,
    blurb: 'Scheduled messaging for sales events, trade-ins, sales follow-up and service reminders.',
    headline: 'Your database already knows who is due an upgrade.',
    sub: 'It also knows who is nearing the end of a PCP and who is due a service. Smart Text turns that into sales leads and booked appointments, sent from the customer data you already hold.',
    howHeading: 'From your database to a booked appointment.',
    /* Step 3 of "How it works", in this industry's own terms. */
    scheduleTiming: 'Choose when it goes out, timed around a sales event, a PCP end date, or a service due date.',
    /* The reader's own situation, in their words, before any product talk. */
    situation: [
      { title: 'Finance timing slips', desc: 'Customers reach the end of a PCP or lease before anyone gets in touch about their options.' },
      { title: 'Reminders get missed', desc: 'Service and recall notices go out by post or email, and a good share are never opened.' },
      { title: 'Past customers go quiet', desc: 'Buyers and service customers from previous years are still in your database, with nothing scheduled to bring them back in.' },
    ],
    bannerImage: 'https://images.unsplash.com/photo-1643142314913-0cf633d9bbb5?w=1600&q=80&auto=format&fit=crop',
    stats: [{ n: '98%', l: 'message read rate' }, { n: '45%', l: 'service reminder response' }, { n: '3.2x', l: 'trade-in lead conversion' }, { n: '1,200+', l: 'dealerships' }],
    usecases: [
      { title: 'Sales events', desc: 'Invite customers to sales events and new model launches, with one tap to book an appointment or register interest.', image: 'https://images.unsplash.com/photo-1771284848890-ae12d143a8a7?w=700&q=80&auto=format&fit=crop' },
      { title: 'Trade-ins', desc: 'Re-engage past buyers with trade-in offers timed to the end of their PCP or finance agreement.', image: 'https://images.unsplash.com/photo-1653565217811-85b41bcd1edb?w=700&q=80&auto=format&fit=crop' },
      { title: 'Service reminders', desc: 'Schedule texts to go out when a service is due or a recall is issued, cutting missed appointments.', image: 'https://images.unsplash.com/photo-1615906655593-ad0386982a0f?w=700&q=80&auto=format&fit=crop' },
    ],
    /* Every real Smart Text reference is automotive, so this is the one page
       that can show named customers. They come from CLIENT_LOGOS, which is
       taken from smarttext.com, rather than being restated here. */
    clientProof: true,
    samples: [
      { key: 'usedcars', name: 'Used Cars', desc: 'Used Car Sales Event' },
      { key: 'bmw', name: 'BMW', desc: 'Sales Event' },
      { key: 'ford', name: 'Ford', desc: 'PCP Choices' },
      { key: 'volkswagen', name: 'Volkswagen', desc: 'Aftersales Promotion' },
      { key: 'audi', name: 'Audi', desc: 'Service Reminder' },
    ],
    faqs: [
      { q: 'Do I need to connect any dealer software to use Smart Text?', a: "No. Smart Text works from an uploaded customer database or a CRM export, so there's no dependency on your existing dealer systems." },
      { q: 'Can service reminders be scheduled around service due dates?', a: 'Yes. Once your customer data is uploaded, you can schedule reminder campaigns to go out at the right time.' },
      { q: 'Is Smart Text built for single dealerships only, or multi-franchise groups too?', a: 'Both. Smart Text scales from a single dealership to multi-brand dealer groups.' },
    ],
  },
  healthcare: {
    name: 'Healthcare & Medical', tagline: 'Patient engagement & scheduling', flagship: false,
    blurb: 'Improve patient engagement and encourage more bookings through personalised mobile communications.',
    headline: 'Your patient list already knows who is overdue a check-up.',
    sub: 'Smart Text turns that into confirmed appointments, with reminders and recall outreach scheduled around when patients are actually due.',
    howHeading: 'From your patient list to a confirmed appointment.',
    situation: [
      { title: 'No-shows cost the day', desc: 'A missed appointment is a slot that cannot be refilled at short notice, and ringing round to confirm takes time reception does not have.' },
      { title: 'Recalls slip', desc: 'Patients due a check-up or screening drop off the list because nobody has time to work through it.' },
      { title: 'Reception is stretched', desc: 'Confirming and rescheduling by phone eats into the day, and calls get missed at the busiest times.' },
    ],
    bannerImage: 'https://images.unsplash.com/photo-1576091160550-2173dba999ef?w=1600&q=80&auto=format&fit=crop',
    stats: [{ n: '35%', l: 'reduction in no-shows' }, { n: '92%', l: 'reminder read rate' }, { n: '2.5x', l: 'recall response' }, { n: '600+', l: 'practices' }],
    usecases: [
      { title: 'Appointment reminders', desc: 'Cut no-shows with scheduled reminders synced to your booking system.', image: 'https://images.unsplash.com/photo-1758691462878-6edc3d3da1be?w=700&q=80&auto=format&fit=crop' },
      { title: 'Recall & preventive care outreach', desc: 'Re-engage patients due for checkups, cleanings, or screenings.', image: 'https://images.unsplash.com/photo-1758691462413-b07dee2933fe?w=700&q=80&auto=format&fit=crop' },
      { title: 'Patient intake by text', desc: 'Send forms and instructions ahead of the visit, reducing front-desk time.', image: 'https://images.unsplash.com/photo-1758691462814-485c3672e447?w=700&q=80&auto=format&fit=crop' },
      { title: 'Post-visit follow-up', desc: 'Schedule check-ins after appointments, with replies routed to the right staff.', image: 'https://images.unsplash.com/photo-1758691462858-f1286e5daf40?w=700&q=80&auto=format&fit=crop' },
    ],
    samples: [
      { key: 'appointment', name: 'Appointment Reminder', desc: 'Reduce missed visits' },
      { key: 'recall', name: 'Recall Outreach', desc: 'Checkups & screenings due' },
      { key: 'intake', name: 'Patient Intake', desc: 'Forms sent ahead of the visit' },
      { key: 'followup', name: 'Post-Visit Follow-Up', desc: 'Check in after care' },
    ],
    faqs: [
      { q: 'Is Smart Text suitable for use with patient data?', a: 'Smart Text is GDPR friendly, but your practice remains responsible for ensuring patient consent and clinical data handling meet the regulations that apply to you.' },
      { q: 'Can patients reply directly to a message with a question?', a: 'Smart Text is not a two-way messaging tool. Patient responses are captured through a linked action such as confirm, book, or reschedule, rather than a free-text reply conversation.' },
      { q: 'Does Smart Text replace our practice management or booking software?', a: 'No. It works alongside it, using messaging to drive patients to actions in your existing system.' },
    ],
  },
  realestate: {
    name: 'Property & Real Estate', tagline: 'Listings, showings & lead routing', flagship: false,
    blurb: 'Generate more viewings and property enquiries with interactive campaigns tailored to every buyer.',
    headline: 'Your database is full of buyers who never got a call back.',
    sub: 'Smart Text re-engages registered buyers with the listings that match what they asked for, and routes new enquiries to the right agent.',
    howHeading: 'From your database to a booked viewing.',
    situation: [
      { title: 'Registered buyers go quiet', desc: 'People register their criteria, hear nothing relevant for months, and buy through someone else.' },
      { title: 'Viewings are slow to fill', desc: 'A new listing or an open viewing needs interest quickly, and email rarely moves fast enough.' },
      { title: 'Enquiries wait for an agent', desc: 'A weekend enquiry sits unassigned while the buyer works down the list to the next agency.' },
    ],
    bannerImage: 'https://images.unsplash.com/photo-1725379448228-f87690661bfc?w=1600&q=80&auto=format&fit=crop',
    stats: [{ n: '40%', l: 'avg. open rate' }, { n: '6%', l: 'avg. booking rate' }, { n: '3x', l: 'listing inquiry conversion' }, { n: '900+', l: 'agencies' }],
    usecases: [
      { title: 'Listing alerts', desc: 'Text new and price-changed listings to buyers matching their saved criteria.', image: 'https://images.unsplash.com/photo-1758382850717-8dcb01bd1fc0?w=700&q=80&auto=format&fit=crop' },
      { title: 'Showing scheduling', desc: 'Let leads book a showing time directly from a text, no phone tag.', image: 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=700&q=80&auto=format&fit=crop' },
      { title: 'Database reactivation', desc: 'Re-engage past leads and expired listings with timely, personalised outreach.', image: 'https://images.unsplash.com/photo-1748228885250-49564b614db9?w=700&q=80&auto=format&fit=crop' },
      { title: 'Instant lead routing', desc: 'Route new enquiries to the right agent in one tap, day or night.', image: 'https://images.unsplash.com/photo-1562564055-71e051d33c19?w=700&q=80&auto=format&fit=crop' },
    ],
    samples: [
      { key: 'listing', name: 'Listing Alert', desc: 'New matching properties' },
      { key: 'showing', name: 'Showing Reminder', desc: 'Book a viewing by text' },
      { key: 'reactivation', name: 'Database Reactivation', desc: 'Re-engage past leads' },
      { key: 'routing', name: 'Instant Lead Routing', desc: 'New enquiry to the right agent' },
    ],
    faqs: [
      { q: 'Can I send different listings to different buyer segments?', a: 'Yes. Upload and segment your database, then send each group the listings that match the criteria they registered with.' },
      { q: 'Will Smart Text replace my existing CRM?', a: 'No. It integrates alongside your CRM rather than replacing it.' },
      { q: 'Can new enquiries be routed to a specific agent?', a: 'Yes. New enquiries can be routed straight to the right team member.' },
    ],
  },
  retail: {
    name: 'E-commerce & Retail', tagline: 'Cart recovery & promotions', flagship: false,
    blurb: 'Increase customer engagement, repeat purchases and campaign performance through personalised mobile experiences.',
    headline: 'Most of your next orders will come from people who already bought.',
    sub: 'Smart Text brings them back with offers matched to what they bought before, and follows up the baskets that were left behind.',
    howHeading: 'From your customer list to a completed order.',
    situation: [
      { title: 'Offers are missed', desc: 'A time-limited promotion only works if it is seen on the day it runs, and email makes that a gamble.' },
      { title: 'Baskets get abandoned', desc: 'Customers get as far as the basket and leave, and the follow-up email lands in a promotions tab.' },
      { title: 'Repeat buyers go untouched', desc: 'The customers most likely to buy again are sitting in your database with nothing scheduled for them.' },
    ],
    bannerImage: 'https://images.unsplash.com/photo-1481437156560-3205f6a55735?w=1600&q=80&auto=format&fit=crop',
    stats: [{ n: '45%', l: 'cart recovery lift' }, { n: '30%', l: 'promo redemption rate' }, { n: '3x', l: 'avg. ROAS' }, { n: '700+', l: 'retailers' }],
    usecases: [
      { title: 'Cart & browse recovery', desc: 'Win back customers who left items in their cart with a well-timed Smart Text.', image: 'https://images.unsplash.com/photo-1601598851547-4302969d0614?w=700&q=80&auto=format&fit=crop' },
      { title: 'Flash sales & promotions', desc: 'Send time-sensitive offers segmented by purchase history.', image: 'https://images.unsplash.com/photo-1546213290-e1b492ab3eee?w=700&q=80&auto=format&fit=crop' },
      { title: 'Loyalty & VIP messaging', desc: 'Reward repeat customers with early access and exclusive perks.', image: 'https://images.unsplash.com/photo-1441984904996-e0b6ba687e04?w=700&q=80&auto=format&fit=crop' },
      { title: 'In-store pickup alerts', desc: 'Notify shoppers the moment their order is ready.', image: 'https://images.unsplash.com/photo-1770013413878-2530e2c3d82b?w=700&q=80&auto=format&fit=crop' },
    ],
    samples: [
      { key: 'cartrecovery', name: 'Cart Recovery', desc: 'Win back abandoned carts' },
      { key: 'flashsale', name: 'Flash Sale', desc: 'Time-sensitive offer' },
      { key: 'loyalty', name: 'Loyalty & VIP', desc: 'Early access & perks' },
      { key: 'pickup', name: 'Pickup Alert', desc: 'Order ready notification' },
    ],
    faqs: [
      { q: 'Can I segment campaigns by purchase history?', a: "Yes. Upload and segment your customer database so each group receives offers relevant to what they've bought before." },
      { q: 'Is this a live chat widget for my website?', a: "Smart Text isn't a live two-way chat tool. It's used for outbound campaigns like cart recovery and promotions. However, it can direct your customers to a live chat or WhatsApp for lead nurturing." },
      { q: 'Can I send one-time promo codes by text?', a: 'Yes. Promo codes can be included in your campaign and every tap is tracked back to the customer record.' },
    ],
  },
  luxuryretail: {
    name: 'Luxury Retail', tagline: 'Invitations, private viewings & client care', flagship: false,
    blurb: 'Fill exhibitions and private viewings, and keep high-value clients close with personal, timed invitations.',
    headline: 'Your best client should not hear about the exhibition from someone else.',
    sub: 'Smart Text puts the invitation on their phone, with one tap to RSVP or request a private appointment, and every response recorded against the client.',
    howHeading: 'From your client book to a booked appointment.',
    scheduleTiming: 'Choose when it goes out, timed around an exhibition, a brand event, or a new collection arriving.',
    situation: [
      { title: 'Invitations go unseen', desc: 'Event invitations sent by email sit unopened, so the people you most wanted in the room never knew it was on.' },
      { title: 'The client book goes quiet', desc: 'Clients who bought once hear nothing until they happen to pass the window again.' },
      { title: 'A short event needs the right people', desc: 'An exhibition runs for a few days only, and it pays back only if enough of the right clients know in time to come in.' },
    ],
    bannerImage: 'https://images.unsplash.com/photo-1764512680324-048f158cab2b?w=1600&q=80&auto=format&fit=crop',
    usecases: [
      { title: 'Event & exhibition invitations', desc: 'Invite your client list to a private evening, an exhibition or a brand event, with one tap to RSVP.', image: 'https://images.unsplash.com/photo-1768508665663-fa483a0cb208?w=700&q=80&auto=format&fit=crop' },
      { title: 'Private viewings & appointments', desc: 'Let clients request a time with the right person, so they are expected by name when they arrive.', image: 'https://images.unsplash.com/photo-1771964990519-1af926f25f31?w=700&q=80&auto=format&fit=crop' },
      { title: 'New collections & arrivals', desc: 'Tell the clients who buy a brand when new pieces arrive, rather than hoping they see it in passing.', image: 'https://images.unsplash.com/photo-1783684443710-6f9be3b9c143?w=700&q=80&auto=format&fit=crop' },
    ],
    samples: [
      { key: 'exhibition', name: 'Exhibition Invitation', desc: 'A dated, limited-run event' },
      { key: 'viewing', name: 'Private Viewing', desc: 'Request an appointment' },
      { key: 'arrivals', name: 'New Arrivals', desc: 'New pieces, told to the right clients' },
    ],
    faqs: [
      { q: 'Is a text not too impersonal for luxury clients?', a: 'Each message is personalised from your own client data and sent to a chosen list rather than everyone. It arrives as an invitation, carries your branding, and takes one tap to respond to.' },
      { q: 'Can we invite a small selected group rather than the whole client book?', a: 'Yes. Upload and segment your client book, then send only to the clients that event or collection is for.' },
      { q: 'Can a client RSVP or ask for an appointment from the message?', a: 'Yes, through a button that records their response against their client record. Smart Text is not two-way messaging, so it captures the action rather than a reply conversation.' },
    ],
  },
  travel: {
    name: 'Travel & Leisure', tagline: 'Bookings, offers & loyalty', flagship: false,
    blurb: 'Promote time-sensitive availability, reward loyal customers and drive direct bookings.',
    headline: 'An empty room tonight is worth nothing tomorrow.',
    sub: 'Smart Text puts late availability and offers in front of guests who have already stayed with you, in time for them to book direct.',
    howHeading: 'From your guest list to a direct booking.',
    situation: [
      { title: 'Late availability goes unsold', desc: 'A room, table, or seat open tomorrow has no value the day after, and email is too slow to shift it.' },
      { title: 'Bookings go through third parties', desc: 'Guests who would happily book direct go through a platform instead, and the commission goes with them.' },
      { title: 'Past guests are forgotten', desc: 'The people most likely to return are already in your database, with nothing scheduled to bring them back.' },
    ],
    bannerVideo: 'assets/videos/travel-loyalty-repeat-guest.mp4',
    stats: [{ n: '38%', l: 'direct booking lift' }, { n: '85%', l: 'offer read rate' }, { n: '2.8x', l: 'repeat booking rate' }, { n: '500+', l: 'venues & operators' }],
    usecases: [
      { title: 'Last-minute availability alerts', desc: 'Text time-sensitive offers the moment rooms, seats, or tables open up.', image: 'https://images.unsplash.com/photo-1551882547-ff40c63fe5fa?w=700&q=80&auto=format&fit=crop' },
      { title: 'Loyalty & repeat-guest messaging', desc: 'Reward returning guests with early access, upgrades, and exclusive perks.', image: 'https://images.unsplash.com/photo-1445019980597-93fa8acb246c?w=700&q=80&auto=format&fit=crop' },
      { title: 'Upgrades & upselling', desc: 'Offer room upgrades, dining, spa treatments and experiences to guests who have already booked, adding value to every stay.', image: 'https://images.unsplash.com/photo-1776763018970-9fdf66bd4666?w=700&q=80&auto=format&fit=crop' },
      { title: 'Direct booking recovery', desc: "Re-engage browsers who didn't complete a booking with a well-timed follow-up text.", image: 'https://images.unsplash.com/photo-1584132967334-10e028bd69f7?w=700&q=80&auto=format&fit=crop' },
    ],
    samples: [
      { key: 'lastminute', name: 'Last-Minute Availability', desc: 'Rooms, seats, or tables open up' },
      { key: 'loyalty', name: 'Loyalty & Repeat Guest', desc: 'Early access & upgrades' },
      { key: 'upgrades', name: 'Upgrades & Extras', desc: 'Room upgrades and add-ons' },
      { key: 'bookingrecovery', name: 'Booking Recovery', desc: 'Re-engage browsers' },
    ],
    faqs: [
      { q: 'Can I use Smart Text to upsell to guests who have already booked?', a: 'Yes. Schedule offers for room upgrades, dining, spa treatments or experiences to go out before the stay, with a button that takes the guest straight to book the extra.' },
      { q: 'Is this only for hotels, or can travel agents and tour operators use it too?', a: 'Smart Text works for hotels, travel agencies, and tour operators alike.' },
      { q: 'Can guests reply to confirm a booking or ask a question?', a: 'Smart Text is not two-way messaging. Guest actions such as confirming are captured through a link rather than a reply conversation.' },
    ],
  },
  fitness: {
    name: 'Health & Fitness', tagline: 'Memberships, classes & retention', flagship: false,
    blurb: 'Fill classes, win back lapsed members and promote new memberships for gyms, pilates studios and fitness clubs.',
    headline: 'Your lapsed members already know where you are.',
    sub: 'Smart Text brings them back with class updates and offers, scheduled around renewal dates, new timetables and the quieter months.',
    howHeading: 'From your member list to a booked class.',
    situation: [
      { title: 'Members drift away', desc: 'Attendance drops off quietly, and by the time a membership is cancelled it is too late to change their mind.' },
      { title: 'Classes run half full', desc: 'A new timetable or an empty evening slot needs bookings quickly, and a social post rarely reaches the right people.' },
      { title: 'New joiners do not stick', desc: 'Sign-ups arrive in a rush in January and September, then fade, with nothing scheduled to keep them coming back.' },
    ],
    bannerImage: 'https://images.unsplash.com/photo-1534438327276-14e5300c3a48?w=1600&q=80&auto=format&fit=crop',
    usecases: [
      { title: 'Class & timetable alerts', desc: 'Tell members about new classes, timetable changes and spaces that open up, with one tap to book.', image: 'https://images.unsplash.com/photo-1518611012118-696072aa579a?w=700&q=80&auto=format&fit=crop' },
      { title: 'Lapsed member win-back', desc: 'Re-engage members who have stopped coming or cancelled, with an offer timed to bring them back.', image: 'https://images.unsplash.com/photo-1517836357463-d25dfeac3438?w=700&q=80&auto=format&fit=crop' },
      { title: 'Membership renewals', desc: 'Schedule reminders ahead of renewal dates, with a clear reason to stay on.', image: 'https://images.unsplash.com/photo-1540497077202-7c8a3999166f?w=700&q=80&auto=format&fit=crop' },
      { title: 'Intro offers & open days', desc: 'Promote trial passes, open days and new studio launches to the enquiries already in your database.', image: 'https://images.unsplash.com/photo-1571019613454-1cb2f99b2d8b?w=700&q=80&auto=format&fit=crop' },
    ],
    samples: [
      { key: 'classalert', name: 'Class Alert', desc: 'New class or a space opens up' },
      { key: 'winback', name: 'Member Win-Back', desc: 'Lapsed and cancelled members' },
      { key: 'renewal', name: 'Renewal Reminder', desc: 'Membership due for renewal' },
      { key: 'introoffer', name: 'Intro Offer', desc: 'Trial pass or open day' },
    ],
    faqs: [
      { q: 'Does Smart Text work for a single studio as well as a gym group?', a: 'Yes. It works for a single pilates or yoga studio through to multi-site gym groups.' },
      { q: 'Can members book a class straight from the message?', a: 'Yes. A message can include a button that takes the member to your booking page. Smart Text is not two-way messaging, so the booking happens through that link rather than a reply.' },
      { q: 'Can I target members who have stopped attending?', a: 'Yes, if your member data records it. Upload and segment your database by last visit, membership type or renewal date, then send each group a message that fits.' },
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
  { title: 'Integrations', desc: 'Smart Text integrates seamlessly with your CRM system, bringing your customer data straight into the platform so your marketing is easier to plan, target and run.', video: 'assets/videos/integrations.mp4' },
  { title: 'Analytics and Reporting', desc: 'Understand exactly how customers engage. Track opens, clicks, conversions and campaign performance in real time to optimise every campaign.', video: 'assets/videos/analytics-reporting.mp4' },
  { title: 'Campaign Builder and Templates', desc: 'Launch personalised campaigns in minutes using flexible templates that support every stage of your customer journey, from awareness to conversion.', video: 'assets/videos/campaign-builder.mp4' },
  { title: 'Lead Routing', desc: 'Route responses to the right person or team so every opportunity gets followed up quickly.', video: 'assets/videos/lead-routing.mp4' },
];

/* Subscription plans. Annual figures are the supplied ones rather than
   derived, so what's shown always matches billing exactly: twelve months for
   the price of eleven, i.e. the monthly rate is the saving. The Smart plan is
   monthly-only. */
const PLANS = [
  {
    name: 'Smart Plan',
    tagline: 'For small teams getting started',
    monthly: 125,
    trial: '14-day free trial',
    annual: null,
    annualNote: 'Annual billing not available on Starter',
    features: [
      { label: 'Smart Texts', value: 'Up to 10,000 / month' },
      { label: 'Users', value: 'Up to 3' },
      { label: 'Clients / Branches', value: 'Up to 5' },
      { label: 'Report Viewers', value: 'Up to 10' },
      { label: 'Campaigns', value: 'Up to 10 / month' },
      { label: 'CTA Buttons', value: 'Single button only' },
      { label: 'Standard SMS' },
      { label: 'Forms', included: false },
    ],
  },
  {
    name: 'Smarter Plan',
    tagline: 'For growing businesses',
    popular: true,
    monthly: 249,
    annual: { perMonth: 228.25, billed: 2739, save: 249 },
    features: [
      { label: 'Smart Texts', value: 'Up to 30,000 / month' },
      { label: 'Users', value: 'Up to 10' },
      { label: 'Clients / Branches', value: 'Up to 30' },
      { label: 'Report Viewers', value: 'Up to 60' },
      { label: 'Campaigns', value: 'Up to 30 / month' },
      { label: 'CTA Buttons', value: '2+ buttons' },
      { label: 'Standard SMS' },
      { label: 'Forms' },
    ],
  },
  {
    name: 'Smartest Plan',
    tagline: 'For large-scale operations',
    monthly: 495,
    annual: { perMonth: 453.75, billed: 5445, save: 495 },
    features: [
      { label: 'Smart Texts', value: 'Unlimited' },
      { label: 'Users', value: 'Unlimited' },
      { label: 'Clients / Branches', value: 'Unlimited' },
      { label: 'Report Viewers', value: 'Unlimited' },
      { label: 'Campaigns', value: 'Unlimited' },
      { label: 'CTA Buttons', value: '2+ buttons' },
      { label: 'Standard SMS' },
      { label: 'Forms' },
    ],
  },
];

/* ---------- Agents ----------------------------------------------------------
   Merged in from the old agents.js. Agents seed from here and are then kept in
   the browser's localStorage, so admin edits survive a reload but are local to
   that browser — there is no shared backend. To change what every visitor
   sees, edit SEED_AGENTS and redeploy.

   The Agent Portal password below is a soft, client-side gate only: it ships
   in this file to every visitor and anyone can read or bypass it. Replace it
   with real auth before trusting it with anything sensitive. */

const ADMIN_PASSWORD = 'DealerMarketing2026'; // change this, or replace with real auth

const SEED_AGENTS = [
  {
    id: 'eca-south',
    name: 'ECA South',
    region: 'United Kingdom',
    type: 'UK Agent',
    codes: ['SO', 'PO', 'BH', 'GU', 'RG', 'SP'],
    lat: 50.9097,
    lng: -1.4044,
    blurb: 'Covering Southern England: Southampton, Portsmouth, Bournemouth, Guildford, Reading and Salisbury postcode areas.',
    email: '',
    phone: '',
  },
  {
    id: 'dealer-marketing',
    name: 'Dealer Marketing',
    region: 'Ireland',
    type: 'Irish Agent',
    codes: ['IE'],
    lat: 53.3498,
    lng: -6.2603,
    blurb: 'Covering all of Ireland, nationwide.',
    email: 'dealermarketingie@gmail.com',
    phone: '',
  },
];

const AGENT_STORAGE_KEY = 'smarttext_agents_v1';
const AGENT_SESSION_KEY = 'smarttext_admin_unlocked';

/* The same four steps whatever the industry, because it is the same product.
   Answers "what am I actually buying", which no industry page said before. */
const HOW_IT_WORKS = [
  { title: 'Upload your database', desc: 'Import your customer database or a CRM export, then segment it down to the customers this campaign is for.' },
  { title: 'Build the message', desc: 'Add your branding, the offer, and one clear action such as book, confirm, or enquire.' },
  /* An industry can replace this with its own scheduleTiming. */
  { title: 'Schedule the send', desc: 'Choose when it goes out, timed around a service due date, a finance end date, or an event.' },
  { title: 'Track every tap', desc: 'See who opened, who tapped, and who acted, tracked back to the individual customer record.' },
];

/* Three FAQs that appear on every industry page, tackling the misconceptions
   that come up most often. Kept identical across every industry page by design. */
const STANDARD_FAQS = [
  {
    q: 'Is Smart Text a two-way messaging or live chat tool?',
    a: "No. Smart Text is a database reactivation and customer engagement platform, built for one-to-many personalised outbound messaging. It's not built for two-way conversations like WhatsApp Business or live chat.",
  },
  {
    q: 'Can Smart Text be connected to my CRM?',
    a: 'Yes. Smart Text integrates with your existing CRM so customer data and campaign activity stay in sync.',
  },
  {
    q: 'Can I integrate WhatsApp for lead nurturing?',
    a: 'Yes. Smart Text can integrate with WhatsApp to support your lead nurturing journeys.',
  },
];

const PRICING_FAQ = [
  { q: 'What counts as a Smart Text?', a: 'One message delivered to one recipient. Replies routed back to your team and link taps are tracked at no extra cost.' },
  { q: 'Can I change plan later?', a: 'Yes. You can move up or down at any point, and the change applies from your next billing date.' },
  { q: 'What happens if I exceed my monthly volume?', a: 'Nothing stops working. We get in touch to talk through moving you to the plan that fits your usage.' },
  { q: 'How does annual billing work?', a: 'You pay for eleven months up front and get twelve, on the Smarter and Smartest plans. The Smart plan is billed monthly only.' },
  { q: 'Is there a contract or setup fee?', a: 'No setup fee. Monthly plans run month to month; annual plans run for the twelve months you have paid for.' },
];

/* Privacy statement, copied verbatim from the Privacy Statement section of
   smarttext.com/cookie-policy. It is Dealer Marketing Limited's own legal
   text: do not reword it here. Anything that needs changing should be
   changed on the live site too, so the two stay identical.

   Blocks are { h } for a heading, { p } for a paragraph, { list } for a
   bulleted list, and { intro } for the lead-in above a list. */
const PRIVACY_POLICY = [
  { p: 'Dealer Marketing Limited is committed to protecting the Personal Data of the Users of our Services. This policy describes our data protection practices and how we use and collect the Personal Data. Our processing of your Personal Data is needed for us to deliver the service to you. We may also process your Personal Data in order to comply with our legal obligations as explained below or due to our legitimate interests.' },
  { p: 'This document refers to personal data, which is defined as information concerning any living person (a natural person who hereafter will be called the Data Subject) that is not already in the public domain. The General Data Protection Regulation (GDPR) seeks to protect and enhance the rights of data subjects. These rights cover the safeguarding of personal data, protection against the unlawful processing of personal data and the unrestricted movement of personal data within the EU. It should be noted that GDPR does not apply to information already in the public domain.' },
  { p: 'Dealer Marketing Limited is strongly committed to protecting the privacy of its clients. The intent of this privacy statement is to detail the information Dealer Marketing Limited may gather about individuals and how that information is used. You should read this statement carefully before working with Dealer Marketing Limited.' },

  { h: 'Personal Data' },
  { p: 'Dealer Marketing Limited uses the information collected from you to provide quotations, make telephone contact and to email you marketing information which Dealer Marketing Limited believes may be of interest to you and your business. In you making initial contact you agree to Dealer Marketing Limited maintaining a marketing dialogue with you until you either opt out (which you can do at any stage) or we decide to desist in promoting our services. Dealer Marketing Limited also acts on behalf of its clients in the capacity of data processor.' },
  { p: 'Some personal data may be collected about you from the forms and surveys you complete, from records of our correspondence and phone calls and details of your visits to our websites, including but not limited to personally identifying information like Internet Protocol (IP) addresses, Name & Job Title, Contact Information including email addresses, phone numbers, demographic information such as postcode, preferences and interests and other information relevant to customer surveys and/or offers as well as payment information, drafts of product and designs that we save under your Account, Content that you choose to save, communications and correspondence sent to and from you, information about purchasing habits and preferences, Order histories, and/or Account histories. Dealer Marketing Limited may from time to time use such information to identify its visitors.' },
  { p: 'The Dealer Marketing Limited websites use cookies, which is a string of information that a website stores on a visitor’s computer, and that the visitor’s browser provides to the website each time the visitor returns. More specifically a “cookie” is a small text file which is stored, either on your hard drive, or in memory until your browser is closed.' },
  { p: 'You are entitled to object to our use of cookies and you can disable cookies on your website browser.' },
  { p: 'We also collect statistical data via our websites, which allows us to assess the number of visitors to the websites and to identify what page is viewed most frequently.' },

  { h: 'Purpose and Use of Personal Data' },
  { intro: 'We use your Personal Data, including your Content, for the following purposes:' },
  { list: [
    'To provide you with the Services and to evaluate, modify and enhance the Services;',
    'To communicate with you and to respond to your requests;',
    'To provide you with customer service and support;',
    'For corporate Account management purposes;',
    'To help keep our Websites safe and secure and to improve our Websites.',
  ] },
  { p: 'We use Automatic Information to administer our Websites and track user activities on the Websites. We will create anonymous data records from Personal Data by excluding information (such as your name) that makes the data personally identifiable to you. We use such Anonymous Data records to analyse request and patterns so that we may enhance the Content of the Services and improve Website navigation.' },

  { h: 'Cookies Policy' },
  { p: '“Cookies” are small pieces of information stored by your internet browser that collect data such as your browser type, your operating system, web pages visited, time of visits, content viewed, advertisements viewed, and other click stream data. We use cookies to help us tailor our websites to your needs, to deliver a better, more personalised service and to remember certain choices you’ve made so you don’t have to re-enter them. Cookies also enable us to identify traffic to our websites including pages visited, visitor numbers and traffic paths taken.' },
  { p: 'We may use advertising networks to help present advertisements or other content on our website and other websites that display Dealer Marketing Limited advertisements. Advertising networks use cookies, web beacons, or similar technologies on your computer or mobile or other device to serve you advertisements or content tailored to interests you have shown by building a profile of your internet browsing our websites.' },
  { p: 'Our websites may use Google Analytics, a web analytics service provided by Google, Inc. ("Google"). Google Analytics uses "cookies" as outlined above. The information generated by the cookie about your use of the website (including your IP address) will be transmitted to and stored by Google on servers in the United States. Google will use this information for the purpose of evaluating your use of the websites, compiling reports on website activity for website operators and providing other services relating to website activity and internet usage. Google may also transfer this information to third parties where required to do so by law, or where such third parties process the information on Google\'s behalf. Google will not associate your IP address with any other data held by Google. You may refuse the use of cookies by selecting the appropriate settings on your browser, however, please note that if you do this you may not be able to use the full functionality of the websites. By using our websites, you consent to the processing of data about you by Google in the manner and for the purposes set out above.' },
  { p: 'You can prevent Google’s collection and use of data (cookies and IP address) by downloading and installing the browser plug- in available under https://tools.google.com/dlpage/gaoptout?hl=en.' },

  { h: 'Legal basis for processing any personal data' },
  { p: 'To meet Dealer Marketing Limited’s contractual obligations to clients and to also respond to enquiries, or provide services requested directly by you.' },

  { h: 'Legitimate interests pursued by Dealer Marketing Limited and/or its clients' },
  { p: 'To promote the services offered by Dealer Marketing Limited and/or to market the services and/or products offered by Dealer Marketing Limited to existing clients.' },

  { h: 'Consent' },
  { p: 'Through agreeing to this privacy notice you are consenting to Dealer Marketing Limited processing your personal data for the purposes outlined. You can withdraw consent at any time by emailing gdpr@dealermarketing.ie or writing to us at the address below.' },

  { h: 'Disclosure' },
  { p: 'Dealer Marketing Limited may on occasions pass your Personal Information to third parties exclusively to process work on its behalf. Dealer Marketing Limited requires these parties to agree to process this information based on our instructions and requirements consistent with this Privacy Statement and GDPR.' },
  { p: 'Dealer Marketing Limited do not broker or pass on information gained from your engagement without your consent. However, Dealer Marketing Limited may disclose your Personal Information to meet legal obligations, regulations or valid governmental request. Dealer Marketing Limited may also enforce its Terms and Conditions, including investigating potential violations of its Terms and Conditions to detect, prevent or mitigate fraud or security or technical issues; or to protect against imminent harm to the rights, property or safety of Dealer Marketing Limited, its clients and/or the wider community.' },

  { h: 'Third Party Service Providers' },
  { p: 'We will share your Personal Data with third party companies and individuals that perform Services on our behalf to help us provide the Platform and Services to you.' },
  { p: 'Third Party Service Providers acting on our behalf are only provided with such Personal Data reasonably required to provide the particular service for which they are retained. Our Third-Party Service Providers are obligated to keep all of your Personal Data confidential and to collect, use and disclose your Personal Data only to the extent necessary to provide the Services on our behalf. They have signed a Data Processing & Confidentiality Agreement with Dealer Marketing Limited.' },

  { h: 'Business Transfers' },
  { p: 'We may share some or all of your Personal Data in connection with or during negotiation of any merger, financing, acquisition or dissolution, transaction or proceeding involving sale, transfer, divestiture, or disclosure of all or a portion of our business or assets. In the event of an insolvency, bankruptcy, or receivership, Personal Data may also be transferred as a business asset. If another company acquires our company, business, or assets, that company will possess the Personal Data that we have collected and will assume the rights and obligations regarding your Personal Data as described in this Privacy Statement. The company may need your consent to continue handling your data.' },

  { h: 'Compliance with Law, Court Order, and Other Disclosures' },
  { p: 'You hereby acknowledge and agree that Dealer Marketing Limited may, in its sole discretion, release Account and other Personal Data when we believe such release is appropriate: (a) to comply with an applicable law, statute, regulation, Court Order, or administrative proceeding; (b) in connection with any legal investigation; (c) to investigate or assist in preventing any violation or potential violation of this Privacy Policy or our Terms of Use; or (d) to protect the rights, property, or safety of Dealer Marketing Limited, our users, or others. This may include exchanging information with other companies and organisations for fraud protection and credit risk reduction.' },

  { h: 'Retention Policy' },
  { p: 'Dealer Marketing Limited will process personal data during the duration of any contract and will continue to store only the personal data needed in accordance with Dealer Marketing Limited Data Retention Policy.' },

  { h: 'Your rights as a data subject' },
  { intro: 'At any point whilst Dealer Marketing Limited is in possession of or processing your personal data, all data subjects have the following rights:' },
  { list: [
    'Right of access – you have the right to request a copy of the information that we hold about you.',
    'Right of rectification – you have a right to correct data that we hold about you that is inaccurate or incomplete.',
    'Right to be forgotten – in certain circumstances you can ask for the data we hold about you to be erased from our records.',
    'Right to restriction of processing – where certain conditions apply you have a right to restrict the processing.',
    'Right of portability – you have the right to have the data we hold about you transferred to another organisation.',
    'Right to object – you have the right to object to certain types of processing such as direct marketing.',
    'Right to object to automated processing, including profiling – you also have the right not to be subject to the legal effects of automated processing or profiling.',
  ] },
  { p: 'In the event that Dealer Marketing Limited refuses your request under rights of access, we will provide you with a reason as to why, which you have the right to legally challenge.' },
  { p: 'Dealer Marketing Limited at your request can confirm what information it holds about you and how it is processed' },
  { intro: 'You can request the following information:' },
  { list: [
    'Identity and the contact details of the person or organisation (Dealer Marketing Limited) that has determined how and why to process your data.',
    'Contact details of the data privacy representative, where applicable.',
    'The purpose of the processing as well as the legal basis for processing.',
    'If the processing is based on the legitimate interests of Dealer Marketing Limited or a third party such as one of its clients, information about those interests.',
    'The categories of personal data collected, stored and processed.',
    'Recipient(s) or categories of recipients that the data is/will be disclosed to.',
    'How long the data will be stored.',
    'Details of your rights to correct, erase, restrict or object to such processing.',
    'Information about your right to withdraw consent at any time.',
    'How to lodge a complaint with the supervisory authority (Data Protection Regulator).',
    'Whether the provision of personal data is a statutory or contractual requirement, or a requirement necessary to enter into a contract, as well as whether you are obliged to provide the personal data and the possible consequences of failing to provide such data.',
    'The source of personal data if it wasn’t collected directly from you.',
    'Any details and information of automated decision making, such as profiling, and any meaningful information about the logic involved, as well as the significance and expected consequences of such processing.',
  ] },

  { h: 'To access what personal data is held, identification may be required' },
  { p: 'Dealer Marketing Limited will accept the following forms of ID when information on your personal data is requested: a copy of your national ID card, driving license, passport, birth certificate and a utility bill not older than three months. A minimum of one piece of photographic ID listed above and a supporting document is required. If Dealer Marketing Limited is dissatisfied with the quality, further information may be sought before personal data can be released.' },
  { p: 'All requests should be made to gdpr@dealermarketing.ie or writing to us at the address further below.' },

  { h: 'Link to Third Party Sites' },
  { p: 'The Websites may contain links to third party Websites, e.g. Dropbox, YouTube, Facebook, Twitter, Google or third-party Websites may otherwise be associated with the Websites. Dealer Marketing Limited will endeavour to only allow companies that are meeting the GDPR requirements and will have a signed a Data Processing Agreement in place with them, but Dealer Marketing Limited is not responsible for the policies and practices employed by the owners of such third party Websites, including but not limited to their collection, use and disclosure of your Personal Data, nor does Dealer Marketing Limited offer any (and expressly disclaims any) guarantee, representation, warranty, or covenant of any kind with respect to the collection, use or disclosure of your Personal Data by any third party Website that is linked from (or is otherwise associated with) the Websites. Please consult the terms and conditions and privacy policies of any third-party Websites prior to use.' },
  { p: 'In other words, please note that this policy applies only to the Dealer Marketing Limited websites and not to the websites of other companies or organisations to which we provide links. Any third-party websites are viewed at your own risk.' },

  { h: 'Changes to Privacy Statement' },
  { p: 'Dealer Marketing Limited may change this Privacy Statement from time to time. It is advisable that you review this Privacy Statement regularly for any such changes.' },

  { h: 'Governing Law' },
  { p: 'This Privacy Statement is governed by the laws of Ireland and you submit to the exclusive jurisdiction of the Irish Courts.' },

  { h: 'Complaints' },
  { p: 'In the event that you wish to make a complaint about how your personal data is being processed by Dealer Marketing Limited or its partners, you have the right to complain to Dealer Marketing Limited. If you do not get a response within 30 days you can complain to the Data Protection Regulator.' },
  { p: 'Dealer Marketing Limited, Unit 2, Block 403, Grants Drive, Greenogue Business Park, Rathcoole, Co Dublin. T: 01 4301200 E: info@dealermarketing.ie' },
];

/* Cookie policy. Written for THIS site, not copied from the live one: the
   live site's declaration lists Hotjar, LinkedIn and CookieScript cookies
   that this site does not set. Update it when anything is added that sets
   a cookie. */
const COOKIE_POLICY = [
  { p: 'This page explains what this website stores on your device. It applies to this site only. Other Dealer Marketing Limited websites have their own cookie declarations.' },

  { h: 'What this site stores today' },
  { p: 'This site does not use advertising, tracking or analytics cookies. Nothing you do here is shared with an advertising network.' },
  { p: 'The only thing stored is your answer to the cookie banner, kept in your browser so you are not asked again on every page. It stays on your device, is never sent to us, and holds nothing that identifies you. Clearing your browser data removes it and the banner returns.' },

  { h: 'If you decline' },
  { p: 'Everything on the site keeps working. There is nothing behind the banner to switch off yet, so declining costs you nothing.' },

  { h: 'Measurement, when we add it' },
  { p: 'We plan to add website analytics so we can see which pages are useful. When we do, it will run only if you accept, it will be listed here with what it stores and for how long, and declining will keep it switched off.' },

  { h: 'Changing your mind' },
  { p: 'Use the "Change cookie choice" button at the bottom of this page. The banner will appear again so you can answer differently.' },

  { h: 'How we handle personal data' },
  { p: 'Cookies are only part of the picture. How we collect and use personal data is set out in our Privacy Statement, linked in the footer of every page.' },

  { h: 'Questions' },
  { p: 'Email gdpr@dealermarketing.ie, or write to Dealer Marketing Limited, Unit 2, Block 403, Grants Drive, Greenogue Business Park, Rathcoole, Co Dublin.' },
];

const COOKIE_CONSENT_KEY = 'smarttext_cookie_consent';

const WAYS = [
  { title: 'Upload and segment', desc: 'Build highly targeted audiences using the customer data you already own.' },
  { title: 'Personalised by record', desc: "Deliver relevant communications that reflect each customer's relationship with your business." },
  { title: 'Route to the right person', desc: 'Ensure every enquiry reaches the right team without delay.' },
  { title: 'Every tap tracked to the record', desc: 'Measure engagement, identify opportunities and continuously improve campaign performance.' },
];

const HOME_STATS = [
  { n: '44%', l: 'avg. open rate' },
  { n: '4%', l: 'avg. booking rate' },
  { n: '400:1', l: 'ROAS' },
  { n: '93%', l: 'delivery success' },
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
  dumbbell: '<path d="M7 12h10"/><rect x="4" y="7" width="3" height="10" rx="1"/><rect x="17" y="7" width="3" height="10" rx="1"/><path d="M2 10v4M22 10v4"/>',
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
  fitness: 'dumbbell',
  luxuryretail: 'star',
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
  billing: 'monthly',     // 'monthly' | 'annual' — which pricing is shown
  activeFaq: -1,          // pricing page FAQ; -1 = all collapsed
  activeUsecase: 0,
  demoModalOpen: false,
  demoModalIndustry: null,
  cookieChoice: loadCookieChoice(),  // '' until the banner is answered
};

/* Storage can throw in private mode or with site data blocked, so every read
   and write is guarded. An unreadable choice just means the banner shows. */
function loadCookieChoice() {
  try {
    const v = localStorage.getItem(COOKIE_CONSENT_KEY);
    return v === 'accepted' || v === 'declined' ? v : '';
  } catch (err) {
    return '';
  }
}

function setCookieChoice(choice) {
  try {
    if (choice) localStorage.setItem(COOKIE_CONSENT_KEY, choice);
    else localStorage.removeItem(COOKIE_CONSENT_KEY);
  } catch (err) { /* choice just won't persist */ }
  setState({ cookieChoice: choice });
}

function acceptCookies() { setCookieChoice('accepted'); }
function declineCookies() { setCookieChoice('declined'); }
function resetCookieChoice() { setCookieChoice(''); }

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
  setState({ page: 'vertical', activeIndustry: key, navOpen: false, activeUsecase: 0, activeFaq: -1 });
  window.location.hash = 'industry/' + key;
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function toggleFeature(i) {
  setState((s) => ({ activeFeature: s.activeFeature === i ? -1 : i }));
}

function toggleUsecase(i) {
  setState((s) => ({ activeUsecase: s.activeUsecase === i ? -1 : i }));
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

function setBilling(mode) {
  setState({ billing: mode });
}

function toggleFaq(i) {
  setState((s) => ({ activeFaq: s.activeFaq === i ? -1 : i }));
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

function euro(n) {
  return '€' + n.toLocaleString('en-IE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const TICK = '<svg class="plan-icon" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="9" fill="currentColor"/><path d="M6 10.4l2.6 2.6L14.2 7.4" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
const CROSS = '<svg class="plan-icon" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="9" fill="currentColor"/><path d="M7 7l6 6M13 7l-6 6" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>';

function planCard(plan) {
  const annual = state.billing === 'annual';
  /* A plan without annual terms keeps its monthly price on the annual tab,
     with a note saying why, rather than dropping out of the comparison. */
  const terms = annual && plan.annual ? plan.annual : null;
  const price = terms ? terms.perMonth : plan.monthly;

  let sub = '';
  if (terms) {
    sub = `
      <div class="plan-save">Save ${euro(terms.save)}</div>
      <div class="plan-billed">${euro(terms.billed)} billed annually</div>`;
  } else if (annual && plan.annualNote) {
    sub = `<div class="plan-note">${esc(plan.annualNote)}</div>`;
  } else if (!annual && plan.trial) {
    sub = `<div class="plan-trial">${esc(plan.trial)}</div>`;
  }

  return `
    <div class="plan-card ${plan.popular ? 'is-popular' : ''}">
      ${plan.popular ? '<div class="plan-popular">Most popular</div>' : ''}
      <div class="plan-name">${esc(plan.name)}</div>
      <div class="plan-tagline">${esc(plan.tagline)}</div>
      <div class="plan-price">${euro(price)}<span class="plan-per">/month</span></div>
      ${sub}
      <ul class="plan-features">
        ${plan.features.map((f) => `
          <li class="plan-feature ${f.included === false ? 'is-excluded' : ''}">
            ${f.included === false ? CROSS : TICK}
            <span><b>${esc(f.label)}</b>${f.value ? ': ' + esc(f.value) : ''}</span>
          </li>`).join('')}
      </ul>
      <button class="btn ${plan.popular ? 'btn-primary' : 'btn-outline'} btn-block" data-action="scrollToDemoForm">Book a Demo</button>
    </div>`;
}

/* FAQ accordion, shared by the industry pages and the pricing page so there is
   a single FAQ treatment across the site. */
function faqSection(faqs, heading) {
  if (!faqs.length) return '';
  return `
    <section class="section" id="faqs">
      <div class="eyebrow">FAQs</div>
      <h2>${esc(heading)}</h2>
      <div class="faq-list">
        ${faqs.map((f, i) => `
          <div class="faq-item${state.activeFaq === i ? ' faq-open' : ''}">
            <button type="button" class="faq-q" data-action="toggleFaq:${i}" aria-expanded="${state.activeFaq === i}">
              <span>${esc(f.q)}</span>
              <span class="faq-chev">${state.activeFaq === i ? '&#9650;' : '&#9660;'}</span>
            </button>
            ${state.activeFaq === i ? `<div class="faq-a"><p>${esc(f.a)}</p></div>` : ''}
          </div>`).join('')}
      </div>
    </section>`;
}

function renderPricing() {
  const annual = state.billing === 'annual';
  return `
  <div>
    <section class="section" id="pricing">
      <div class="eyebrow">Pricing</div>
      <h1 class="page-title">Plans that scale with your database.</h1>
      <p class="section-lead">Every plan includes standard SMS and real-time tracking. Choose annual billing and get twelve months for the price of eleven.</p>

      <div class="pricing-toggle" role="group" aria-label="Billing period">
        <button class="pricing-toggle-btn ${annual ? '' : 'is-active'}" data-action="setBilling:monthly" aria-pressed="${!annual}">Monthly</button>
        <button class="pricing-toggle-btn ${annual ? 'is-active' : ''}" data-action="setBilling:annual" aria-pressed="${annual}">
          Annual <span class="pricing-toggle-tag">1 month free</span>
        </button>
      </div>

      <div class="plan-grid">
        ${PLANS.map(planCard).join('')}
      </div>

      <p class="pricing-footnote">All prices exclude VAT. Message volumes are per calendar month. Need something beyond these limits? <a href="#demo">Talk to us</a>.</p>
    </section>

    ${faqSection(PRICING_FAQ, 'Before you choose.')}
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
      <div class="hero-grid">
        <div class="hero-inner">
          <h1>Turn Customer Data Into <span class="accent">Business Growth</span></h1>
          <p class="hero-sub">Smart Text transforms customer data into personalised, interactive mobile experiences that generate leads, increase bookings and drive measurable business growth. Every message is designed to encourage action and every interaction is tracked.</p>
          <div class="trust-line">GDPR-friendly customer engagement platform.</div>
          <div class="hero-ctas">
            <button class="btn btn-primary btn-lg" data-action="scrollToDemoForm">Book a Demo</button>
          </div>
        </div>
        <img class="hero-visual" src="assets/hero-smart-text-phone.jpg"
             alt="A Smart Text open on a phone, showing an Audi All-Access offer with a tap-through button">
      </div>
      <div class="stat-grid stat-grid-5">
        ${HOME_STATS.map(statCard).join('')}
      </div>
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

    <section class="section" id="used-by">
      <div class="eyebrow">Trusted Across Industries</div>
      <h2>Trusted by businesses that value customer engagement.</h2>
      <p class="section-lead">From independent businesses to enterprise organisations, Smart Text helps teams build stronger customer relationships and deliver measurable commercial results.</p>
      ${logoCarousel()}
    </section>

    <section class="section" id="why-smart-text">
      <div class="reactivation">
        <div class="eyebrow eyebrow-on-dark">Why Smart Text</div>
        <h2>Unlock the value already sitting in your database.</h2>
        <p class="reactivation-sub">Most businesses invest heavily in acquiring new customers. Smart Text helps you create more value from the customers you already have through personalised engagement and measurable interactions.</p>
        <button class="btn btn-primary" data-action="scrollToDemoForm">Book a Demo</button>
        <div class="reactivation-grid">
          <div class="ways-list">
            ${WAYS.map((w) => `
                <div class="way-item">
                  <b>${esc(w.title)}</b>
                  <p>${esc(w.desc)}</p>
                </div>`).join('')}
          </div>
          <video class="reactivation-video" src="assets/videos/create-campaign-square.mp4"
                 autoplay muted loop playsinline preload="auto"
                 disablepictureinpicture tabindex="-1" aria-hidden="true"></video>
        </div>
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

    ${current.situation ? `
    <section class="section">
      <div class="eyebrow">The situation</div>
      <h2>Sound familiar?</h2>
      <div class="situation-grid">
        ${current.situation.map((s) => `
          <div class="situation-card">
            <b>${esc(s.title)}</b>
            <p>${esc(s.desc)}</p>
          </div>`).join('')}
      </div>
    </section>` : ''}

    <section class="section">
      <div class="eyebrow">Use Cases</div>
      <h2>How ${esc(current.name)} businesses use Smart Text.</h2>
      <div class="usecase-grid ${current.usecases.length === 3 ? 'usecase-grid-3' : ''}">
        ${current.usecases.map(usecaseCard).join('')}
      </div>
    </section>

    ${current.samples ? `
    <section class="section">
      <div class="eyebrow">What your customer receives</div>
      <h2>Real Smart Texts, sent by businesses like yours.</h2>
      <p class="section-lead">Pick any of these and we will send it to your phone, so you can see exactly what it looks like at the other end.</p>
      <div class="sample-showcase">
        ${current.samples.map((s) => `
          <div class="sample-card" data-action="openDemoModal:${state.activeIndustry}">
            <div class="sample-card-name">${esc(s.name)}</div>
            <div class="sample-card-desc">${esc(s.desc)}</div>
          </div>`).join('')}
      </div>
    </section>` : ''}

    <section class="section">
      <div class="eyebrow">How it works</div>
      <h2>${esc(current.howHeading || 'From your database to a booked job.')}</h2>
      <div class="step-grid">
        ${HOW_IT_WORKS.map((s, i) => `
          <div class="step">
            <div class="step-n">${i + 1}</div>
            <b>${esc(s.title)}</b>
            <p>${esc(i === 2 && current.scheduleTiming ? current.scheduleTiming : s.desc)}</p>
          </div>`).join('')}
      </div>
    </section>

    <section class="section">
      <div class="eyebrow">Integrations</div>
      <div class="badge-row">
        ${INTEGRATION_BADGES.map((b) => `<span class="badge">${esc(b)}</span>`).join('')}
      </div>
    </section>

    ${current.clientProof ? `
    <section class="section">
      <div class="eyebrow">In their words</div>
      <h2>What ${esc(current.name.toLowerCase())} customers say.</h2>
      <div class="client-quote-grid">
        ${CLIENT_LOGOS.map((c) => `
          <figure class="client-quote">
            <img class="client-quote-logo" src="${esc(c.logo)}" alt="${esc(c.alt)}" loading="lazy">
            <blockquote>${esc(c.quote)}</blockquote>
            <figcaption>${esc(c.name)}</figcaption>
          </figure>`).join('')}
      </div>
    </section>` : ''}

    ${faqSection(STANDARD_FAQS.concat(current.faqs || []), 'Common questions about Smart Text.')}

    <section class="section">
      <div class="close-cta">
        <h2>See it on your own phone.</h2>
        <p>We will send you a sample Smart Text built for ${esc(current.name.toLowerCase())}, so you can judge it the way your customers will.</p>
        <div class="close-cta-actions">
          <button class="btn btn-primary" data-action="openDemoModal:${state.activeIndustry}">Receive a Smart Text</button>
          <button class="btn btn-outline" data-action="scrollToDemoForm">Book a Demo</button>
        </div>
      </div>
    </section>
  </div>`;
}

/* Legal pages. The copy is the company's own legal text held in a constant,
   so this only decides how it is laid out. */
function renderLegal({ title, intro, blocks }) {
  return `
  <div>
    <section class="section legal-page">
      <h1 class="legal-title">${esc(title)}</h1>
      ${intro ? `<p class="legal-intro">${esc(intro)}</p>` : ''}
      ${blocks.map((b) => {
        if (b.h) return `<h2 class="legal-heading">${esc(b.h)}</h2>`;
        if (b.intro) return `<p class="legal-lead">${esc(b.intro)}</p>`;
        if (b.list) return `<ul class="legal-list">${b.list.map((li) => `<li>${esc(li)}</li>`).join('')}</ul>`;
        return `<p>${esc(b.p)}</p>`;
      }).join('')}
    </section>
  </div>`;
}

function renderPrivacy() {
  return renderLegal({
    title: 'Privacy Statement',
    blocks: PRIVACY_POLICY,
  });
}

function renderCookies() {
  return renderLegal({
    title: 'Cookie Policy',
    blocks: COOKIE_POLICY,
  }).replace('</section>', `
      <div class="legal-actions">
        <button class="btn btn-outline" data-action="resetCookieChoice">Change cookie choice</button>
        <span class="legal-choice">${state.cookieChoice === 'accepted' ? 'You accepted cookies.'
          : state.cookieChoice === 'declined' ? 'You declined cookies.'
          : 'You have not answered the banner yet.'}</span>
      </div>
    </section>`);
}

/* Consent banner. It stays up until answered, because a banner that
   disappears on scroll is not a choice. Nothing here loads a tracker: it
   records the answer so that analytics, once added, can check it first. */
function renderCookieBanner() {
  if (state.cookieChoice) return '';
  return `
    <div class="cookie-banner" role="dialog" aria-label="Cookie choice">
      <div class="cookie-banner-text">
        <b>Cookies on this site</b>
        <p>We store nothing except your answer to this banner. If we add website analytics later, it will run only if you accept. <a href="#cookie-policy">Read the cookie policy</a>.</p>
      </div>
      <div class="cookie-banner-actions">
        <button class="btn btn-outline" data-action="declineCookies">Decline</button>
        <button class="btn btn-primary" data-action="acceptCookies">Accept</button>
      </div>
    </div>`;
}

/* ---------- Header + footer -------------------------------------------------
   Rendered here rather than duplicated across HTML files. Every page is a
   route inside index.html, exactly like the industry pages, so nav items are
   in-app actions or hash links — never separate files. */

function renderHeader() {
  /* The mobile menu's open state lives as a class on the <header>, which
     survives re-renders. Read it back so the button's aria stays truthful. */
  const headerEl = document.getElementById('site-header');
  const menuOpen = !!(headerEl && headerEl.classList.contains('is-open'));

  return `
    <div class="header-left">
      <img class="logo" src="assets/logo.svg" alt="Smart Text" data-action="goHome">
      <button class="nav-toggle" type="button" aria-label="Menu" aria-expanded="${menuOpen}" aria-controls="main-nav">
        <span class="nav-toggle-bar"></span>
      </button>
      <nav class="main-nav" id="main-nav">
        <button class="nav-link" data-action="scrollToId:platform-features">Platform</button>
        <div class="nav-solutions">
          <button class="nav-solutions-trigger" data-action="toggleNav">Solutions <span class="chev">▾</span></button>
          <div id="nav-dropdown-mount"></div>
        </div>
        <a class="nav-link ${state.page === 'pricing' ? 'nav-link-active' : ''}" href="#pricing-plans">Pricing</a>
        <button class="nav-link" data-action="scrollToId:used-by">Resources</button>
        <a class="nav-link ${state.page === 'agents' ? 'nav-link-active' : ''}" href="#agents">Find an Agent</a>
      </nav>
    </div>
    <div class="header-right">
      <button class="btn btn-primary" data-action="scrollToDemoForm">Book a Demo</button>
    </div>`;
}

const FOOTER_MENU = [
  { id: 'used-by', label: 'used by' },
  { id: 'platform-features', label: 'what is it?' },
  { id: 'industries', label: 'solutions' },
  { id: 'why-smart-text', label: 'why us?' },
];

const SOCIALS = [
  { name: 'Instagram', href: 'https://www.instagram.com/smarttexts/', svg: '<rect x="3" y="3" width="18" height="18" rx="5" fill="none" stroke="#fff" stroke-width="2"/><circle cx="12" cy="12" r="4" fill="none" stroke="#fff" stroke-width="2"/><circle cx="17.2" cy="6.8" r="1.3" fill="#fff"/>' },
  { name: 'LinkedIn', href: 'https://www.linkedin.com/company/smart-text', svg: '<path fill="#fff" d="M6.94 8.5H4.2V19h2.74V8.5zM5.57 4a1.6 1.6 0 1 0 0 3.2 1.6 1.6 0 0 0 0-3.2zM19.8 19h-2.73v-5.1c0-1.22-.02-2.78-1.7-2.78-1.7 0-1.96 1.33-1.96 2.7V19H10.7V8.5h2.62v1.44h.04c.36-.69 1.25-1.42 2.58-1.42 2.76 0 3.27 1.82 3.27 4.18V19z"/>' },
  { name: 'Facebook', href: 'https://www.facebook.com/smarttexts/', svg: '<path fill="#fff" d="M13.5 21v-8h2.7l.4-3.1h-3.1V7.9c0-.9.25-1.5 1.54-1.5H16.7V3.6c-.28-.04-1.25-.12-2.38-.12-2.35 0-3.96 1.44-3.96 4.08V9.9H7.65V13h2.71v8h3.14z"/>' },
];

function renderFooter() {
  const link = (id, label) => `<a class="footer-link" href="#${id}">${label}</a>`;

  return `
    <div class="footer-inner">
      <div class="footer-brand">
        <img class="footer-logo" src="assets/logo.svg" alt="Smart Text" data-action="goHome">
        <div class="footer-socials">
          ${SOCIALS.map((s) => `
            <a class="footer-social" href="${s.href}" target="_blank" rel="noopener" aria-label="Smart Text on ${s.name}">
              <svg viewBox="0 0 24 24" aria-hidden="true">${s.svg}</svg>
            </a>`).join('')}
        </div>
      </div>

      <nav class="footer-col">
        <h2 class="footer-heading">menu</h2>
        ${FOOTER_MENU.map((m) => link(m.id, m.label)).join('')}
        <a class="footer-link" href="#pricing-plans">pricing</a>
        <a class="footer-link" href="#agents">find an agent</a>
        ${link('demo', 'book a demo')}
      </nav>

      <div class="footer-col">
        <h2 class="footer-heading">contact details</h2>
        <a class="footer-link" href="tel:+35319073288">+353 (0)1 907 3288</a>
        <a class="footer-link" href="mailto:smart@smarttext.com">smart@smarttext.com</a>
        <address class="footer-address">
          Block 403, Grant's Drive,<br>
          Greenogue Business Park<br>
          Rathcoole, Co Dublin,<br>
          Ireland
        </address>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© 2026 Dealer Marketing Ltd</span>
      <a class="footer-legal-link" href="#privacy-policy">privacy statement</a>
      <a class="footer-legal-link" href="#cookie-policy">cookie policy</a>
    </div>`;
}

/* ---------- Agents page ---------------------------------------------------- */

let agents = loadAgents();
let agentFilterText = '';
let agentFilterRegion = 'all';
let selectedAgentId = null;
let adminUnlocked = sessionStorage.getItem(AGENT_SESSION_KEY) === '1';

let agentMap = null;
let markerLayer = null;
const markerRefs = {};

function loadAgents() {
  try {
    const raw = localStorage.getItem(AGENT_STORAGE_KEY);
    if (raw) {
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed) && parsed.length) return parsed;
    }
  } catch (err) { /* ignore malformed storage */ }
  return SEED_AGENTS.slice();
}

function persistAgents() {
  localStorage.setItem(AGENT_STORAGE_KEY, JSON.stringify(agents));
}

function slugify(str) {
  return String(str).toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || ('agent-' + Date.now());
}

function distinctRegions() {
  return Array.from(new Set(agents.map((a) => a.region))).sort();
}

function matchesAgentFilter(agent) {
  if (agentFilterRegion !== 'all' && agent.region !== agentFilterRegion) return false;
  const q = agentFilterText.trim().toUpperCase();
  if (!q) return true;
  if (agent.name.toUpperCase().includes(q)) return true;
  if (agent.region.toUpperCase().includes(q)) return true;
  if (agent.type.toUpperCase().includes(q)) return true;
  return agent.codes.some((c) => {
    const code = c.toUpperCase();
    return code.startsWith(q) || q.startsWith(code);
  });
}

function filteredAgents() {
  return agents.filter(matchesAgentFilter);
}

function renderAgents() {
  return `
  <div>
    <section class="section section-tight">
      <div class="eyebrow">Agent Network</div>
      <h1 class="page-title">Find your local agent.</h1>
      <p class="section-lead">Search by name, location code, or browse the map to find the Smart Text agent covering your area.</p>
    </section>

    <section class="section section-tight">
      <div class="search-bar">
        <input type="text" id="agent-search" placeholder="Search by agent name or location code (e.g. ECA South, SO, IE)…" value="${esc(agentFilterText)}">
      </div>
      <div class="chip-row" id="region-filter-mount"></div>
    </section>

    <section class="section section-tight">
      <div id="agent-map" class="agent-map"></div>
    </section>

    <section class="section section-tight">
      <div id="agent-list" class="agent-list"></div>
    </section>

    <section class="section section-tight">
      <div class="agent-cta-banner">
        <div>
          <div class="agent-cta-title">Can't find an agent in your region?</div>
          <p class="agent-cta-sub">Become the Smart Text agent for your area. Apply below and we'll be in touch.</p>
        </div>
        <button class="btn btn-primary" data-action="scrollToBecomeAgent">Become an Agent</button>
      </div>
    </section>

    <section class="section" id="become-agent">
      <div class="demo-grid">
        <div class="demo-copy">
          <div class="demo-title">Become an agent</div>
          <p class="demo-sub">Represent Smart Text in your region. Tell us a bit about your business and we'll be in touch.</p>
          <div class="pill-row">
            <span class="pill">No exclusivity commitment required to apply</span>
          </div>
        </div>
        <form class="demo-form" data-action="submitAgentApplication" novalidate>
          <div class="form-success" hidden>
            <div class="form-success-icon">✓</div>
            <div class="form-success-title">Thanks, application received.</div>
            <p>We'll review your details and get back to you shortly.</p>
          </div>
          <div class="form-fields">
            <label class="field">
              <span>Full name</span>
              <input type="text" name="fullName" required autocomplete="name">
              <span class="field-error"></span>
            </label>
            <label class="field">
              <span>Company / agency name</span>
              <input type="text" name="company" required autocomplete="organization">
              <span class="field-error"></span>
            </label>
            <label class="field">
              <span>Region / country you'd cover</span>
              <input type="text" name="region" required>
              <span class="field-error"></span>
            </label>
            <label class="field">
              <span>Email</span>
              <input type="email" name="email" required autocomplete="email">
              <span class="field-error"></span>
            </label>
            <label class="field">
              <span>Tell us about your business</span>
              <textarea name="notes" rows="3"></textarea>
            </label>
            <button type="submit" class="btn btn-primary btn-block">Submit application</button>
          </div>
        </form>
      </div>
    </section>

    <section class="section" id="agent-portal">
      <div class="eyebrow">Agent Portal</div>
      <h2>Manage agents</h2>
      <p class="section-lead">Backend-manager access only. Not visible or linked from anywhere else on the site.</p>
      <div id="admin-panel-mount" class="admin-mount"></div>
    </section>
  </div>`;
}

/* Leaflet is only needed on this one route, so it is fetched on demand rather
   than loaded on every page. */
function ensureLeaflet(cb) {
  if (typeof L !== 'undefined') { cb(); return; }
  if (document.getElementById('leaflet-js')) {
    document.getElementById('leaflet-js').addEventListener('load', cb, { once: true });
    return;
  }
  const css = document.createElement('link');
  css.rel = 'stylesheet';
  css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
  document.head.appendChild(css);

  const js = document.createElement('script');
  js.id = 'leaflet-js';
  js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
  js.addEventListener('load', cb, { once: true });
  js.addEventListener('error', () => { /* offline: list still works, map stays empty */ });
  document.head.appendChild(js);
}

function initAgentsPage() {
  renderAgentFilters();
  renderAgentList();
  renderAdminPanel();
  ensureLeaflet(initMap);
}

function initMap() {
  if (typeof L === 'undefined') return;
  const el = document.getElementById('agent-map');
  if (!el) return;
  /* The container is rebuilt on every render, so drop any previous instance. */
  if (agentMap) { agentMap.remove(); agentMap = null; }
  agentMap = L.map(el, { scrollWheelZoom: false }).setView([52.5, -3.5], 5);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 18,
  }).addTo(agentMap);
  markerLayer = L.layerGroup().addTo(agentMap);
  renderMarkers();
}

function renderMarkers() {
  if (!agentMap || !markerLayer) return;
  markerLayer.clearLayers();
  Object.keys(markerRefs).forEach((k) => delete markerRefs[k]);

  const list = filteredAgents();
  list.forEach((agent) => {
    const marker = L.marker([agent.lat, agent.lng]).addTo(markerLayer);
    marker.bindPopup(`<strong>${esc(agent.name)}</strong><br>${esc(agent.type)}<br>${esc(agent.blurb)}`);
    marker.on('click', () => selectAgent(agent.id, { fromMap: true }));
    markerRefs[agent.id] = marker;
  });

  if (list.length) {
    const bounds = L.latLngBounds(list.map((a) => [a.lat, a.lng]));
    agentMap.fitBounds(bounds, { padding: [40, 40], maxZoom: 9 });
  }
}

function selectAgent(id, opts) {
  opts = opts || {};
  selectedAgentId = id;
  renderAgentList();
  const agent = agents.find((a) => a.id === id);
  if (!agent) return;
  if (agentMap && markerRefs[id] && !opts.fromMap) {
    agentMap.setView([agent.lat, agent.lng], 10);
    markerRefs[id].openPopup();
  }
  if (!opts.fromMap) {
    const card = document.getElementById('agent-card-' + id);
    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

function renderAgentFilters() {
  const mount = document.getElementById('region-filter-mount');
  if (!mount) return;
  mount.innerHTML = `
    <button class="chip ${agentFilterRegion === 'all' ? 'is-active' : ''}" data-region="all">All regions</button>
    ${distinctRegions().map((r) => `<button class="chip ${agentFilterRegion === r ? 'is-active' : ''}" data-region="${esc(r)}">${esc(r)}</button>`).join('')}`;
}

function renderAgentList() {
  const mount = document.getElementById('agent-list');
  if (!mount) return;
  const list = filteredAgents();

  if (!list.length) {
    mount.innerHTML = '<div class="agent-empty">No agents match your search. Try a different name or location code.</div>';
    return;
  }

  mount.innerHTML = list.map((agent) => `
    <div id="agent-card-${agent.id}" class="agent-card ${selectedAgentId === agent.id ? 'is-selected' : ''}" data-agent-id="${agent.id}">
      <div class="agent-card-top">
        <div>
          <div class="agent-type">${esc(agent.type)}</div>
          <h3>${esc(agent.name)}</h3>
        </div>
        <button class="btn btn-outline btn-sm" data-action="viewOnMap" data-id="${agent.id}">View on map</button>
      </div>
      <p class="agent-blurb">${esc(agent.blurb)}</p>
      <div class="agent-meta">
        <span class="agent-region">${esc(agent.region)}</span>
        ${agent.codes.length ? `<span class="agent-codes">Codes: ${agent.codes.map(esc).join(', ')}</span>` : ''}
      </div>
      ${(agent.email || agent.phone) ? `
        <div class="agent-contact">
          ${agent.email ? `<a href="mailto:${esc(agent.email)}">${esc(agent.email)}</a>` : ''}
          ${agent.phone ? `<span>${esc(agent.phone)}</span>` : ''}
        </div>` : ''}
    </div>`).join('');
}

function refreshAgents() {
  renderAgentFilters();
  renderAgentList();
  renderMarkers();
}

function scrollToBecomeAgent() {
  const el = document.getElementById('become-agent');
  if (!el) return;
  el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  const first = el.querySelector('input');
  if (first) setTimeout(() => first.focus(), 400);
}

/* ---------- Agent admin panel ---------- */

function renderAdminPanel() {
  const mount = document.getElementById('admin-panel-mount');
  if (!mount) return;

  if (!adminUnlocked) {
    mount.innerHTML = `
      <form class="admin-login" data-action="adminLogin">
        <label class="field field-inline">
          <span>Admin password</span>
          <input type="password" name="password" autocomplete="off" required>
        </label>
        <button type="submit" class="btn btn-primary btn-sm">Unlock admin panel</button>
        <span class="admin-login-error"></span>
      </form>`;
    return;
  }

  mount.innerHTML = `
    <div class="admin-panel">
      <div class="admin-panel-header">
        <div>
          <div class="admin-panel-title">Manage agents</div>
          <p class="admin-panel-note">Changes save to this browser only (localStorage). See the note above.</p>
        </div>
        <button class="btn btn-outline btn-sm" data-action="adminLogout">Log out</button>
      </div>

      <div class="admin-agent-rows">
        ${agents.map((a) => `
          <div class="admin-agent-row">
            <div>
              <b>${esc(a.name)}</b>
              <span class="admin-agent-row-meta">${esc(a.type)} · ${esc(a.region)} · ${esc(a.codes.join(', '))}</span>
            </div>
            <button class="btn btn-outline btn-sm btn-danger" data-action="adminRemoveAgent" data-id="${a.id}">Remove</button>
          </div>`).join('') || '<p class="admin-panel-note">No agents yet.</p>'}
      </div>

      <div class="admin-add-form-wrap">
        <div class="admin-panel-title admin-panel-title-sm">Add a new agent</div>
        <form class="admin-add-form" data-action="adminAddAgent">
          <div class="field-row">
            <label class="field">
              <span>Agent / company name</span>
              <input type="text" name="name" required>
              <span class="field-error"></span>
            </label>
            <label class="field">
              <span>Type / label</span>
              <input type="text" name="type" placeholder="e.g. UK Agent" required>
              <span class="field-error"></span>
            </label>
          </div>
          <div class="field-row">
            <label class="field">
              <span>Region / country</span>
              <input type="text" name="region" placeholder="e.g. United Kingdom" required>
              <span class="field-error"></span>
            </label>
            <label class="field">
              <span>Location codes (comma separated)</span>
              <input type="text" name="codes" placeholder="e.g. SO, PO, BH">
              <span class="field-error"></span>
            </label>
          </div>
          <div class="field-row">
            <label class="field">
              <span>Latitude</span>
              <input type="text" name="lat" placeholder="e.g. 50.9097" required>
              <span class="field-error"></span>
            </label>
            <label class="field">
              <span>Longitude</span>
              <input type="text" name="lng" placeholder="e.g. -1.4044" required>
              <span class="field-error"></span>
            </label>
          </div>
          <p class="admin-panel-hint">Tip: find coordinates by right-clicking a location on Google Maps and copying the numbers shown, or use latlong.net.</p>
          <label class="field">
            <span>Short description</span>
            <input type="text" name="blurb" placeholder="What area/market do they cover?">
            <span class="field-error"></span>
          </label>
          <div class="field-row">
            <label class="field">
              <span>Contact email (optional)</span>
              <input type="email" name="email">
              <span class="field-error"></span>
            </label>
            <label class="field">
              <span>Contact phone (optional)</span>
              <input type="text" name="phone">
              <span class="field-error"></span>
            </label>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Add agent</button>
        </form>
      </div>

      <button class="btn btn-outline btn-sm" data-action="adminReset">Reset to default agents</button>
    </div>`;
}

function validateAdminAddForm(form) {
  let valid = true;
  form.querySelectorAll('.field-error').forEach((el) => { el.textContent = ''; });
  form.querySelectorAll('.field.has-error').forEach((el) => el.classList.remove('has-error'));

  form.querySelectorAll('input[required]').forEach((input) => {
    const field = input.closest('.field');
    const errorEl = field.querySelector('.field-error');
    let message = '';
    if (!input.value.trim()) {
      message = 'This field is required.';
    } else if ((input.name === 'lat' || input.name === 'lng') && isNaN(parseFloat(input.value))) {
      message = 'Enter a valid number.';
    } else if (input.type === 'email' && input.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value)) {
      message = 'Enter a valid email address.';
    }
    if (message) {
      valid = false;
      field.classList.add('has-error');
      errorEl.textContent = message;
    }
  });
  return valid;
}

function handleAdminAddAgent(form) {
  if (!validateAdminAddForm(form)) return;
  const data = new FormData(form);
  const name = data.get('name').trim();
  agents.push({
    id: slugify(name) + '-' + Math.random().toString(36).slice(2, 6),
    name,
    type: data.get('type').trim(),
    region: data.get('region').trim(),
    codes: (data.get('codes') || '').split(',').map((c) => c.trim()).filter(Boolean),
    lat: parseFloat(data.get('lat')),
    lng: parseFloat(data.get('lng')),
    blurb: (data.get('blurb') || '').trim(),
    email: (data.get('email') || '').trim(),
    phone: (data.get('phone') || '').trim(),
  });
  persistAgents();
  renderAdminPanel();
  refreshAgents();
}

function handleAdminRemoveAgent(id) {
  agents = agents.filter((a) => a.id !== id);
  persistAgents();
  renderAdminPanel();
  refreshAgents();
}

function handleAdminReset() {
  agents = SEED_AGENTS.slice();
  localStorage.removeItem(AGENT_STORAGE_KEY);
  renderAdminPanel();
  refreshAgents();
}

function handleAdminLogin(form) {
  const input = form.querySelector('input[name="password"]');
  const errorEl = form.querySelector('.admin-login-error');
  if (input.value === ADMIN_PASSWORD) {
    adminUnlocked = true;
    sessionStorage.setItem(AGENT_SESSION_KEY, '1');
    errorEl.textContent = '';
    renderAdminPanel();
  } else {
    errorEl.textContent = 'Incorrect password.';
  }
}

function handleAdminLogout() {
  adminUnlocked = false;
  sessionStorage.removeItem(AGENT_SESSION_KEY);
  renderAdminPanel();
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

const PAGES = {
  vertical: renderVertical,
  pricing: renderPricing,
  agents: renderAgents,
  privacy: renderPrivacy,
  cookies: renderCookies,
  home: renderHome,
};

function render() {
  document.getElementById('site-header').innerHTML = renderHeader();
  /* The dropdown mount only exists once the header has been written. */
  const navMount = document.getElementById('nav-dropdown-mount');
  if (navMount) navMount.innerHTML = renderNavDropdown();

  document.getElementById('app').innerHTML = (PAGES[state.page] || renderHome)();
  document.getElementById('site-footer').innerHTML = renderFooter();

  const modalMount = document.getElementById('modal-mount');
  if (modalMount) modalMount.innerHTML = demoModal();

  const cookieMount = document.getElementById('cookie-mount');
  if (cookieMount) cookieMount.innerHTML = renderCookieBanner();
  document.body.classList.toggle('modal-open', state.demoModalOpen);

  initBannerVideo();
  initLogoCarousel();
  if (state.page === 'agents') initAgentsPage();
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

const ACTIONS = {
  goHome, toggleNav, scrollToDemoForm, closeDemoModal,
  scrollToBecomeAgent, adminLogout: handleAdminLogout,
  acceptCookies, declineCookies, resetCookieChoice,
};

function handleClick(e) {
  /* Clicking the dark backdrop (not the card itself) closes the modal. */
  if (e.target.classList && e.target.classList.contains('modal-overlay')) {
    closeDemoModal();
    return;
  }

  /* Agents page: region chips and clicking a card to select it. Both are
     outside the data-action scheme, so they're handled first. */
  const chip = e.target.closest('[data-region]');
  if (chip) {
    agentFilterRegion = chip.getAttribute('data-region');
    refreshAgents();
    return;
  }
  const card = e.target.closest('.agent-card');
  if (card && !e.target.closest('[data-action]')) {
    selectAgent(card.getAttribute('data-agent-id'));
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
  if (name === 'toggleFaq') { toggleFaq(Number(arg)); return; }
  if (name === 'scrollToId') { scrollToId(arg); return; }
  if (name === 'setBilling') { setBilling(arg); return; }
  if (name === 'openDemoModal') { openDemoModal(arg); return; }

  /* Agents page actions. These act on the agent list/admin panel directly
     rather than through setState, so the map isn't torn down and rebuilt. */
  if (name === 'viewOnMap') { selectAgent(target.getAttribute('data-id')); return; }
  if (name === 'adminRemoveAgent') {
    if (confirm('Remove this agent?')) handleAdminRemoveAgent(target.getAttribute('data-id'));
    return;
  }
  if (name === 'adminReset') {
    if (confirm('Reset agents to the built-in defaults? This clears any local changes.')) handleAdminReset();
    return;
  }

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
  /* Agents page forms first — the admin ones aren't lead forms and must not
     land on the #thank-you conversion URL. */
  const loginForm = e.target.closest('form[data-action="adminLogin"]');
  if (loginForm) { e.preventDefault(); handleAdminLogin(loginForm); return; }

  const addForm = e.target.closest('form[data-action="adminAddAgent"]');
  if (addForm) { e.preventDefault(); handleAdminAddAgent(addForm); return; }

  const form = e.target.closest('form[data-action="submitDemoForm"], form[data-action="submitAgentApplication"]');
  if (!form) return;
  e.preventDefault();

  if (!validateForm(form)) return;

  const fields = form.querySelector('.form-fields');
  const success = form.querySelector('.form-success');
  fields.hidden = true;
  success.hidden = false;
  form.reset();

  /* Give the thank-you state its own URL so it can be wired up as a GA4
     conversion destination. Agent applications get their own so the two
     aren't counted as the same conversion. pushState (not location.hash=) is
     deliberate: it doesn't fire 'hashchange', so the app's hash router won't
     re-render the page and wipe out the success message we just showed. */
  const isAgentApplication = form.getAttribute('data-action') === 'submitAgentApplication';
  history.pushState(null, '', isAgentApplication ? '#thank-you-agent' : '#thank-you');
}

document.addEventListener('click', handleClick);
document.addEventListener('submit', handleSubmit);
/* Agent search filters the list and markers without a full re-render, so the
   input keeps focus as you type. */
document.addEventListener('input', (e) => {
  if (e.target.id !== 'agent-search') return;
  agentFilterText = e.target.value;
  renderAgentList();
  renderMarkers();
});
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

/* Every page is a route here — industries, pricing and agents alike. Each has
   its own URL and none has its own file. */
function applyHash() {
  const hash = window.location.hash.replace(/^#\/?/, '');

  const m = /^industry\/([a-z]+)$/.exec(hash);
  if (m && INDUSTRY_DATA[m[1]]) {
    setState({ page: 'vertical', activeIndustry: m[1] });
    window.scrollTo({ top: 0 });
    return;
  }

  if (hash === 'pricing-plans') {
    setState({ page: 'pricing' });
    window.scrollTo({ top: 0 });
    return;
  }

  if (hash === 'agents') {
    setState({ page: 'agents' });
    window.scrollTo({ top: 0 });
    return;
  }

  if (hash === 'privacy-policy') {
    setState({ page: 'privacy' });
    window.scrollTo({ top: 0 });
    return;
  }

  if (hash === 'cookie-policy') {
    setState({ page: 'cookies' });
    window.scrollTo({ top: 0 });
    return;
  }

  setState({ page: 'home' });
  /* Sections are rendered by JS, so by the time the browser would have
     handled #foo natively the target didn't exist yet — scroll here instead. */
  if (hash === 'demo') setTimeout(scrollToDemoForm, 0);
  else if (hash && document.getElementById(hash)) setTimeout(() => scrollToId(hash), 0);
}

window.addEventListener('hashchange', applyHash);
applyHash();
render();
