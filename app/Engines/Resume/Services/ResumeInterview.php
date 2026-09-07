<?php

namespace App\Engines\Resume\Services;

/**
 * RESUME888 — the templated interview. A deterministic state machine with pre-written questions in
 * Taglish (tl), English (en) and Filipino (fil). No model call happens here; answers are applied to the
 * draft by ResumeService and the model only writes bullets/summary at the end (ResumeWriter).
 *
 * Step kinds: chips (single choice) · multichips · text · textarea · form (fields[]) · info · file · done
 */
class ResumeInterview
{
    public const LANGS = ['tl', 'en', 'fil'];
    public const MAX_JOBS = 6;

    /** Ordered step ids for the build path (the job loop repeats job_* steps). */
    public const ORDER = ['path', 'region', 'name', 'phone', 'email', 'city', 'target_role', 'target_countries',
        'job_employer', 'job_title', 'job_dates', 'job_desc', 'job_proud', 'job_more',
        'education', 'licences', 'skills', 'languages', 'visa_status', 'availability', 'photo', 'finish'];

    public static function t(string $lang, string $tl, string $en, string $fil = ''): string
    {
        return match ($lang) { 'en' => $en, 'fil' => $fil !== '' ? $fil : $tl, default => $tl };
    }

    /** Full step definition for the reader's language. $ctx: ['job_index'=>n, 'region'=>'QA', 'draft'=>[]] */
    public static function step(string $id, string $lang, array $ctx = []): array
    {
        $n = (int) ($ctx['job_index'] ?? 0); $nth = $n + 1;
        $region = strtoupper((string) ($ctx['region'] ?? ''));
        $t = fn (string $tl, string $en, string $fil = '') => self::t($lang, $tl, $en, $fil);
        $chips = fn (array $pairs) => array_map(fn ($p) => ['value' => $p[0], 'label' => self::t($lang, $p[1], $p[2], $p[3] ?? '')], $pairs);
        $s = ['id' => $id, 'kind' => 'text', 'q' => '', 'help' => null, 'chips' => null, 'fields' => null, 'skippable' => false, 'field' => null];
        switch ($id) {
            case 'path':
                $s += []; $s['kind'] = 'chips'; $s['field'] = 'meta.source';
                $s['q'] = $t('May CV ka na ba, kabayan?', 'Do you already have a CV?', 'Mayroon ka na bang CV, kabayan?');
                $s['chips'] = $chips([['interview', 'Wala pa — tulungan mo ako gumawa', 'No — help me build one', 'Wala pa — tulungan mo akong gumawa'], ['upload', 'Meron — i-upload ko', 'Yes — I will upload it', 'Mayroon — ia-upload ko']]);
                break;
            case 'region':
                $s['kind'] = 'chips'; $s['field'] = 'meta.region';
                $s['q'] = $t('Saan ka mag-a-apply?', 'Where will you apply?', 'Saan ka mag-a-apply?');
                $s['chips'] = $chips([['AE', 'UAE', 'UAE'], ['QA', 'Qatar', 'Qatar'], ['ALL', 'Pareho / kahit saan', 'Both / anywhere', 'Pareho / kahit saan']]);
                break;
            case 'name':
                $s['field'] = 'person.full_name'; $s['q'] = $t('Ano ang buong pangalan mo, gaya ng nasa passport?', 'What is your full name, as written in your passport?', 'Ano ang iyong buong pangalan, tulad ng nasa pasaporte?');
                $s['help'] = $t('Halimbawa: Maria Clara Santos', 'Example: Maria Clara Santos');
                break;
            case 'phone':
                $s['field'] = 'person.phone'; $s['q'] = $t('Anong number ang pwedeng tawagan o i-WhatsApp ng employer?', 'Which number can an employer call or WhatsApp?', 'Anong numero ang maaaring tawagan o i-WhatsApp ng employer?');
                $s['help'] = $t('Isama ang country code, hal. +971 50 123 4567 o +974 3312 3456', 'Include the country code, e.g. +971 50 123 4567 or +974 3312 3456'); $s['input'] = 'tel';
                break;
            case 'email':
                $s['field'] = 'person.email'; $s['skippable'] = true; $s['input'] = 'email';
                $s['q'] = $t('Email address mo? (Optional, pero mas maganda para sa employer.)', 'Your email address? (Optional, but employers prefer one.)', 'Ang iyong email address? (Opsyonal, ngunit mas mainam para sa employer.)');
                break;
            case 'city':
                $s['kind'] = 'form'; $s['q'] = $t('Saan ka nakatira ngayon?', 'Where do you live now?', 'Saan ka naninirahan ngayon?');
                $s['fields'] = [
                    ['name' => 'person.city', 'label' => $t('Lungsod', 'City', 'Lungsod'), 'placeholder' => $region === 'QA' ? 'Doha' : 'Dubai', 'required' => true],
                    ['name' => 'person.country', 'label' => $t('Bansa', 'Country', 'Bansa'), 'kind' => 'chips', 'chips' => $chips([['AE', 'UAE', 'UAE'], ['QA', 'Qatar', 'Qatar'], ['PH', 'Pilipinas', 'Philippines', 'Pilipinas'], ['OTHER', 'Iba', 'Other', 'Iba']]), 'required' => true],
                ];
                break;
            case 'target_role':
                $s['field'] = 'target.roles'; $s['q'] = $t('Anong trabaho ang hinahanap mo? Pwede rin dalawa o tatlo.', 'What job are you looking for? Two or three is fine.', 'Anong trabaho ang hinahanap mo? Maaaring dalawa o tatlo.');
                $s['help'] = $t('Hal. Barista, Sales Associate, Nurse, Driver', 'e.g. Barista, Sales Associate, Nurse, Driver'); $s['list'] = true;
                break;
            case 'target_countries':
                $s['kind'] = 'multichips'; $s['field'] = 'target.countries'; $s['q'] = $t('Saan ka handang magtrabaho?', 'Where are you willing to work?', 'Saan ka handang magtrabaho?');
                $s['chips'] = $chips([['AE', 'UAE', 'UAE'], ['QA', 'Qatar', 'Qatar'], ['SA', 'Saudi', 'Saudi Arabia', 'Saudi'], ['KW', 'Kuwait', 'Kuwait'], ['BH', 'Bahrain', 'Bahrain'], ['OM', 'Oman', 'Oman']]);
                break;
            case 'job_employer':
                $s['field'] = "experience.{$n}.employer";
                $s['q'] = $n === 0 ? $t('Simulan natin sa kasalukuyan o pinakahuling trabaho mo. Anong kumpanya?', "Let's start with your current or most recent job. Which company?", 'Simulan natin sa kasalukuyan o pinakahuling trabaho mo. Anong kumpanya?')
                    : $t("Okay, trabaho #{$nth}. Anong kumpanya?", "Okay, job #{$nth}. Which company?", "Sige, trabaho #{$nth}. Anong kumpanya?");
                $s['help'] = $t('Kung agency o self-employed, isulat lang.', 'If it was an agency or self-employed, just say so.');
                break;
            case 'job_title':
                $s['field'] = "experience.{$n}.title"; $s['q'] = $t('Anong position mo doon?', 'What was your position there?', 'Ano ang iyong posisyon doon?'); $s['help'] = $t('Hal. Waiter, Sales Associate, Site Engineer', 'e.g. Waiter, Sales Associate, Site Engineer');
                break;
            case 'job_dates':
                $s['kind'] = 'form'; $s['q'] = $t('Kailan ka nagsimula at natapos?', 'When did you start and finish?', 'Kailan ka nagsimula at natapos?');
                $s['fields'] = [
                    ['name' => "experience.{$n}.city", 'label' => $t('Lungsod / bansa', 'City / country', 'Lungsod / bansa'), 'placeholder' => 'Dubai, UAE'],
                    ['name' => "experience.{$n}.start", 'label' => $t('Simula (buwan taon)', 'Start (month year)', 'Simula (buwan taon)'), 'placeholder' => '03 2022', 'kind' => 'month', 'required' => true],
                    ['name' => "experience.{$n}.end", 'label' => $t('Tapos (o "present")', 'End (or "present")', 'Wakas (o "present")'), 'placeholder' => 'present', 'kind' => 'month_or_present', 'required' => true],
                ];
                break;
            case 'job_desc':
                $s['kind'] = 'textarea'; $s['field'] = "experience.{$n}.raw";
                $s['q'] = $t('Ano ang mga ginagawa mo doon araw-araw? Kwento mo lang, ako na ang mag-aayos.', 'What did you do there day to day? Just tell me in your own words; I will tidy it up.', 'Ano ang mga ginagawa mo doon araw-araw? Ikuwento mo lang; ako na ang mag-aayos.');
                $s['help'] = $t('Hal. "Nagseserve ng customers, naghahandle ng cash, nagbubukas ng store, 4 na staff ang kasama ko"', 'e.g. "Served customers, handled cash, opened the store, worked with 4 staff"');
                break;
            case 'job_proud':
                $s['kind'] = 'textarea'; $s['field'] = "experience.{$n}.proud"; $s['skippable'] = true;
                $s['q'] = $t('May isang bagay ka bang ipinagmamalaki doon? Award, promotion, o nakatulong sa team?', 'One thing you are proud of there? An award, a promotion, or something that helped the team?', 'Mayroon ka bang ipinagmamalaki doon? Parangal, promosyon, o nakatulong sa koponan?');
                break;
            case 'job_more':
                $s['kind'] = 'chips'; $s['field'] = '_job_more';
                $s['q'] = $t('May iba ka pang trabaho bago ito?', 'Any other job before this one?', 'Mayroon ka pa bang ibang trabaho bago ito?');
                $s['chips'] = $chips([['yes', 'Oo, may isa pa', 'Yes, one more', 'Oo, may isa pa'], ['no', 'Wala na, next na', 'No, that is all', 'Wala na, susunod na']]);
                if ($nth >= self::MAX_JOBS) $s['chips'] = $chips([['no', 'Sapat na ito', 'That is enough', 'Sapat na ito']]);
                break;
            case 'education':
                $s['kind'] = 'form'; $s['q'] = $t('Pinakamataas na pinag-aralan mo?', 'Your highest education?', 'Ang pinakamataas na natapos mong pag-aaral?'); $s['skippable'] = true;
                $s['fields'] = [
                    ['name' => 'education.0.qualification', 'label' => $t('Antas / kurso', 'Level / course', 'Antas / kurso'), 'placeholder' => $t('BS Hotel Management, Senior High, TESDA NC II…', 'BS Hotel Management, Senior High, TESDA NC II…'), 'required' => true],
                    ['name' => 'education.0.school', 'label' => $t('Paaralan', 'School', 'Paaralan'), 'placeholder' => ''],
                    ['name' => 'education.0.year', 'label' => $t('Taon natapos', 'Year finished', 'Taon natapos'), 'placeholder' => '2018', 'kind' => 'year'],
                ];
                break;
            case 'licences':
                $s['kind'] = 'multichips'; $s['field'] = 'licences'; $s['skippable'] = true; $s['other'] = true;
                $s['q'] = $t('Alin sa mga ito ang meron ka? Pindutin lahat ng applicable.', 'Which of these do you have? Tap all that apply.', 'Alin sa mga ito ang mayroon ka? Pindutin ang lahat ng naaangkop.');
                $pool = [['TESDA NC II', 'TESDA NC II', 'TESDA NC II'], ['PRC licence', 'PRC licence', 'PRC licence'], ['NBI clearance', 'NBI clearance', 'NBI clearance'], ['OWWA membership', 'OWWA membership', 'OWWA membership'], ['Food safety / PIC', 'Food safety / PIC', 'Food safety / PIC'], ['Basic Life Support', 'Basic Life Support', 'Basic Life Support'], ['First aid', 'First aid', 'First aid']];
                $pool[] = $region === 'QA' ? ['Qatar driving licence', 'Qatar driving licence', 'Qatar driving licence'] : ['UAE driving licence', 'UAE driving licence', 'UAE driving licence'];
                $pool[] = ['Philippine driving licence', 'PH driving licence', 'PH driving licence'];
                $s['chips'] = $chips($pool);
                break;
            case 'skills':
                $s['field'] = 'skills'; $s['list'] = true; $s['q'] = $t('Ano ang mga skills mo? Paghiwalayin ng comma.', 'What are your skills? Separate with commas.', 'Ano ang iyong mga kasanayan? Paghiwalayin ng kuwit.');
                $s['help'] = $t('Hal. customer service, cash handling, MS Excel, forklift, barista', 'e.g. customer service, cash handling, MS Excel, forklift, barista');
                break;
            case 'languages':
                $s['kind'] = 'multichips'; $s['field'] = 'languages'; $s['other'] = true;
                $s['q'] = $t('Anong mga wika ang sinasalita mo?', 'Which languages do you speak?', 'Anong mga wika ang iyong sinasalita?');
                $s['chips'] = $chips([['English', 'English', 'English'], ['Filipino', 'Filipino', 'Filipino'], ['Arabic (basic)', 'Arabic (basic)', 'Arabic (basic)'], ['Arabic (fluent)', 'Arabic (fluent)', 'Arabic (fluent)'], ['Cebuano', 'Cebuano', 'Cebuano'], ['Hindi/Urdu (basic)', 'Hindi/Urdu (basic)', 'Hindi/Urdu (basic)']]);
                break;
            case 'visa_status':
                $s['kind'] = 'chips'; $s['field'] = 'person.visa_status';
                $s['q'] = $t('Ano ang visa status mo ngayon?', 'What is your visa status now?', 'Ano ang iyong visa status ngayon?');
                $s['chips'] = $chips($region === 'QA'
                    ? [['Employment visa (transferable)', 'Employment visa, transferable', 'Employment visa, transferable'], ['Employment visa (NOC needed)', 'Employment visa, NOC needed', 'Employment visa, NOC needed'], ['Visit / business visa', 'Visit / business visa', 'Visit / business visa'], ['Family sponsorship', 'Family sponsorship', 'Family sponsorship'], ['In the Philippines', 'Nasa Pilipinas', 'In the Philippines', 'Nasa Pilipinas']]
                    : [['Employment visa (transferable)', 'Employment visa, transferable', 'Employment visa, transferable'], ['Visit visa', 'Visit visa', 'Visit visa'], ['Cancelled visa / job seeker', 'Cancelled visa / job seeker', 'Cancelled visa / job seeker'], ['Family / spouse sponsorship', 'Family / spouse sponsorship', 'Family / spouse sponsorship'], ['Golden / freelance visa', 'Golden / freelance visa', 'Golden / freelance visa'], ['In the Philippines', 'Nasa Pilipinas', 'In the Philippines', 'Nasa Pilipinas']]);
                break;
            case 'availability':
                $s['kind'] = 'chips'; $s['field'] = 'person.availability';
                $s['q'] = $t('Kailan ka pwedeng magsimula?', 'When can you start?', 'Kailan ka maaaring magsimula?');
                $s['chips'] = $chips([['Immediately', 'Agad-agad', 'Immediately', 'Agad-agad'], ['Within 30 days', 'Sa loob ng 30 araw', 'Within 30 days', 'Sa loob ng 30 araw'], ['After notice period', 'Pagkatapos ng notice period', 'After my notice period', 'Pagkatapos ng notice period']]);
                break;
            case 'photo':
                $s['kind'] = 'file'; $s['field'] = 'person.photo_path'; $s['skippable'] = true; $s['accept'] = 'image/*';
                $s['q'] = $t('Gusto mo bang maglagay ng photo? Karaniwan ito sa Gulf CVs. Optional.', 'Want to add a photo? Common on Gulf CVs. Optional.', 'Nais mo bang maglagay ng larawan? Karaniwan ito sa mga Gulf CV. Opsyonal.');
                break;
            case 'finish':
                $s['kind'] = 'done'; $s['q'] = $t('Salamat, kabayan! Inaayos ko na ang CV mo…', 'Thank you! I am putting your CV together…', 'Salamat, kabayan! Inaayos ko na ang iyong CV…');
                break;
            case 'upload':
                $s['kind'] = 'file'; $s['accept'] = '.pdf,.doc,.docx,image/*'; $s['field'] = '_upload';
                $s['q'] = $t('I-upload ang CV mo: PDF, Word, o litrato ng papel.', 'Upload your CV: PDF, Word, or a photo of the paper.', 'I-upload ang iyong CV: PDF, Word, o larawan ng papel.');
                $s['help'] = $t('Hanggang 10 MB. Kung litrato, siguraduhing malinaw at maliwanag.', 'Up to 10 MB. If it is a photo, make sure it is clear and well lit.');
                break;
            case 'confirm':
                $s['kind'] = 'confirm'; $s['q'] = $t('Ito ba ang nabasa ko nang tama? I-tap ang kahit anong mali para ayusin.', 'Did I read this correctly? Tap anything wrong to fix it.', 'Tama ba ang nabasa ko? Pindutin ang anumang mali upang ayusin.');
                break;
            default:
                $s['kind'] = 'info'; $s['q'] = '';
        }
        return $s;
    }

