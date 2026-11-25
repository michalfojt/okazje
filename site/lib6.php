<?php
declare(strict_types=1);
if (!defined('OKZ')) { http_response_code(403); exit; }

// Grupy produktów z gazetek (np. "Karma i zwierzęta") i ich ukrywanie. Dołączany z lib3.php.
//
// Grupa jest liczona przy odczycie z nazwy pozycji, więc działa też dla już zaimportowanych gazetek.
// data/groups.json: {"hidden": [klucze grup], "over": {slug nazwy: klucz grupy}} (ręczne poprawki).

const GROUP_OVER_MAX = 2000;

/** Klucz => etykieta, w kolejności wyświetlania. */
function group_labels(): array {
    return [
        'mieso' => 'Mięso i wędliny', 'ryby' => 'Ryby i owoce morza', 'nabial' => 'Nabiał i jaja',
        'owoce' => 'Owoce i warzywa', 'pieczywo' => 'Pieczywo i ciasta', 'dania' => 'Dania gotowe i mrożonki',
        'spizarnia' => 'Spiżarnia i przyprawy', 'slodycze' => 'Słodycze i przekąski', 'napoje' => 'Napoje',
        'kawa' => 'Kawa i herbata', 'alkohol' => 'Alkohol', 'dzieci' => 'Dla dzieci i niemowląt',
        'zwierzeta' => 'Karma i zwierzęta', 'chemia' => 'Chemia domowa i papier', 'kosmetyki' => 'Kosmetyki i higiena',
        'elektro' => 'Elektronika, AGD i narzędzia', 'odziez' => 'Odzież i obuwie', 'zabawki' => 'Zabawki i hobby',
        'dom' => 'Dom, ogród i dekoracje', 'inne' => 'Inne',
    ];
}

/**
 * Reguły w kolejności sprawdzania: wygrywa pierwsza pasująca. Tekst jest małymi literami, bez polskich znaków.
 * Kolejność ma znaczenie ("płyn do płukania jamy ustnej" przed chemią, "karma" przed mięsem).
 * Każdy wzorzec zaczyna się na początku słowa.
 */
