<?php
declare(strict_types=1);

/**
 * Kontakte sind die zentrale Datenbasis. Zu-/Absagen und Kondolenzen
 * sind Teilbereiche desselben Datensatzes.
 */
final class Contacts
{
    public const RSVP = ['', 'open', 'yes', 'no'];

    public const FILTERS = ['', 'called_open', 'letter_open', 'rsvp_yes', 'rsvp_no', 'rsvp_open', 'cond_any', 'thanks_open'];

    /** @return array<string, string> RSVP-Status => übersetzte Beschriftung ('' = nicht eingeladen) */
    public static function rsvpLabels(): array
    {
        return array_combine(self::RSVP, array_map(fn($k) => t('rsvp.' . ($k === '' ? 'none' : $k)), self::RSVP));
    }

    /** @return array<string, string> Filter => übersetzte Beschriftung */
    public static function filterLabels(): array
    {
        return array_combine(self::FILTERS, array_map(fn($k) => t('filter.' . ($k === '' ? 'all' : $k)), self::FILTERS));
    }

    /** Vorschläge für das Feld „Bundesland“ (Freitext bleibt möglich, z. B. für das Ausland). */
    public const STATES = ['Baden-Württemberg', 'Bayern', 'Berlin', 'Brandenburg', 'Bremen', 'Hamburg', 'Hessen',
        'Mecklenburg-Vorpommern', 'Niedersachsen', 'Nordrhein-Westfalen', 'Rheinland-Pfalz', 'Saarland',
        'Sachsen', 'Sachsen-Anhalt', 'Schleswig-Holstein', 'Thüringen'];

    /**
     * Textfelder, die in der Tabellenansicht direkt bearbeitet werden können.
     * Schlüssel => [Feldrecht (Perm::FIELDS) oder null, maximale Länge, Standardspalte, Breite in px]; Beschriftung: t('cf.<Schlüssel>')
     */
    public const TEXT_FIELDS = [
        'salutation' => [null, 500, false, 90],
        'first'      => [null, 500, true, 140],
        'last'       => [null, 500, true, 150],
        'relation'   => [null, 500, true, 140],
        'street'     => ['address', 200, false, 200],
        'zip'        => ['address', 200, true, 80],
        'city'       => ['address', 200, true, 150],
        'state'      => ['address', 200, false, 160],
        'country'    => ['address', 200, false, 120],
        'phone'      => ['phone', 200, false, 140],
        'mobile'     => ['mobile', 200, false, 140],
        'email'      => ['email', 200, false, 220],
        'note'       => [null, 500, false, 240],
    ];

    /**
     * Spalten der Tabellenansicht, gefiltert nach den Rechten des Benutzers.
     * @return list<array{key:string,label:string,type:string,edit:bool,on:bool,w:int}>
     */
    public static function tableColumns(): array
    {
        $edit = Perm::can('contacts', Perm::EDIT);
        $check = Perm::can('contacts', Perm::CHECK);
        $cols = [];
        foreach (self::TEXT_FIELDS as $key => [$perm, , $on, $w]) {
            $label = t('cf.' . $key);
            if ($key === 'note') {
                $cols[] = ['key' => 'called', 'label' => t('cf.called'), 'type' => 'flag', 'edit' => $check, 'on' => true, 'w' => 110];
                $cols[] = ['key' => 'letter', 'label' => t('cf.letter'), 'type' => 'flag', 'edit' => $check, 'on' => true, 'w' => 130];
                if (Perm::can('rsvp')) {
                    $cols[] = ['key' => 'rsvp', 'label' => t('cf.rsvp'), 'type' => 'rsvp', 'edit' => Perm::can('rsvp', Perm::CHECK), 'on' => true, 'w' => 140];
                    $cols[] = ['key' => 'persons', 'label' => t('cf.persons'), 'type' => 'num', 'edit' => false, 'on' => false, 'w' => 90];
                }
            }
            if ($perm !== null && !Perm::field($perm)) {
                continue;
            }
            $cols[] = ['key' => $key, 'label' => $label, 'type' => 'text', 'edit' => $edit, 'on' => $on, 'w' => $w];
        }
        if (Auth::isAdmin()) {
            $cols[] = ['key' => 'access', 'label' => t('cf.access'), 'type' => 'text', 'edit' => false, 'on' => false, 'w' => 150];
        }
        return $cols;
    }

