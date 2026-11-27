<?php
declare(strict_types=1);

/**
 * Try Now outbound SMS: deterministic GSM-7-safe ASCII text.
 *
 * This project deliberately uses an ASCII subset of GSM 03.38 so SMS copy is
 * consistent across Sendmode, Irish and UK networks. Keep LF line breaks.
 * Conversion happens AFTER personalised fields have been substituted.
 *
 * No intl/iconv extension is required (Windows + Plesk PHP 7.4+ compatible).
 * Untransliterable non-emoji characters fail closed rather than sending a
 * Unicode SMS or silently corrupting a customer's name.
 */

/** True only for printable ASCII GSM-7 characters and LF. */
function stTryNowSmsIsGsm7Safe(string $sms): bool
{
    // GSM 03.38 does not include the ASCII backtick (U+0060).
    // Other ASCII punctuation is GSM-7 default or extension-table text.
    return preg_match('/\A[\x20-\x5F\x61-\x7E\n]*\z/D', $sms) === 1;
}

/** Replace unsupported punctuation, fold Latin letters, remove emoji. */
function stTryNowSmsToGsm7(string $text): string
{
    if (preg_match('//u', $text) !== 1) {
        throw new InvalidArgumentException('SMS text must be valid UTF-8.');
    }

    // No extra line breaks: preserve the supplied LF line structure.
    $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);

    $symbols = [
        '‘' => "'", '’' => "'", '‚' => "'", '‛' => "'", '′' => "'",
        '“' => '"', '”' => '"', '„' => '"', '‟' => '"', '″' => '"',
        '–' => '-', '—' => '-', '‒' => '-', '―' => '-', '‐' => '-',
        '‑' => '-', '−' => '-', '…' => '...', '•' => '-', '●' => '-',
        '·' => '-', '→' => '->', '←' => '<-', '↔' => '<->',
        '€' => 'EUR', '£' => 'GBP', '¥' => 'JPY',
        '©' => '(C)', '®' => '(R)', '™' => '(TM)', '°' => ' deg',
        '×' => 'x', '÷' => '/', 'µ' => 'u', '№' => 'No.',
        '`' => "'",
        'ẞ' => 'SS', 'ß' => 'ss', 'Æ' => 'AE', 'æ' => 'ae',
        'Œ' => 'OE', 'œ' => 'oe', 'Ø' => 'O', 'ø' => 'o',
        'Ð' => 'D', 'ð' => 'd', 'Þ' => 'Th', 'þ' => 'th',
        'Ł' => 'L', 'ł' => 'l', 'Đ' => 'D', 'đ' => 'd',
        'Ħ' => 'H', 'ħ' => 'h', 'Ŋ' => 'N', 'ŋ' => 'n',
        'ı' => 'i', 'İ' => 'I', 'Ĳ' => 'IJ', 'ĳ' => 'ij',
        'Ə' => 'E', 'ə' => 'e', 'Ŧ' => 'T', 'ŧ' => 't',
        'ƒ' => 'f', 'ſ' => 's', 'ŉ' => 'n', 'ĸ' => 'k',
        'ﬀ' => 'ff', 'ﬁ' => 'fi', 'ﬂ' => 'fl',
        'ﬃ' => 'ffi', 'ﬄ' => 'ffl',
        "\u{2028}" => "\n", "\u{2029}" => "\n",
        "\u{200B}" => '', "\u{200C}" => '',
        "\u{200D}" => '', "\u{2060}" => '',
        "\u{FEFF}" => '', "\u{FE0E}" => '', "\u{FE0F}" => '',
    ];
    $text = strtr($text, $symbols);

    // Deterministic Unicode Latin precomposed letters -> ASCII roots.
    // Decomposed accents (combining marks) are removed further below.
    $latin = [
        'A' => 'ÀÁÂÃÄÅĀĂĄǍǞǠǺȀȂȦḀẠẢẤẦẨẪẬẮẰẲẴẶ',
        'B' => 'ḂḄḆ',
        'C' => 'ÇĆĈĊČḈ',
        'D' => 'ĎḊḌḎḐḒ',
        'E' => 'ÈÉÊËĒĔĖĘĚȄȆȨḔḖḘḚḜẸẺẼẾỀỂỄỆ',
        'F' => 'Ḟ',
        'G' => 'ĜĞĠĢǦǴḠ',
        'H' => 'ĤȞḢḤḦḨḪ',
        'I' => 'ÌÍÎÏĨĪĬĮİǏȈȊḬḮỈỊ',
        'J' => 'Ĵ',
        'K' => 'ĶǨḰḲḴ',
        'L' => 'ĹĻĽḶḸḺḼ',
        'M' => 'ḾṀṂ',
        'N' => 'ÑŃŅŇǸṄṆṈṊ',
        'O' => 'ÒÓÔÕÖŌŎŐƠǑǪǬȌȎȪȬȮȰṌṎṐṒỌỎỐỒỔỖỘỚỜỞỠỢ',
        'P' => 'ṔṖ',
        'R' => 'ŔŖŘȐȒṘṚṜṞ',
        'S' => 'ŚŜŞŠȘṠṢṤṦṨ',
        'T' => 'ŢŤȚṪṬṮṰ',
        'U' => 'ÙÚÛÜŨŪŬŮŰŲƯǓǕǗǙǛȔȖṲṴṶṸṺỤỦỨỪỬỮỰ',
        'V' => 'ṼṾ',
        'W' => 'ŴẀẂẄẆẈ',
        'X' => 'ẊẌ',
        'Y' => 'ÝŶŸȲẎỲỴỶỸ',
        'Z' => 'ŹŻŽẐẒẔ',
        'a' => 'àáâãäåāăąǎǟǡǻȁȃȧḁạảấầẩẫậắằẳẵặ',
        'b' => 'ḃḅḇ',
        'c' => 'çćĉċčḉ',
        'd' => 'ďḋḍḏḑḓ',
        'e' => 'èéêëēĕėęěȅȇȩḕḗḙḛḝẹẻẽếềểễệ',
        'f' => 'ḟ',
        'g' => 'ĝğġģǧǵḡ',
        'h' => 'ĥȟḣḥḧḩḫẖ',
        'i' => 'ìíîïĩīĭįǐȉȋḭḯỉị',
        'j' => 'ĵǰⱼ',
        'k' => 'ķǩḱḳḵ',
        'l' => 'ĺļľḷḹḻḽ',
        'm' => 'ḿṁṃ',
        'n' => 'ñńņňǹṅṇṉṋ',
        'o' => 'òóôõöōŏőơǒǫǭȍȏȫȭȯȱṍṏṑṓọỏốồổỗộớờởỡợ',
        'p' => 'ṕṗ',
        'r' => 'ŕŗřȑȓṙṛṝṟ',
        's' => 'śŝşšſșṡṣṥṧṩẛ',
        't' => 'ţťțṫṭṯṱẗ',
        'u' => 'ùúûüũūŭůűųưǔǖǘǚǜȕȗṳṵṷṹṻụủứừửữự',
        'v' => 'ṽṿ',
        'w' => 'ŵẁẃẅẇẉẘ',
        'x' => 'ẋẍ',
        'y' => 'ýÿŷȳẏẙỳỵỷỹ',
        'z' => 'źżžẑẓẕ',
    ];
    $folds = [];
    foreach ($latin as $ascii => $letters) {
        $matches = [];
        preg_match_all('/./us', $letters, $matches);
        foreach ($matches[0] as $letter) {
            $folds[$letter] = $ascii;
        }
    }
    $text = strtr($text, $folds);

    // Non-breaking and other typographic spaces become ordinary spaces.
    $text = preg_replace('/\p{Zs}/u', ' ', $text);
    // Combining accents and variation selectors must not trigger UCS-2.
    $text = preg_replace('/\p{M}/u', '', $text);
    // Emoji/pictographic symbols cannot be represented meaningfully; omit.
    $text = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $text);

    if (!is_string($text)) {
        throw new RuntimeException('Unable to normalise SMS text.');
    }

    // Trim invisible trailing whitespace without changing any line breaks.
    $text = preg_replace('/[ ]+$/m', '', $text);
    if (!is_string($text) || trim($text) === '' || !stTryNowSmsIsGsm7Safe($text)) {
        throw new InvalidArgumentException('SMS contains unsupported GSM-7 characters.');
    }

    return $text;
}