function group_rules(): array {
    return [
        ['zwierzeta', 'karm[ay]\b|karmy|przysmak\w* dla (ps|kot)|dla (psa|psow|kota|kotow)\b|zwirek|pedigree|whiskas|sheba|felix\b|purina|gourmet gold|activ pet|puffi\b'],

        // jednoznaczne wyjątki, które inaczej złapałaby późniejsza reguła
        ['kosmetyki', 'pod oczy|kul[ea] do kapieli|sol do kapieli|jamy ustnej|past[ay] do zebow|szczotecz|dezodorant|antyperspirant|szampon|odzywka do wlosow|farba do wlosow|lakier do wlosow'],
        ['dzieci', 'bebilon|bebiko|bobovita|hipp\b|gerber|nan optipro|nestle nan|formula na bazie mleka|mleko modyfikowane|kaszk[ai]\b|pampers|pieluch|pieluszk|dada\b|bambino|kuniboo|bepanthen baby|baby food|dla niemowlat|kredki kapielowe'],
        ['spizarnia', 'dzem|w syropie|octy?\b|barilla|koncentrat|chrzan|(wszystkie )?(pomidory suszone|dekoracje do ciast|fixy|dodatki do ciast|mieszanki na piernicz)|siemie|suplement'],
        ['chemia', 'spryskiwacz|sciereczk|rekaw do pieczenia|cif\b|domestos|vizir|agent max|produkty aril|do prania|do plukania|do zmywar|zmywarki|do mycia naczyn|do naczyn|plyn do (mycia|czyszczenia|szyb|podlog|wc)|do czyszczenia|do wc\b|odplamiacz|odswiezacz|(?:papier\w* )toaletow|papier nawilzany|reczniki? papierow|chusteczki higieniczne|folia aluminiowa|folia spozywcza|worki na|papier do pieczenia|gabk[ai]\b|zmywak|ariel\b|persil|lenor|finish\b|fairy\b'],
        ['alkohol', 'piwo\b|piwa\b|wino\b|wina\b|wodka|whisk(e)?y|likier\b|gin\b|rum\b|brandy|prosecco|szampan\b|cydr\b|aperitif|aperol'],
        ['elektro', 'akumulator|wiertar|szlifier|wkretar|parkside|silvercrest|elektryczn|golarka|maszynka do strzyzenia|trymer|oczyszczacz|odkurzacz|mop\b|kamera|telewizor|sluchawk|glosnik|lampk[ai]\b|suszarka|zarowk|bateri[ea]\b|\bagd\b|blender|toster|czajnik|ekspres do|robot kuchenn|zelazko|suszarka do wlosow|powerbank|ladowarka'],
        ['odziez', 'skarpet|rajstop|kurtk|bluz[ay]\b|bluzk|koszulk|koszul[ae]\b|spodnie|leginsy|pizam|bielizn|majtki|biustonosz|czapk|rekawiczk|szalik|buty\b|obuwie|kapcie|klapki|odziez|sweter|dres\w*\b|akcesoria zimowe'],
        ['zabawki', 'zabawk|lalk[ai]|klocki|\blego\b|puzzle|gra planszowa|gry\b|malarsk|kredki|pluszow|maskotk[ai]\b(?!.*\d g)|samochodzik'],
        ['kosmetyki', 'zele? pod prysznic|pasty (sensodyne|do zebow)|sensodyne|dove\b|gliss|schauma|syoss|wellaflex|makijaz|do wlosow|bioelixire|jordan\b|ziaja|kosmetycz|zestawy? kosmetycz|zel do (mycia|kapieli|twarzy|golenia)|mydl[oa]|balsam|maszynk[ai] do golenia|pianka do golenia|golark[ai]|podpask|tampon|wkladki|patyczki|platki kosmetyczne|chusteczki|do twarzy|do rak|do ciala|kosmetyk|perfum|woda toaletowa|zestaw prezentowy|pomadk|tusz do rzes|podklad|protez|serum|maska w plachcie|nivea|garnier|colgate|gillette|old spice|tabliczka do kapieli|plyn do kapieli'],
        ['dom', 'kalanchoe|cyprys|grudnik|gwiazda betlejemska|anturium|storczyk|gozdzik|roz[ae] w|bukiet|doniczk|\bdon\. \d|oslonce|swierk|choink|bombk[ai](?!.*pralin)|dekoracj(?!e cukrow)|aranzacj|kompozycja|swiec[aey]\b|znicz|koc\b|reczniki|posciel|poduszk|garnek|(?<!na )patelni[ae]\b|talerz|kubek|szklank|pojemnik|organizer|walizk|meble|akcesoria wittchen|bombek|roza\b|przybory|wozek kuchenn|podgrzewacz|wklad\w* led'],

        ['nabial', 'serek|jogurt|skyr'],
        ['pieczywo', 'bulk[ai]|buleczk'],
        ['slodycze', 'rurk[ai]|makaronik|kalendarz\w* adwentow|czekolad|czekoladk|pralin|bombonier|cukierk|\bzelk|lizak|ciast(ka|eczka)|herbatnik|biszkopt|wafl|wafelk|baton|paluszki(?! (rybne|z fileta))|chipsy|chrupk|prazynk|krakers|precel|mikolaj(?!kow)|figurk|lody\b|rogaliki|delicje|chalwa|marcepan(?!owa)|mleczko(?! do)|drazetk|zeslodyczami|slodycz|kinder|haribo|maoam|nimm2|orzeszki|orzech|pistacj|migdal|zurawina suszona|bakali|pierniki?\b|piernik w|galaretk[ai] (w|z)|trufle|laska cukrowa|miniciast|mleczna kanapka|snickers|milka\b|daktyle|przekask(?!a z szarp)|smash|bob snail|ciasteczk|krowk[ai]|mentos|donut|paczek|croissant'],
        ['kawa', 'ekspresy delta|kapsulki delta|\bkaw[ay]\b|herbat(a|ka|y)\b|inka\b|cappuccino|jacobs'],
        ['dania', 'uszka|krokiet|lazanki|golabki|tortellini|surowk|mieszanka (chinska|warzywna)|przekaska ala|barchetta|zupki|pizz|pierog|nalesnik|lasagne|penne|danie|dania|\bzup[ay]\b|barszcz|flaki(?! wol\w* (gotowane|krojone))|fasolka po|pulpety|klopsy|golabki w|frytki|paluszki (rybne|z fileta)|nugget|mozzarella sticks|na patelnie|mrozon|mrozna kraina|sushi|kotlety|farsz|kapusta zasmazana|hummus|kebab|zapiekank|bagietka.*guseppe|guseppe|salatk'],
        ['ryby', 'losos|dorsz|dorada|sledz|sledzi|mintaj|miruna|morszczuk|makrela|karp\b|pstrag|zebacz|grenadier|tunczyk|krewetk|malze|ryb[ay]\b|koreczki|matjas|jesiotr|sum\b|witlinek|halibut|rybne'],
        ['nabial', 'maslo(?! orzech)|margaryn|\bser\b|\bsery\b|serek|serk[ai]|twarog|jogurt|skyr|kefir|maslank|smietan(?!kow)|mleko|mleczn[ya] (napoj|deser)|napoj (mleczny|jogurtowy)|deser(?!ow)|radamer|plastry weganskie|pudding|mozzarella|mascarpone|gouda|camembert|jaj[ak]\b|actimel|monte\b|ryz na mleku|budyn'],
        ['mieso', 'mieso|miesa|wieprzow|wolow|kurczak|kurczat|indyk|kaczk|perliczk|schab|karkowk|boczek|szynk|kielbas|kabanos|parowk|paroweczk|frankfurter|poledwic|salami|wedlin|wedzonk|baleron|pasztet(?!ik)|metka|golonk|zeberk|gesi\b|rostbef|ligawa|udk[ao]|\buda\b|filet z piersi|tuszka|drob'],
        ['pieczywo', 'chleb|bul(ka|ki|eczk)|chalk[ai]|bagiet|ciabatta|drozdzowk|ciasto|babka|makowiec|karpatka|sernik\b|malinianka|cynamonka|pinsa|tortill|lawasz|pieczyw|grissini|pane\b|pasztecik|kajzerk|polbagiet|schiacciata|warkocz|pins[ay]\b|rogal'],
        ['napoje', 'napoj|napoje|\bsok\b|soki\b|nektar|woda (mineralna|zrodlana|smakowa|kokosowa|gazowana)|cola|pepsi|fanta|sprite|oranzada|energetyczn|energy drink|red bull|monster|ice tea|syrop|dr pepper|izotonik|woda\b(?! toaletowa)|smoothie'],
        ['owoce', 'pomelo|pome l o|pietruszk|zurawin|smoczy owoc|banan|jablk|gruszk|mandarynk|pomarancz|cytryn|grejpfrut|winogron|borowk|malin[ay]|truskawk|awokado|mango|kiwi|ananas(?!.*(syrop|puszk))|granat|kaki\b|melon|arbuz|pomidor(?!kami)|ogorek\b|ogorki(?! konserw)|papryk|cebul|czosn|ziemniak|batat|marchew|burak|kapust|brokul|kalafior|cukini|salat|rukol|pieczark|boczniak|grzyby|dynia\b|por\b|seler|rzodkiew|owoc[ey]\b|warzyw[ao]\b'],
        ['spizarnia', 'makaron|\bryz\b|kasz[ay]\b|\bmak[ai]\b|\bmak\b|cukier|\bsol\b|olej|oliw[ak]|ocet|ketchup|majonez|musztard|chrzan|\bsos\b|przypraw|koncentrat|puszk|sloik|konserw|korniszon|groszek|kukurydza|fasol|dzem|miod|krem|nutella|platki|granola|musli|masa (makowa|marcepanowa|krowkowa)|belbake|dekoracje cukrow|lukier|pasta cukrowa|pisaki|posypka|proszek do pieczenia|kakao|musy?\b|brzoskwini|drozdze|zelatyna|maslo orzech'],
    ];
}

