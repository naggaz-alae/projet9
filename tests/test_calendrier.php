<?php

declare(strict_types=1);

$d = fn (string $date) => new DateTimeImmutable($date);

test('Fêtes nationales du Maroc : dates fixes', function () {
    $f = Calendrier::feriesFixes(2026);
    egal('Fête du Trône', $f['2026-07-30']);
    egal('Marche Verte', $f['2026-11-06']);
    egal("Fête de l'Indépendance", $f['2026-11-18']);
    egal(11, count($f), '11 fêtes civiles en 2026');
});

test('Fêtes nationales : selon l\'année de création', function () {
    // Stage de 2021 : ni Nouvel an amazigh (depuis 2024), ni Fête de l'Unité (depuis 2026)
    $f2021 = Calendrier::feriesFixes(2021);
    egal(9, count($f2021));
    vrai(!isset($f2021['2021-01-14']) && !isset($f2021['2021-10-31']), 'pas de fêtes postérieures en 2021');
    vrai(isset(Calendrier::feriesFixes(2024)['2024-01-14']), 'Nouvel an amazigh en 2024');
    vrai(!isset(Calendrier::feriesFixes(2025)['2025-10-31']), "pas de Fête de l'Unité en 2025");
});

test('Fêtes religieuses : prises en compte une fois saisies', function () use ($d) {
    egal(null, Calendrier::ferie($d('2026-05-27')));
    Calendrier::definirFeriesVariables(['2026-05-27' => 'Aïd al-Adha', '2026-05-28' => 'Aïd al-Adha']);
    egal('Aïd al-Adha', Calendrier::ferie($d('2026-05-27')));
    // Semaine du 25 mai 2026 : 5 jours - 2 jours d'Aïd
    egal(3.0, Calendrier::joursOuvres($d('2026-05-25'), $d('2026-05-29')));
});

test('Jours décomptés : semaine normale, fériés, week-end', function () use ($d) {
    egal(5.0, Calendrier::joursOuvres($d('2026-10-05'), $d('2026-10-11')));
    egal(4.0, Calendrier::joursOuvres($d('2026-11-02'), $d('2026-11-06')), 'Marche Verte le vendredi 6');
    egal(0.0, Calendrier::joursOuvres($d('2026-10-10'), $d('2026-10-11')));
});

test('Jours décomptés : demi-journées', function () use ($d) {
    egal(4.5, Calendrier::joursOuvres($d('2026-10-05'), $d('2026-10-09'), true));
    egal(4.0, Calendrier::joursOuvres($d('2026-10-05'), $d('2026-10-09'), true, true));
    egal(0.5, Calendrier::joursOuvres($d('2026-10-05'), $d('2026-10-05'), false, true));
});

test('Jours décomptés : périodes invalides refusées', function () use ($d) {
    erreur_metier(fn () => Calendrier::joursOuvres($d('2026-10-09'), $d('2026-10-05')), 'date de fin');
    erreur_metier(fn () => Calendrier::joursOuvres($d('2026-10-05'), $d('2026-10-05'), true, true));
});

test('Décalage en jours travaillés (saute week-ends et fériés)', function () use ($d) {
    egal('2026-10-05', Calendrier::decalerJoursOuvres($d('2026-10-02'), 1)->format('Y-m-d'));   // vendredi -> lundi
    egal('2026-11-09', Calendrier::decalerJoursOuvres($d('2026-11-05'), 1)->format('Y-m-d'));   // saute la Marche Verte
    egal('2026-10-02', Calendrier::decalerJoursOuvres($d('2026-10-05'), -1)->format('Y-m-d'));
});

test('Horaires officiels : 7 h 30 du lundi au jeudi, 6 h 30 le vendredi (pause prière)', function () use ($d) {
    egal(7 * 3600 + 1800, Horaires::secondesPrevues($d('2026-10-05')));
    egal(6 * 3600 + 1800, Horaires::secondesPrevues($d('2026-10-09')));
    egal(0, Horaires::secondesPrevues($d('2026-10-10')), 'samedi');
    egal(0, Horaires::secondesPrevues($d('2026-11-18')), "Fête de l'Indépendance");
    $semaine = 0;
    for ($j = $d('2026-10-05'); $j <= $d('2026-10-11'); $j = $j->modify('+1 day')) {
        $semaine += Horaires::secondesPrevues($j);
    }
    egal(36 * 3600 + 1800, $semaine, 'semaine complète : 36 h 30');
});

test('Horaires particuliers : ramadan', function () use ($d) {
    Horaires::definirSpeciaux([['nom' => 'Ramadan', 'date_debut' => '2026-02-19', 'date_fin' => '2026-03-19',
        'heure_debut' => '09:00', 'heure_fin' => '15:00', 'pause_minutes' => 0]]);
    egal('09:00', Horaires::du($d('2026-03-02'))['debut']);
    egal(6 * 3600, Horaires::secondesPrevues($d('2026-03-02')));
    egal('08:30', Horaires::du($d('2026-03-23'))['debut'], 'après le ramadan');
});