    public static function blank(): array
    {
        return [
            'id' => '', 'salutation' => '', 'first' => '', 'last' => '', 'relation' => '',
            'street' => '', 'zip' => '', 'city' => '', 'state' => '', 'country' => '',
            'phone' => '', 'mobile' => '', 'email' => '', 'note' => '',
            'called' => false, 'letter' => false,
            'rsvp' => '', 'adults' => 0, 'children' => 0, 'rsvp_note' => '',
            'card' => false, 'flowers' => false, 'wreath' => false, 'donation' => false,
            'amount' => 0.0, 'cond_note' => '', 'thanks' => false,
            'created' => '', 'updated' => '', 'updated_by' => '',
        ];
    }

    public static function all(): array
    {
        $list = array_map(fn($c) => array_merge(self::blank(), $c), Store::read('contacts'));
        usort($list, fn($a, $b) => strcasecmp($a['last'] . ' ' . $a['first'], $b['last'] . ' ' . $b['first']));
        return $list;
    }

    public static function hasCondolence(array $c): bool
    {
        return $c['card'] || $c['flowers'] || $c['wreath'] || $c['donation'];
    }

    public static function filter(array $list, string $filter, string $relation = ''): array
    {
        return array_values(array_filter($list, function ($c) use ($filter, $relation) {
            if ($relation !== '' && $c['relation'] !== $relation) {
                return false;
            }
            return match ($filter) {
                'called_open' => !$c['called'],
                'letter_open' => !$c['letter'],
                'rsvp_yes'    => $c['rsvp'] === 'yes',
                'rsvp_no'     => $c['rsvp'] === 'no',
                'rsvp_open'   => $c['rsvp'] === 'open',
                'cond_any'    => self::hasCondolence($c),
                'thanks_open' => self::hasCondolence($c) && !$c['thanks'],
                default       => true,
            };
        }));
    }

    public static function relations(array $list): array
    {
        $r = array_unique(array_filter(array_map(fn($c) => $c['relation'], $list)));
        sort($r, SORT_NATURAL | SORT_FLAG_CASE);
        return $r;
    }

    public static function fullName(array $c): string
    {
        return trim($c['first'] . ' ' . $c['last']);
    }

    public static function rsvpSums(array $list): array
    {
        $s = ['yes' => 0, 'no' => 0, 'open' => 0, 'adults' => 0, 'children' => 0];
        foreach ($list as $c) {
            if (isset($s[$c['rsvp']])) {
                $s[$c['rsvp']]++;
            }
            if ($c['rsvp'] === 'yes') {
                $s['adults'] += (int)$c['adults'];
                $s['children'] += (int)$c['children'];
            }
        }
        return $s;
    }

    /** Übernimmt Formularwerte in einen Datensatz (nur Felder, die bearbeitet werden dürfen). */
    public static function fromForm(array $c, array $in): array
    {
        foreach (['salutation', 'first', 'last', 'relation', 'note'] as $k) {
            $c[$k] = mb_substr(trim((string)($in[$k] ?? '')), 0, 500);
        }
        $fieldMap = [
            'address' => ['street', 'zip', 'city', 'state', 'country'],
            'phone'   => ['phone'],
            'mobile'  => ['mobile'],
            'email'   => ['email'],
        ];
        foreach ($fieldMap as $perm => $keys) {
            if (!Perm::field($perm)) {
                continue; // verborgene Felder bleiben unverändert
            }
            foreach ($keys as $k) {
                $c[$k] = mb_substr(trim((string)($in[$k] ?? '')), 0, 200);
            }
        }
        if (Perm::can('contacts', Perm::CHECK)) {
            $c['called'] = !empty($in['called']);
            $c['letter'] = !empty($in['letter']);
        }
        if (Perm::can('rsvp', Perm::CHECK)) {
            $c['rsvp'] = in_array((string)($in['rsvp'] ?? ''), self::RSVP, true) ? (string)($in['rsvp'] ?? '') : '';
            $c['adults'] = max(0, min(99, (int)($in['adults'] ?? 0)));
            $c['children'] = max(0, min(99, (int)($in['children'] ?? 0)));
            $c['rsvp_note'] = mb_substr(trim((string)($in['rsvp_note'] ?? '')), 0, 500);
        }
        if (Perm::can('condolences', Perm::CHECK)) {
            foreach (['card', 'flowers', 'wreath', 'donation', 'thanks'] as $k) {
                $c[$k] = !empty($in[$k]);
            }
            $c['amount'] = max(0, (float)str_replace(',', '.', (string)($in['amount'] ?? '0')));
            $c['cond_note'] = mb_substr(trim((string)($in['cond_note'] ?? '')), 0, 500);
        }
        return $c;
    }

