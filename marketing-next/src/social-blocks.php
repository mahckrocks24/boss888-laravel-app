  <?php /* 05b — SOCIAL (Owner 2026-10-05: "we do not have anything about social media... add logos of Facebook, IG, and LinkedIn";
     "the sample social media image must show the designs we have in the inspiration"; "make it an image gallery of those images, as if those
     are posted on fb, IG, and Linkedin, with their respective previews"). The images are OUR design-library renders, each re-rendered under its own business name,
     never the reference images. Logos: Font Awesome Free 6.5.2 brand icons (CC BY 4.0, fontawesome.com/license/free). */
  $icFb = '<svg viewBox="0 0 512 512" aria-hidden="true"><path fill="#0866FF" d="M512 256C512 114.6 397.4 0 256 0S0 114.6 0 256C0 376 82.7 476.8 194.2 504.5V334.2H141.4V256h52.8V222.3c0-87.1 39.4-127.5 125-127.5c16.2 0 44.2 3.2 55.7 6.4V172c-6-.6-16.5-1-29.6-1c-42 0-58.2 15.9-58.2 57.2V256h83.6l-14.4 78.2H287V510.1C413.8 494.8 512 386.9 512 256h0z"/></svg>';
  $icIg = fn (string $id) => '<svg viewBox="0 0 448 512" aria-hidden="true"><defs><linearGradient id="' . $id . '" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#FEDA75"/><stop offset=".3" stop-color="#FA7E1E"/><stop offset=".55" stop-color="#D62976"/><stop offset=".8" stop-color="#962FBF"/><stop offset="1" stop-color="#4F5BD5"/></linearGradient></defs><path fill="url(#' . $id . ')" d="M224.1 141c-63.6 0-114.9 51.3-114.9 114.9s51.3 114.9 114.9 114.9S339 319.5 339 255.9 287.7 141 224.1 141zm0 189.6c-41.1 0-74.7-33.5-74.7-74.7s33.5-74.7 74.7-74.7 74.7 33.5 74.7 74.7-33.6 74.7-74.7 74.7zm146.4-194.3c0 14.9-12 26.8-26.8 26.8-14.9 0-26.8-12-26.8-26.8s12-26.8 26.8-26.8 26.8 12 26.8 26.8zm76.1 27.2c-1.7-35.9-9.9-67.7-36.2-93.9-26.2-26.2-58-34.4-93.9-36.2-37-2.1-147.9-2.1-184.9 0-35.8 1.7-67.6 9.9-93.9 36.1s-34.4 58-36.2 93.9c-2.1 37-2.1 147.9 0 184.9 1.7 35.9 9.9 67.7 36.2 93.9s58 34.4 93.9 36.2c37 2.1 147.9 2.1 184.9 0 35.9-1.7 67.7-9.9 93.9-36.2 26.2-26.2 34.4-58 36.2-93.9 2.1-37 2.1-147.8 0-184.8zM398.8 388c-7.8 19.6-22.9 34.7-42.6 42.6-29.5 11.7-99.5 9-132.1 9s-102.7 2.6-132.1-9c-19.6-7.8-34.7-22.9-42.6-42.6-11.7-29.5-9-99.5-9-132.1s-2.6-102.7 9-132.1c7.8-19.6 22.9-34.7 42.6-42.6 29.5-11.7 99.5-9 132.1-9s102.7-2.6 132.1 9c19.6 7.8 34.7 22.9 42.6 42.6 11.7 29.5 9 99.5 9 132.1s2.7 102.7-9 132.1z"/></svg>';
  $icIn = '<svg viewBox="0 0 448 512" aria-hidden="true"><path fill="#0A66C2" d="M416 32H31.9C14.3 32 0 46.5 0 64.3v383.4C0 465.5 14.3 480 31.9 480H416c17.6 0 32-14.5 32-32.3V64.3c0-17.8-14.4-32.3-32-32.3zM135.4 416H69V202.2h66.5V416zm-33.2-243c-21.3 0-38.5-17.3-38.5-38.5S80.9 96 102.2 96c21.2 0 38.5 17.3 38.5 38.5 0 21.3-17.2 38.5-38.5 38.5zm282.1 243h-66.4V312c0-24.8-.5-56.7-34.5-56.7-34.6 0-39.9 27-39.9 54.9V416h-66.4V202.2h63.7v29.2h.9c8.9-16.8 30.6-34.5 62.9-34.5 67.2 0 79.7 44.3 79.7 101.9V416z"/></svg>';
  // HOME-SOCIAL-3 (Owner: "I wanna see 33 designs"): one library design per industry, each re-rendered under its own business.
  // [network, business, handle, sub line, image, avatar colour, alt, caption, tags (ig), likes | reactions, comments]
  $socRows = [
    ['ig', 'Kettle Row Coffee', 'kettlerowcoffee', 'Harbour City', 'cafe-05', '#0f766e', 'Monday coffee, a teal cup with croissants', 'Monday, sorted. Flat whites and warm croissants from 7am.', '#MondayCoffee #KettleRow', '214 likes', ''],
    ['fb', 'Saltmarsh Travel', '', 'Friday at 9:00', 'travel-agency-08', '#14532d', 'Explore your destination, up to 35% off', 'Lakes, mountains and slow mornings. Handpicked stays and guided tours, up to 35% off this season.', '', '312', '27 comments'],
    ['in', 'Crown Court Realty', '', '1,240 followers · 2d', 'real-estate-agency-09', '#1e3a8a', 'Well connected, well positioned: a night map of Harbour District', 'Harbour District is one of the best-connected places to live and invest: the ring road, the business corridor and the riverside, minutes away.', '', '86', '9 comments'],
    ['ig', 'Porcelain Aesthetic Clinic', 'porcelainclinic', 'Harbour City', 'aesthetic-clinic-04', '#a16207', 'Your beauty, elevated: a close-up behind a soft veil', 'Your beauty, elevated. Skin treatments designed around you, by doctors who listen.', '#SkinCare #Porcelain', '402 likes', ''],
    ['fb', 'Torque Auto', '', 'Today at 8:15', 'automotive-08', '#b91c1c', 'Your car, our care: a mechanic at work in the garage', 'Service, repairs and tyres, with same-day slots. Sit back, we have it covered.', '', '97', '11 comments'],
    ['in', 'Plumbline Design Studio', '', '2,180 followers · 3d', 'architecture-08', '#78350f', 'We read the plan: a floor plan in afternoon light', 'Before we draw a line, we read the plan: light, circulation and how you actually live. That is where good architecture starts.', '', '143', '12 comments'],
    ['ig', 'Kingsway Barbershop', 'kingswaybarbers', 'Kings Street', 'barbershop-04', '#3f3f46', 'We are open: a barber at his station', 'Open 10:00 to 21:00, every day. Walk in, or book your chair.', '#KingswayBarbers #FreshCut', '276 likes', ''],
    ['fb', 'Marigold Catering', '', 'Yesterday at 17:40', 'catering-05', '#c2410c', 'A modern take on wedding catering: layered cakes on glass stands', 'A modern take on wedding catering: small plates, big flavour, served beautifully. Now booking spring weddings.', '', '188', '23 comments'],
    ['in', 'Keystone Builders', '', '4,050 followers · 5d', 'construction-04', '#ea580c', 'Quality you can see: a builder in a hard hat', 'Quality you can see, foundations you can rely on. Every project delivered with precision, on time.', '', '121', '8 comments'],
    ['ig', 'Velvet Salon', 'velvetsalon', 'Harbour City', 'beauty-salon-02', '#7c2d12', 'Nail trends: lilac almond nails and rings', 'This season\'s nails: soft lilac, long almond, quiet luxury. Book your set.', '#NailTrends #VelvetSalon', '531 likes', ''],
    ['fb', 'Sunbeam Preschool', '', 'Monday at 7:30', 'childcare-06', '#65a30d', 'More than a preschool: children painting at a table', 'More than a preschool, a second home. Visits for next term are open: come and spend a morning with us.', '', '204', '31 comments'],
    ['in', 'Harbourline Consulting', '', '3,410 followers · 1w', 'consulting-04', '#1d4ed8', 'Business consultant: solutions for your company\'s growth', 'Growth stalls when everything is a priority. We help leadership teams choose what matters and build the systems to deliver it.', '', '64', '6 comments'],
    ['ig', 'Ironhouse Fitness', 'ironhousefitness', 'Central Street', 'gym-fitness-03', '#3f6212', 'Start training today: a kettlebell lifted', 'Three coaches, small groups, real progress. Your first session is on us.', '#StartToday #Ironhouse', '389 likes', ''],
    ['fb', 'Pearl Dental', '', 'Tuesday at 10:00', 'dental-01', '#2563eb', 'Your smile deserves expert care: a friendly tooth mascot', 'Your smile deserves expert care. New patients welcome, with check-ups available this week.', '', '142', '17 comments'],
    ['in', 'Willowmere & Co.', '', '1,960 followers · 4d', 'event-venue-04', '#4d7c0f', 'Event venue rental: flowers, chandeliers and a long table', 'Weddings, corporate meetings, birthdays: one venue, tailored packages. Now booking spring dates.', '', '77', '5 comments'],
    ['ig', 'Cartwheel', 'cartwheelshop', 'Online store', 'e-commerce-02', '#15803d', 'Your favourite things, just a click away', 'Your favourite things, just a click away. Free delivery all week.', '#ShopCartwheel', '618 likes', ''],
    ['fb', 'Saffron Row', '', 'Yesterday at 18:30', 'restaurant-03', '#b45309', 'Status check: working, busy or hungry, over a plate of idli', 'Working, busy or hungry? Fresh idli and sambar from noon. Walk in, or order ahead.', '', '158', '14 comments'],
    ['in', 'Bytewise', '', '5,320 followers · 2d', 'it-services-03', '#6d28d9', 'We help you take control: web, apps, cloud and design', 'We build digital solutions that help businesses grow faster, look better and work smarter. Let\'s talk about yours.', '', '109', '10 comments'],
    ['ig', 'Inkwell Studio', 'inkwell.studio', 'Design studio', 'graphic-design-05', '#881337', 'Our services: branding, social, web and motion design', 'Branding, social, web and motion. What are we making together this season?', '#DesignStudio #Inkwell', '447 likes', ''],
    ['fb', 'Trueline Fix', '', 'Wednesday at 8:00', 'home-services-10', '#1e3a8a', 'Expert electrical and plumbing renovation', 'Electrical and plumbing, done right the first time. Call us and book your service today.', '', '83', '9 comments'],
    ['in', 'Oakhaven Interiors', '', '2,770 followers · 6d', 'interior-design-06', '#57534e', 'From sketch to space: a calm living room', 'From sketch to space. A recent living room for a young family: calm, warm and made for real life.', '', '132', '11 comments'],
    ['ig', 'The Rookery', 'rookeryhotel', 'Old Town', 'hotel-04', '#44403c', 'Sleep better here: a quiet hotel bedroom', 'Sleep better here. Rooms from $129, breakfast included.', '#RookeryHotel #Weekend', '352 likes', ''],
    ['fb', 'Atrium Clinic & Lab', '', 'Thursday at 9:30', 'medical-clinic-07', '#4338ca', 'Your health deserves attention: a smiling doctor', 'Your health deserves attention. Book your visit at the clinic or the lab, this week.', '', '119', '13 comments'],
    ['in', 'Loudhouse', '', '6,840 followers · 1d', 'marketing-agency-01', '#18181b', 'We are not your typical agency: a man with a TV for a head', 'We are not your typical agency. Predictable is boring, so let\'s make something people remember.', '', '214', '28 comments'],
    ['ig', 'Waggle Grooming', 'wagglegrooming', 'Riverside', 'pet-services-07', '#0d9488', 'Bath day, best day: a terrier with a foam hat', 'Bath day, best day. Book a groom, treats included.', '#BathDay #Waggle', '702 likes', ''],
    ['fb', 'Daybreak', '', 'Saturday at 7:00', 'news-media-07', '#ca8a04', 'Stay defiantly hopeful by intentionally reading good news', 'Good news happens every single day, it just gets buried. Here are this week\'s most popular good news stories.', '', '256', '34 comments'],
    ['in', 'Brightpath Academy', '', '3,890 followers · 3d', 'online-courses-08', '#1e40af', 'Online courses: a student celebrating at her laptop', 'Learn anywhere, at your own pace, with expert instructors. New courses start on Monday.', '', '98', '7 comments'],
    ['ig', 'Ember Chef', 'emberchef', 'Private dining', 'private-chef-01', '#292524', 'Your private chef: a smiling chef in whites', 'Dine like royalty in your own home. Send us a message to book your evening.', '#PrivateChef #Ember', '488 likes', ''],
    ['fb', 'Summit Digital School', '', 'Friday at 16:00', 'training-centre-05', '#1d4ed8', 'Admissions open: a girl in a classroom', 'Admissions are open. Digital skills that build confidence, creativity and success.', '', '137', '19 comments'],
    ['ig', 'Headland Villas & Spa', 'headlandvillas', 'Headland Bay', 'resort-06', '#0369a1', 'A place to relax: loungers by a private pool', 'A place to relax. Private pool villas, five minutes from the beach.', '#Headland #VillaLife', '864 likes', ''],
    ['in', 'Scholar Tutors', '', '1,420 followers · 1w', 'tutoring-10', '#1e3a8a', 'A good tutor doesn\'t just teach: a tutor and a boy reading', 'A good tutor does not just teach, they understand how your child learns. One-to-one sessions, in person or online.', '', '91', '8 comments'],
    ['ig', 'Juniper', 'juniperstore', 'High Street', 'retail-shop-08', '#a16207', 'The summer edit: sunglasses and sneakers around a phone', 'The summer edit is here: shades, sneakers and everything in between.', '#SummerEdit #Juniper', '577 likes', ''],
    ['ig', 'Harbourloft Stays', 'harbourloft', 'Harbour City', 'short-term-rental-04', '#0e7490', 'Plan your stay: two guests by a plunge pool', 'Send us a message to plan your stay, or book direct for the best rate.', '#Harbourloft #Getaway', '419 likes', ''],
  ];
  $socSquare = ['consulting-04', 'beauty-salon-02', 'childcare-06', 'construction-04', 'dental-01', 'event-venue-04'];
  $socPosts = array_map(fn ($r) => ['net' => $r[0], 'biz' => $r[1], 'handle' => $r[2], 'sub' => $r[3], 'img' => $r[4], 'av' => $r[5], 'alt' => $r[1] . ' post: ' . $r[6],
    'cap' => $r[7], 'tags' => $r[8], 'meta' => $r[9], 'meta2' => $r[10], 'w' => 640, 'h' => in_array($r[4], $socSquare, true) ? 640 : 800], $socRows);
  // Owner: "we also share website articles remember?": articles Priya writes for the business's website, shared as link posts
  // [network, business, sub line, avatar colour, image, domain, title, text, reactions, comments, read time (LinkedIn)]
  $artRows = [
    ['fb', 'Saltmarsh Travel', 'Sunday at 10:00', '#14532d', 'harbour-towns', 'saltmarsh.travel', 'Five harbour towns worth the slow road', 'New on the blog: five harbour towns to see at sunrise, where to stay and the best time to go.', '241', '19 comments', ''],
    ['in', 'Crown Court Realty', '1,240 followers · 4d', '#1e3a8a', 'harbour-district-buyers', 'crowncourt.co', 'Harbour District in 2026: what buyers should know before a viewing', 'Thinking of buying in Harbour District this year? Our guide covers prices, streets and what to ask at a viewing.', '58', '7 comments', '6 min read'],
    ['fb', 'Kettle Row Coffee', 'Thursday at 8:00', '#0f766e', 'sourdough-36-hours', 'kettlerow.coffee', 'Why our sourdough takes 36 hours', 'Ever wondered why our loaves sell out by ten? The long, slow story is on our blog.', '176', '22 comments', ''],
    ['in', 'Harbourline Consulting', '3,410 followers · 2d', '#1d4ed8', 'outgrown-systems', 'harbourline.co', 'Five signs your business has outgrown its systems', 'If your team spends more time chasing updates than doing the work, this one is for you.', '93', '11 comments', '5 min read'],
    ['fb', 'Pearl Dental', 'Monday at 9:00', '#2563eb', 'dentist-how-often', 'pearldental.co', 'How often should you really see the dentist?', 'Twice a year is the rule of thumb, but not for everyone. Our dentists explain.', '128', '15 comments', ''],
    ['fb', 'Ironhouse Fitness', 'Saturday at 7:30', '#3f6212', 'strength-after-40', 'ironhouse.fit', 'Strength training after 40: where to start', 'Never lifted before? Our head coach\'s beginner plan is on the blog.', '207', '26 comments', ''],
  ];
  $socAll = [];
  foreach ($socPosts as $i => $p) {
    $socAll[] = $p;
    if ($i % 5 === 2 && ($r = array_shift($artRows))) $socAll[] = ['type' => 'article', 'net' => $r[0], 'biz' => $r[1], 'handle' => '', 'sub' => $r[2], 'av' => $r[3], 'img' => $r[4], 'domain' => $r[5],
      'title' => $r[6], 'cap' => $r[7], 'meta' => $r[8], 'meta2' => $r[9], 'read' => $r[10], 'alt' => $r[1] . ' article: ' . $r[6]];
  }
  $netName = ['ig' => 'Instagram', 'fb' => 'Facebook', 'in' => 'LinkedIn'];
  ?>
  <section class="mk-sec" id="social" style="padding-top:40px">
    <style>
      #social .soc-nets{display:flex;gap:8px;flex-wrap:wrap}
      #social .soc-net{display:inline-flex;align-items:center;gap:8px;height:40px;padding:0 14px 0 10px;border-radius:999px;font:600 14px/1 var(--font);color:var(--ink)}
      #social svg{flex:none}#social .soc-ico{display:grid;place-items:center;width:28px;height:28px;border-radius:8px;background:#fff;box-shadow:0 0 0 .5px rgba(0,0,0,.12),0 1px 3px rgba(0,0,0,.18)}#social .soc-net svg{width:18px;height:18px}#social .soc-net{padding-left:6px}
      #social .soc-top{display:flex;gap:48px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;margin-bottom:24px}
      #social .soc-top__copy{max-width:600px;display:flex;flex-direction:column;gap:16px}
      #social .soc-sarah{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:18px;max-width:420px}
      #social .soc-strip{position:relative}
      #social .soc-gal{display:flex;gap:20px;align-items:flex-start;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;scrollbar-width:none;margin:0 -8px;padding:6px 8px 22px}#social .soc-gal::-webkit-scrollbar{display:none}
      #social .soc-gal>.soc-card{flex:0 0 296px;scroll-snap-align:start}
      #social .soc-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 14px}
      #social .soc-count{display:flex;gap:10px;align-items:flex-start;max-width:760px;font:400 15px/1.5 var(--font);color:var(--ink-2)}#social .soc-count b{color:var(--ink);font-weight:600}#social .soc-count svg{flex:none;width:22px;height:22px;margin-top:1px;color:var(--accent-text,#7c3aed)}
      #social .soc-arrows{display:flex;gap:8px}
      #social .soc-arrow{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;cursor:pointer;color:var(--ink);border:1px solid var(--hairline)}
      #social .soc-arrow svg{width:20px;height:20px}#social .soc-arrow[disabled]{opacity:.35;cursor:default}
      #social .soc-card{border-radius:16px;overflow:hidden;background:#fff;color:#14161c;box-shadow:0 0 0 .5px rgba(0,0,0,.08),0 12px 32px rgba(20,16,60,.16);font:400 14px/1.42 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
      #social .soc-hd{display:flex;align-items:center;gap:10px;padding:11px 12px}
      #social .soc-av{width:34px;height:34px;border-radius:50%;flex:none;display:grid;place-items:center;color:#fff;font:700 14px/1 var(--font)}
      #social .soc-card[data-net="ig"] .soc-av{box-shadow:0 0 0 2px #fff,0 0 0 4px #d62976}
      #social .soc-who{display:flex;flex-direction:column;min-width:0;flex:1}#social .soc-who b{font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}#social .soc-who span{font-size:12px;color:#65676b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      #social .soc-badge svg{width:20px;height:20px;display:block}
      #social .soc-img{display:block;width:100%;height:auto;background:#eef0f4}
      #social .soc-cap{padding:0 12px 10px;font-size:14px}#social .soc-cap b{font-weight:600}#social .soc-tags{color:#00376b}#social .soc-more{color:#65676b}
      #social .soc-card[data-net="fb"] .soc-cap,#social .soc-card[data-net="in"] .soc-cap{padding-top:0}
      #social .soc-link{background:#f0f2f5;border-top:1px solid #e4e6eb;border-bottom:1px solid #e4e6eb}#social .soc-card[data-net="in"] .soc-link{background:#eef3f8}
      #social .soc-img--link{aspect-ratio:1.91/1;object-fit:cover}
      #social .soc-link__meta{display:flex;flex-direction:column;gap:3px;padding:9px 12px 10px}#social .soc-link__meta b{font-size:14.5px;line-height:1.3;color:#14161c}
      #social .soc-link__dom{font-size:11.5px;letter-spacing:.02em;color:#65676b}#social .soc-link__dom--in{letter-spacing:0}
      #social .soc-icons{display:flex;gap:14px;padding:10px 12px 6px;color:#14161c}#social .soc-icons svg{width:23px;height:23px}
      #social .soc-likes{padding:0 12px 4px;font-weight:600;font-size:13px}
      #social .soc-stats{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;font-size:13px;color:#65676b}
      #social .soc-react{display:inline-flex;align-items:center;gap:6px}#social .soc-react i{display:inline-grid;place-items:center;width:18px;height:18px;border-radius:50%;font-style:normal;font-size:10px;color:#fff;margin-right:-4px;box-shadow:0 0 0 2px #fff}
      #social .soc-act{display:flex;justify-content:space-around;padding:7px 4px 8px;border-top:1px solid #e4e6eb;color:#65676b;font-size:13px;font-weight:600}
      @media (max-width:900px){#social .soc-gal{gap:12px;margin:0 -16px;padding:4px 16px 14px}#social .soc-gal>.soc-card{flex:0 0 78%;scroll-snap-align:center}#social .soc-top{gap:20px}#social .soc-arrows{display:none}}
    </style>
    <div class="mk-wrap">
      <div class="soc-top">
        <div class="soc-top__copy">
          <span class="t-eyebrow" data-rv>Social, handled</span>
          <h2 class="mk-h2" data-rv>Posts that look designed, because they are.</h2>
          <p class="mk-lead" data-rv style="font-size:17px;line-height:27px">Marcus, your social media manager, writes each post in your voice and lays it out in the looks you pick from the design library, in your colours. Sarah shows you every post the way it will appear, and nothing goes out until you approve it.</p>
          <div class="soc-nets" data-rv aria-label="Posts to Facebook, Instagram and LinkedIn">
            <span class="soc-net lg-glass lg-glass--thin"><span class="soc-ico"><?= $icFb ?></span>Facebook</span>
            <span class="soc-net lg-glass lg-glass--thin"><span class="soc-ico"><?= $icIg('soc-ig-a') ?></span>Instagram</span>
            <span class="soc-net lg-glass lg-glass--thin"><span class="soc-ico"><?= $icIn ?></span>LinkedIn</span>
          </div>
        </div>
        <div class="soc-sarah lg-glass lg-glass--thick" data-rv>
          <img class="lg-avatar" src="<?= e($agentImg('sarah')) ?>" alt="" width="34" height="34" style="width:34px;height:34px;flex:none">
          <div class="lg-stack" style="gap:2px;min-width:0"><span class="t-subhead t-strong">Sarah</span><span class="t-body c-2" style="font-size:14px;line-height:20px">Boss, this week's posts are ready to review. Approve them and they go out on schedule, on every network you use.</span></div>
        </div>
      </div>
      <div class="soc-bar" data-rv><span class="soc-count"><svg viewBox="0 0 24 24" class="ic" aria-hidden="true"><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1"/><circle cx="12" cy="12" r="3"/></svg><span><b>Curated by intelligence, not guesswork.</b> Sarah and your specialists read what is trending in your industry, the signals from your audience and how every past post performed, then choose the topic, the look and the moment to post.</span></span>
        <div class="soc-arrows"><button type="button" class="soc-arrow lg-glass lg-glass--thin" data-dir="-1" aria-label="Previous posts" disabled><svg viewBox="0 0 24 24" class="ic"><path d="m15 6-6 6 6 6"/></svg></button><button type="button" class="soc-arrow lg-glass lg-glass--thin" data-dir="1" aria-label="Next posts"><svg viewBox="0 0 24 24" class="ic"><path d="m9 6 6 6-6 6"/></svg></button></div></div>
      <div class="soc-gal" data-rv role="list" aria-label="Example posts on Instagram, Facebook and LinkedIn" tabindex="0">
        <?php foreach ($socAll as $i => $p): $n = $p['net']; $art = ($p['type'] ?? '') === 'article'; ?>
        <article class="soc-card" data-net="<?= $n ?>" role="listitem" aria-label="<?= e($netName[$n] . ' post by ' . $p['biz']) ?>">
          <div class="soc-hd">
            <span class="soc-av" style="background:<?= e($p['av']) ?>"><?= e(substr($p['biz'], 0, 1)) ?></span>
            <div class="soc-who"><b><?= e($n === 'ig' ? $p['handle'] : $p['biz']) ?></b><span><?= e($p['sub']) ?><?= $n === 'fb' ? ' · Public' : '' ?></span></div>
            <span class="soc-badge" title="<?= e($netName[$n]) ?>"><?= $n === 'ig' ? $icIg('soc-ig-' . $i) : ($n === 'fb' ? $icFb : $icIn) ?></span>
          </div>
          <?php if ($n !== 'ig'): ?><div class="soc-cap"><?= e($p['cap']) ?><?= $n === 'in' && !$art ? ' <span class="soc-more">…more</span>' : '' ?></div><?php endif; ?>
          <?php if ($art): ?>
          <div class="soc-link"><img class="soc-img soc-img--link" src="<?= e($mk('social/articles/' . $p['img'] . '.webp')) ?>" alt="<?= e($p['alt']) ?>" width="640" height="335" loading="lazy" decoding="async"><div class="soc-link__meta"><?php if ($n === 'fb'): ?><span class="soc-link__dom"><?= e(strtoupper($p['domain'])) ?></span><b><?= e($p['title']) ?></b><?php else: ?><b><?= e($p['title']) ?></b><span class="soc-link__dom soc-link__dom--in"><?= e($p['domain']) ?> · <?= e($p['read']) ?></span><?php endif; ?></div></div>
          <?php else: ?>
          <img class="soc-img" src="<?= e($mk('social/' . $p['img'] . '.webp')) ?>" alt="<?= e($p['alt']) ?>" width="<?= (int) $p['w'] ?>" height="<?= (int) $p['h'] ?>" loading="lazy" decoding="async">
          <?php endif; ?>
          <?php if ($n === 'ig'): ?>
            <div class="soc-icons" aria-hidden="true"><svg viewBox="0 0 24 24" class="ic"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></svg><svg viewBox="0 0 24 24" class="ic"><path d="M21 12a8 8 0 0 1-11.7 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg><svg viewBox="0 0 24 24" class="ic"><path d="M21 4 3 11l7 2 2 7 9-16z"/></svg></div>
            <div class="soc-likes"><?= e($p['meta']) ?></div>
            <div class="soc-cap"><b><?= e($p['handle']) ?></b> <?= e($p['cap']) ?> <span class="soc-tags"><?= e($p['tags']) ?></span></div>
          <?php else: ?>
            <div class="soc-stats"><span class="soc-react"><?= $n === 'fb' ? '<i style="background:#1877f2">&#128077;</i><i style="background:#f33e58">&#10084;</i>' : '<i style="background:#0a66c2">&#128077;</i><i style="background:#df704d">&#128079;</i>' ?> <span style="margin-left:6px"><?= e($p['meta']) ?></span></span><span><?= e($p['meta2']) ?></span></div>
            <div class="soc-act" aria-hidden="true"><?php foreach ($n === 'fb' ? ['Like', 'Comment', 'Share'] : ['Like', 'Comment', 'Repost', 'Send'] as $a): ?><span><?= $a ?></span><?php endforeach; ?></div>
          <?php endif; ?>
        </article>
        <?php endforeach; ?>
      </div>
    </div>
    <script>
      (function () {
        var s = document.getElementById('social'); if (!s) return;
        var g = s.querySelector('.soc-gal'), b = s.querySelectorAll('.soc-arrow');
        function step() { var c = g.querySelector('.soc-card'); return c ? (c.getBoundingClientRect().width + 20) * Math.max(1, Math.floor(g.clientWidth / (c.getBoundingClientRect().width + 20))) : 300; }
        function sync() { b[0].disabled = g.scrollLeft < 8; b[1].disabled = g.scrollLeft + g.clientWidth > g.scrollWidth - 8; }
        Array.prototype.forEach.call(b, function (x) { x.addEventListener('click', function () { g.scrollBy({ left: step() * (+x.getAttribute('data-dir')), behavior: 'smooth' }); }); });
        g.addEventListener('scroll', function () { window.requestAnimationFrame(sync); }, { passive: true }); sync();
      })();
    </script>
  </section>

  <?php /* 05c — COMMENTS ANSWERED (Owner 2026-10-05: "Add below it a simulation of the Sarah chat responding to comments").
     A self-playing thread: comments arrive, Sarah writes the reply in the brand's voice, the business approves and it posts;
     a buying comment becomes a lead. Plays when on screen, loops; reduced motion shows the finished thread. */ ?>
  <section class="mk-sec" id="replies" style="padding-top:40px">
    <style>
      #replies .rp-wrap{display:flex;gap:48px;align-items:center}
      #replies .rp-copy{width:380px;flex:none;display:flex;flex-direction:column;gap:16px}
      #replies .rp-list{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:8px}
      #replies .rp-list li{display:flex;gap:10px;align-items:flex-start;font-size:15px;line-height:22px;color:var(--ink-2)}
      #replies .rp-list svg{flex:none;width:18px;height:18px;margin-top:2px;color:var(--success)}
      #replies .rp-stage{flex:1;min-width:0;display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,.85fr);gap:18px;align-items:start}
      #replies .rp-post{border-radius:18px;overflow:hidden;background:#fff;color:#14161c;box-shadow:0 0 0 .5px rgba(0,0,0,.08),0 14px 36px rgba(20,16,60,.18);font:400 14px/1.42 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
      #replies .rp-hd{display:flex;align-items:center;gap:10px;padding:11px 12px}
      #replies .rp-av{width:32px;height:32px;border-radius:50%;flex:none;display:grid;place-items:center;color:#fff;font:700 13px/1 var(--font)}
      #replies .rp-hd .rp-av{box-shadow:0 0 0 2px #fff,0 0 0 4px #d62976}
      #replies .rp-hd b{font-size:14px}#replies .rp-hd span{font-size:12px;color:#65676b;display:block}
      #replies .rp-media{display:flex;gap:10px;padding:0 12px 10px;align-items:center}
      #replies .rp-media img{width:72px;height:90px;object-fit:cover;border-radius:8px;flex:none}
      #replies .rp-media p{margin:0;font-size:13.5px;color:#14161c}#replies .rp-c__body{color:#14161c}
      #replies .rp-thread{border-top:1px solid #efefef;padding:10px 12px 12px;display:flex;flex-direction:column;gap:10px;min-height:356px}
      #replies .rp-c{display:flex;gap:9px;align-items:flex-start}
      #replies .rp-c .rp-av{width:28px;height:28px;font-size:12px}
      #replies .rp-c__body{min-width:0;font-size:13.5px}
      #replies .rp-c__body b{font-weight:600;margin-right:4px}
      #replies .rp-c__meta{display:flex;gap:10px;font-size:11.5px;color:#8e8e8e;margin-top:2px}
      #replies .rp-reply{margin-left:37px}
      #replies .rp-tag{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:#7c3aed;background:#f3eeff;border-radius:999px;padding:2px 7px}
      #replies .rp-typing{margin-left:37px;display:flex;align-items:center;gap:8px;font-size:12px;color:#7c3aed}
      #replies .rp-dots{display:inline-flex;gap:3px}#replies .rp-dots i{width:5px;height:5px;border-radius:50%;background:#a78bfa;animation:rp-b 1s infinite}
      #replies .rp-dots i:nth-child(2){animation-delay:.15s}#replies .rp-dots i:nth-child(3){animation-delay:.3s}
      @keyframes rp-b{0%,80%,100%{opacity:.3;transform:translateY(0)}40%{opacity:1;transform:translateY(-3px)}}
      #replies .rp-side{display:flex;flex-direction:column;gap:12px}
      #replies .rp-card{border-radius:18px;padding:14px 16px;display:flex;flex-direction:column;gap:10px}
      #replies .rp-row{display:flex;align-items:center;gap:10px}
      #replies .rp-row .lg-grow{min-width:0}
      #replies .rp-stat{display:flex;justify-content:space-between;font-size:13px;color:var(--ink-2)}#replies .rp-stat b{color:var(--ink);font-variant-numeric:tabular-nums}
      #replies .rp-draft{font-size:13px;line-height:19px;color:var(--ink);padding:10px 12px;border-radius:12px;background:var(--fill-hover)}
      #replies [data-rp]{transition:opacity .35s ease,transform .35s ease}
      #replies [data-rp].rp-off{opacity:0;visibility:hidden;transform:translateY(6px)}
      #replies .rp-draft{min-height:78px}
      @media (max-width:900px){#replies .rp-wrap{flex-direction:column;align-items:stretch;gap:24px}#replies .rp-copy{width:auto}#replies .rp-stage{grid-template-columns:1fr}}
      @media (prefers-reduced-motion:reduce){#replies [data-rp]{transition:none}#replies .rp-dots i{animation:none}}
    </style>
    <div class="mk-wrap rp-wrap">
      <div class="rp-copy">
        <span class="t-eyebrow" data-rv>Comments, answered</span>
        <h2 class="mk-h2" data-rv>Every comment gets a reply. The hot ones become leads.</h2>
        <p class="mk-lead" data-rv style="font-size:17px;line-height:27px">Sarah reads the comments on your Facebook, Instagram and LinkedIn posts, writes each reply in your voice and posts it when you approve. When someone asks about dates or prices, Elena adds them to your clients as a lead.</p>
        <ul class="rp-list" data-rv>
          <li><svg viewBox="0 0 24 24" class="ic"><path d="m5 12 5 5 9-10"/></svg>Replies in minutes, not days, in the way you talk to customers.</li>
          <li><svg viewBox="0 0 24 24" class="ic"><path d="m5 12 5 5 9-10"/></svg>Questions about price or availability are followed up by message.</li>
          <li><svg viewBox="0 0 24 24" class="ic"><path d="m5 12 5 5 9-10"/></svg>You approve every reply, or change it in one tap.</li>
        </ul>
      </div>
      <div class="rp-stage" data-rv aria-label="Example: Sarah replying to comments on an Instagram post">
        <article class="rp-post">
          <div class="rp-hd"><span class="rp-av" style="background:#14532d">S</span><div><b>saltmarshtravel</b><span>Lake District</span></div></div>
          <div class="rp-media"><img src="<?= e($mk('social/travel-agency-08.webp')) ?>" alt="" width="72" height="90" loading="lazy" decoding="async"><p><b>saltmarshtravel</b> Lakes, mountains and slow mornings. Handpicked stays and guided tours, up to 35% off this season.</p></div>
          <div class="rp-thread" id="rp-thread">
            <div class="rp-c" data-rp="c1"><span class="rp-av" style="background:#db2777">M</span><div class="rp-c__body"><b>maya.wanders</b>Is this available in April? Asking for my honeymoon 😍<div class="rp-c__meta"><span>2m</span><span>Reply</span></div></div></div>
            <div class="rp-c rp-reply" data-rp="r1"><span class="rp-av" style="background:#14532d">S</span><div class="rp-c__body"><b>saltmarshtravel</b>Congratulations, Maya! April is lovely for the lakes. We have just sent you dates and prices by message 💌<div class="rp-c__meta"><span>Just now</span><span class="rp-tag">✓ Approved</span></div></div></div>
            <div class="rp-c" data-rp="c2"><span class="rp-av" style="background:#0891b2">T</span><div class="rp-c__body"><b>tom.k_travels</b>How much for two people, 5 nights?<div class="rp-c__meta"><span>1m</span><span>Reply</span></div></div></div>
            <div class="rp-c rp-reply" data-rp="r2"><span class="rp-av" style="background:#14532d">S</span><div class="rp-c__body"><b>saltmarshtravel</b>Hi Tom! Five nights for two starts from $1,240 per person, with breakfast and transfers. Check your messages for the full breakdown 🙌<div class="rp-c__meta"><span>Just now</span><span class="rp-tag">✓ Approved</span></div></div></div>
            <div class="rp-c" data-rp="c3"><span class="rp-av" style="background:#65a30d">L</span><div class="rp-c__body"><b>lena.outdoors</b>That lake 😍😍<div class="rp-c__meta"><span>Now</span><span>Reply</span></div></div></div>
            <div class="rp-c rp-reply" data-rp="r3"><span class="rp-av" style="background:#14532d">S</span><div class="rp-c__body"><b>saltmarshtravel</b>Right? It looks even better at sunrise, Lena 🌄<div class="rp-c__meta"><span>Just now</span><span class="rp-tag">✓ Approved</span></div></div></div>
          </div>
        </article>
        <div class="rp-side">
          <div class="rp-card lg-glass lg-glass--thick">
            <div class="rp-row"><img class="lg-avatar" src="<?= e($agentImg('sarah')) ?>" alt="" width="34" height="34" style="width:34px;height:34px;flex:none"><div class="lg-stack lg-grow" style="gap:1px"><span class="t-subhead t-strong">Sarah</span><span class="t-caption c-3" id="rp-status">Watching your comments</span></div></div>
            <div class="rp-draft" data-rp="d1" id="rp-draft">Boss, Maya asked about April for her honeymoon. I've written a reply and will send her the dates by message. Approve?</div>
            <div class="rp-row" data-rp="a1" style="justify-content:flex-end;gap:8px"><span class="lg-btn lg-btn--glass lg-btn--sm" style="height:32px">Change</span><span class="lg-btn lg-btn--primary lg-btn--sm" style="height:32px" id="rp-approve">Approve</span></div>
          </div>
          <div class="rp-card lg-glass lg-glass--thick" data-rp="lead">
            <div class="rp-row"><img class="lg-avatar" src="<?= e($agentImg('elena')) ?>" alt="" width="34" height="34" style="width:34px;height:34px;flex:none"><div class="lg-stack lg-grow" style="gap:1px"><span class="t-subhead t-strong">New lead in Clients</span><span class="t-caption c-3">Elena · from an Instagram comment</span></div><span class="lg-badge lg-badge--success">Hot</span></div>
            <div class="rp-stat"><span>Maya · honeymoon, April</span><b>2 guests</b></div>
          </div>
          <div class="rp-card lg-glass lg-glass--thin">
            <div class="rp-stat"><span>Comments answered today</span><b id="rp-count">0</b></div>
            <div class="rp-stat"><span>Average reply time</span><b>4 min</b></div>
            <div class="rp-stat"><span>Leads from comments this week</span><b id="rp-leads">2</b></div>
          </div>
        </div>
      </div>
    </div>
    <script>
      (function () {
        var s = document.getElementById('replies'); if (!s) return;
        var q = function (k) { return s.querySelector('[data-rp="' + k + '"]'); };
        var all = Array.prototype.slice.call(s.querySelectorAll('[data-rp]'));
        var status = document.getElementById('rp-status'), draft = document.getElementById('rp-draft'), count = document.getElementById('rp-count'), leads = document.getElementById('rp-leads'), appr = document.getElementById('rp-approve');
        var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
        var gen = 0, visible = false, running = false;
        function on(k) { var el = q(k); if (el) el.classList.remove('rp-off'); }
        function gone(k) { var el = q(k); if (el) el.classList.add('rp-off'); }
        function finished() { all.forEach(function (el) { el.classList.remove('rp-off'); }); gone('a1'); status.textContent = '3 replies sent'; count.textContent = '14'; leads.textContent = '3'; draft.textContent = 'All caught up, Boss. Two people asked about trips today, both are in your clients.'; }
        function reset() { all.forEach(function (el) { el.classList.add('rp-off'); }); status.textContent = 'Watching your comments'; count.textContent = '11'; leads.textContent = '2'; appr.style.boxShadow = ''; }
        function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
        async function run() {
          var my = ++gen, live = function () { return my === gen && visible; };
          running = true; reset(); await wait(500);
          on('c1', 1); await wait(1100); if (!live()) return (running = false);
          status.textContent = 'Writing a reply to Maya'; await wait(1400); if (!live()) return (running = false);
          draft.textContent = 'Boss, Maya asked about April for her honeymoon. I’ve written a reply and will send her the dates by message. Approve?';
          on('d1', 1); on('a1', 1); await wait(1500); if (!live()) return (running = false);
          appr.style.boxShadow = '0 0 0 4px rgba(124,58,237,.35)'; await wait(500); appr.style.boxShadow = '';
          on('r1', 1); count.textContent = '12'; status.textContent = 'Reply sent'; await wait(700);
          on('lead', 1); leads.textContent = '3'; await wait(1600); if (!live()) return (running = false);
          on('c2', 1); await wait(1000); status.textContent = 'Writing a reply to Tom'; 
          draft.textContent = 'Tom asked for a price for two. Reply ready, with the full breakdown by message. Approve?'; await wait(1400); if (!live()) return (running = false);
          appr.style.boxShadow = '0 0 0 4px rgba(124,58,237,.35)'; await wait(500); appr.style.boxShadow = '';
          on('r2', 1); count.textContent = '13'; status.textContent = 'Reply sent'; await wait(1500); if (!live()) return (running = false);
          on('c3', 1); await wait(900); draft.textContent = 'Lena loved the lake. A short thank-you reply is ready. Approve?'; status.textContent = 'Writing a reply to Lena'; await wait(1100);
          appr.style.boxShadow = '0 0 0 4px rgba(124,58,237,.35)'; await wait(500); appr.style.boxShadow = '';
          on('r3', 1); gone('a1'); count.textContent = '14'; status.textContent = '3 replies sent'; draft.textContent = 'All caught up, Boss. Two people asked about trips today, both are in your clients.';
          await wait(5000); if (!live()) return (running = false);
          running = false; if (visible) run();
        }
        if (reduce || !('IntersectionObserver' in window)) { finished(); return; }
        reset();
        new IntersectionObserver(function (es) { es.forEach(function (e) { visible = e.isIntersecting; if (visible && !running) run(); else if (!visible) { gen++; running = false; finished(); } }); }, { threshold: .35 }).observe(s.querySelector('.rp-stage'));
      })();
    </script>
  </section>