/** Grupa wyliczona z nazwy; gdy nazwa nic nie mówi, próbuje pola applies_to. */
function group_guess(string $name, ?string $appliesTo = null): string {
    foreach ([$name, (string)$appliesTo] as $text) {
        if (trim($text) === '') continue;
        $t = str_replace(['’', "'"], '', deal_fold($text));
        foreach (group_rules() as [$key, $re]) {
            if (preg_match('~(?<![a-z0-9])(?:' . $re . ')~', $t)) return $key;
        }
    }
    return 'inne';
}

/** @return array{hidden:string[],over:array<string,string>} */
function groups_db(): array {
    $d = jread('groups', []);
    return ['hidden' => array_values((array)($d['hidden'] ?? [])), 'over' => (array)($d['over'] ?? [])];
}

function group_of(array $it, array $over): string {
    $name = (string)($it['name'] ?? '');
    return $over[slug($name)] ?? group_guess($name, $it['applies_to'] ?? null);
}

/** Lista grup do interfejsu. */
function groups_view(): array {
    $hidden = groups_db()['hidden'];
    $out = [];
    foreach (group_labels() as $key => $name) $out[] = ['key' => $key, 'name' => $name, 'hidden' => in_array($key, $hidden, true)];
    return $out;
}

function group_hide(string $key, bool $hide): bool {
    if (!isset(group_labels()[$key])) return false;
    return (bool)locked(function () use ($key, $hide) {
        $d = groups_db();
        $h = array_values(array_diff($d['hidden'], [$key]));
        if ($hide) $h[] = $key;
        $d['hidden'] = $h;
        jwrite('groups', $d);
        return true;
    });
}

/** Ręczne przypisanie pozycji (po nazwie) do grupy; pusty klucz przywraca automat. */
function group_set(string $name, string $key): bool {
    $s = slug($name);
    if ($s === '' || ($key !== '' && !isset(group_labels()[$key]))) return false;
    return (bool)locked(function () use ($s, $key) {
        $d = groups_db();
        unset($d['over'][$s]);
        if ($key !== '') $d['over'][$s] = $key;
        if (count($d['over']) > GROUP_OVER_MAX) $d['over'] = array_slice($d['over'], -GROUP_OVER_MAX, null, true);
        jwrite('groups', $d);
        return true;
    });
}