    /** Export-Spalten je nach Bereich und Feldrechten. */
    public static function exportTable(array $list, string $set): array
    {
        $h = [t('ex.salutation'), t('ex.first'), t('ex.last'), t('ex.relation')];
        if (Perm::field('address')) {
            array_push($h, t('ex.street'), t('ex.zip'), t('ex.city'), t('ex.state'), t('ex.country'));
        }
        if (Perm::field('phone')) {
            $h[] = t('ex.phone');
        }
        if (Perm::field('mobile')) {
            $h[] = t('ex.mobile');
        }
        if (Perm::field('email')) {
            $h[] = t('ex.email');
        }
        match ($set) {
            'rsvp'        => array_push($h, t('ex.rsvp'), t('ex.adults'), t('ex.children'), t('ex.rsvp_note')),
            'condolences' => array_push($h, t('cond.card'), t('cond.flowers'), t('cond.wreath'), t('cond.donation'), t('ex.amount'), t('cond.thanks'), t('ex.cond_note')),
            default       => array_push($h, t('ex.called'), t('ex.letter'), t('ex.note')),
        };

        $yn = fn($b) => t($b ? 'ex.yes' : 'ex.no');
        $rsvpLabels = self::rsvpLabels();
        $rows = [];
        foreach ($list as $c) {
            $r = [$c['salutation'], $c['first'], $c['last'], $c['relation']];
            if (Perm::field('address')) {
                array_push($r, $c['street'], $c['zip'], $c['city'], $c['state'], $c['country']);
            }
            if (Perm::field('phone')) {
                $r[] = $c['phone'];
            }
            if (Perm::field('mobile')) {
                $r[] = $c['mobile'];
            }
            if (Perm::field('email')) {
                $r[] = $c['email'];
            }
            match ($set) {
                'rsvp' => array_push($r, $rsvpLabels[$c['rsvp']] ?? '', (int)$c['adults'], (int)$c['children'], $c['rsvp_note']),
                'condolences' => array_push($r, $yn($c['card']), $yn($c['flowers']), $yn($c['wreath']),
                    $yn($c['donation']), (float)$c['amount'], $yn($c['thanks']), $c['cond_note']),
                default => array_push($r, $yn($c['called']), $yn($c['letter']), $c['note']),
            };
            $rows[] = $r;
        }
        return [$h, $rows];
    }

    /** Dublettenprüfung: gleicher Name oder gleiche Telefonnummer. */
    public static function isDuplicate(array $list, array $c): bool
    {
        $norm = fn($s) => preg_replace('/\D/', '', (string)$s);
        $name = mb_strtolower(self::fullName($c));
        $nums = array_filter([$norm($c['phone']), $norm($c['mobile'])]);
        foreach ($list as $x) {
            if ($name !== '' && mb_strtolower(self::fullName($x)) === $name) {
                return true;
            }
            foreach ([$norm($x['phone']), $norm($x['mobile'])] as $n) {
                if ($n !== '' && in_array($n, $nums, true)) {
                    return true;
                }
            }
        }
        return false;
    }
}