    /** The next step id after $current, given progress (job loop). */
    public static function next(string $current, array $progress, string $path = 'interview'): string
    {
        if ($path === 'upload') {
            return match ($current) { 'path' => 'region', 'region' => 'upload', 'upload' => 'confirm', 'confirm' => 'gaps', 'gaps' => 'finish', default => 'finish' };
        }
        if ($current === 'job_more') return !empty($progress['add_job']) ? 'job_employer' : 'education';
        $i = array_search($current, self::ORDER, true);
        if ($i === false) return 'finish';
        return self::ORDER[$i + 1] ?? 'finish';
    }

    /** Progress 0..100 for the UI. */
    public static function progress(string $current, array $progress): int
    {
        $i = array_search($current, self::ORDER, true); if ($i === false) return 100;
        return (int) round(($i / (count(self::ORDER) - 1)) * 100);
    }

    /** Short UI strings the front end needs, per language. */
    public static function ui(string $lang): array
    {
        $t = fn (string $tl, string $en, string $fil = '') => self::t($lang, $tl, $en, $fil);
        return [
            'skip' => $t('Laktawan', 'Skip', 'Laktawan'), 'next' => $t('Susunod', 'Next', 'Susunod'), 'back' => $t('Bumalik', 'Back', 'Bumalik'), 'send' => $t('Ipadala', 'Send', 'Ipadala'),
            'preview' => $t('Tingnan ang CV', 'Preview CV', 'Tingnan ang CV'), 'download' => $t('I-download ang PDF', 'Download PDF', 'I-download ang PDF'), 'email_me' => $t('I-email sa akin', 'Email it to me', 'I-email sa akin'),
            'edit' => $t('I-edit', 'Edit', 'I-edit'), 'save' => $t('I-save', 'Save', 'I-save'), 'improve' => $t('Pagandahin', 'Improve this', 'Pagandahin'), 'delete_data' => $t('Burahin ang data ko', 'Delete my data', 'Burahin ang aking data'),
            'add_job' => $t('Magdagdag ng trabaho', 'Add a job', 'Magdagdag ng trabaho'), 'remove' => $t('Alisin', 'Remove', 'Alisin'), 'other' => $t('Iba pa…', 'Other…', 'Iba pa…'), 'required' => $t('Kailangan ito', 'This is required', 'Kailangan ito'),
            'writing' => $t('Sinusulat ko na ang CV mo…', 'Writing your CV…', 'Sinusulat ko na ang iyong CV…'), 'done_title' => $t('Handa na ang CV mo, kabayan!', 'Your CV is ready!', 'Handa na ang iyong CV, kabayan!'),
            'done_body' => $t('I-check ang preview, i-tap ang kahit ano para i-edit, tapos i-download.', 'Check the preview, tap anything to edit, then download.', 'Suriin ang preview, pindutin ang anuman upang i-edit, pagkatapos ay i-download.'),
            'limit_title' => $t('Sapat na muna ngayon', 'That is enough for today', 'Sapat na muna ngayon'), 'limit_body' => $t('Nakagawa ka na ng maximum na CV ngayong araw. Bumalik bukas — nandito pa rin ang mga nagawa mo.', 'You have reached today\'s limit. Come back tomorrow; what you built is still here.', 'Naabot mo na ang limitasyon ngayong araw. Bumalik bukas; nandito pa rin ang iyong mga nagawa.'),
            'economy' => $t('Nagpapahinga muna ang smart writing ngayon; gumagana pa rin ang CV mo.', 'Smart writing is resting today; your CV still works.', 'Nagpapahinga muna ang smart writing ngayon; gumagana pa rin ang iyong CV.'),
            'consent' => $t('Libre ito. Walang account. Itatago ang data mo nang 30 araw (12 buwan kung i-email mo sa sarili mo), pwede mong burahin anumang oras, at hindi ibibigay sa employer maliban kung ikaw mismo ang mag-apply.', 'Free. No account. Your data is kept 30 days (12 months if you email it to yourself), you can delete it any time, and it is never shared with employers unless you apply.', 'Libre ito. Walang account. Itatago ang iyong data nang 30 araw (12 buwan kung i-email mo sa iyong sarili), maaari mong burahin anumang oras, at hindi ibibigay sa employer maliban kung ikaw mismo ang mag-apply.'),
            'agree' => $t('Sige, simulan na', 'OK, let\'s start', 'Sige, simulan na'), 'lang_q' => $t('Anong wika ang gusto mo?', 'Which language do you prefer?', 'Anong wika ang nais mo?'),
            'upload_reading' => $t('Binabasa ko ang CV mo…', 'Reading your CV…', 'Binabasa ko ang iyong CV…'), 'upload_failed' => $t('Hindi ko mabasa nang maayos. Subukan ang mas malinaw na litrato o PDF, o sagutin na lang ang mga tanong.', 'I could not read it well. Try a clearer photo or a PDF, or just answer the questions instead.', 'Hindi ko ito mabasa nang maayos. Subukan ang mas malinaw na larawan o PDF, o sagutin na lamang ang mga tanong.'),
            'sections' => ['summary' => $t('Buod', 'Summary', 'Buod'), 'experience' => $t('Karanasan', 'Experience', 'Karanasan'), 'education' => $t('Edukasyon', 'Education', 'Edukasyon'), 'licences' => $t('Lisensya at sertipiko', 'Licences & certificates', 'Lisensya at sertipiko'), 'skills' => $t('Mga kasanayan', 'Skills', 'Mga kasanayan'), 'languages' => $t('Mga wika', 'Languages', 'Mga wika'), 'details' => $t('Personal na detalye', 'Personal details', 'Personal na detalye')],
        ];
    }
}
