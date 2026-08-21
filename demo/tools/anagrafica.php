<?php

/**
 * Aziende e dipendenti inventati per il set di prova.
 *
 * I codici fiscali sono verosimili ma non appartengono a nessuno: i primi
 * quindici caratteri sono costruiti a mano e il carattere di controllo e'
 * calcolato, perche' altrimenti il caso d'uso scarta il valore e il campo
 * resta vuoto nel documento estratto.
 *
 * Le email usano domini di fantasia sotto .test, riservato proprio a questo
 * dall'RFC 2606: nessuna di esse puo' corrispondere a una casella reale.
 */

require_once __DIR__.'/support.php';

/**
 * @return array<string, array<string, string>>
 */
function aziende(): array
{
    return [
        'meridiana' => [
            'nome' => 'Meridiana Logistica S.p.A.',
            'indirizzo' => 'Via delle Officine 42, 35127 Padova (PD)',
            'piva' => '03918470281',
            'dominio' => 'meridianalogistica.test',
            'ink' => '#1d3557', 'accent' => '#457b9d', 'wash' => '#f1faee',
        ],
        'valconca' => [
            'nome' => 'Valconca Manifatture S.r.l.',
            'indirizzo' => 'Strada Provinciale 17, 47833 Morciano di Romagna (RN)',
            'piva' => '02674930409',
            'dominio' => 'valconca.test',
            'ink' => '#3d2b1f', 'accent' => '#a0522d', 'wash' => '#faf3e8',
        ],
        'santelena' => [
            'nome' => "Societa' Agricola Sant'Elena S.r.l.",
            'indirizzo' => 'Localita\' Fontanelle 8, 53024 Montalcino (SI)',
            'piva' => '01298760521',
            'dominio' => 'santelena.test',
            'ink' => '#2d4a22', 'accent' => '#6a994e', 'wash' => '#f4f9ee',
        ],
        'delta' => [
            'nome' => 'Delta Ingegneria Consortile S.c.a.r.l.',
            'indirizzo' => 'Viale della Scienza 210, 36100 Vicenza (VI)',
            'piva' => '03744120245',
            'dominio' => 'deltaingegneria.test',
            'ink' => '#2b2d42', 'accent' => '#5c677d', 'wash' => '#f0f1f5',
        ],
        'ostuni' => [
            'nome' => 'Ostuni Servizi Alberghieri S.r.l.',
            'indirizzo' => 'Corso Vittorio Emanuele 55, 72017 Ostuni (BR)',
            'piva' => '02411870748',
            'dominio' => 'ostuniservizi.test',
            'ink' => '#1b4965', 'accent' => '#5fa8d3', 'wash' => '#eef7fb',
        ],
    ];
}

/**
 * Dipendenti raggruppati per azienda.
 *
 * @return array<string, list<array<string, string>>>
 */
function dipendenti(): array
{
    $persone = [
        'meridiana' => [
            ['Giulia', 'Ferraris', 'FRRGLI88M52L781', 'MTR-10428', '5° livello', 'Impiegata amministrativa'],
            ['Alessandro', 'Bertolino', 'BRTLSN79E14G224', 'MTR-10871', '3° livello', 'Magazziniere specializzato'],
            ['Chiara', 'Mazzoleni', 'MZZCHR92T45A794', 'MTR-11035', '6° livello', 'Responsabile spedizioni'],
            ['Davide', 'Sorrentino', 'SRRDVD85H09F839', 'MTR-11204', '4° livello', 'Autista patente CE'],
            ['Ilaria', 'Fontanesi', 'FNTLRI94D61H501', 'MTR-11390', '5° livello', 'Addetta pianificazione'],
        ],
        'valconca' => [
            ['Marco', 'Ravaioli', 'RVLMRC81C22H294', 'VLC-2210', '4° livello', 'Operatore macchine utensili'],
            ['Sabrina', 'Corvino', 'CRVSRN90S58D643', 'VLC-2287', '5° livello', 'Controllo qualita\''],
            ['Nicola', 'Belletti', 'BLLNCL76B18C573', 'VLC-2011', '6° livello', 'Capoturno produzione'],
            ['Elisa', 'Guerrini', 'GRRLSE95P49G337', 'VLC-2345', '3° livello', 'Addetta confezionamento'],
        ],
        'santelena' => [
            ['Tommaso', 'Baldini', 'BLDTMS83L07B354', 'SEL-0117', '4° livello', 'Cantiniere'],
            ['Federica', 'Lombardi', 'LMBFRC91R55E202', 'SEL-0143', '5° livello', 'Responsabile vendite'],
            ['Youssef', 'El Amrani', 'LMRYSF87A28Z330', 'SEL-0166', '3° livello', 'Operaio agricolo'],
        ],
        'delta' => [
            ['Silvia', 'Zanardi', 'ZNRSLV89T63L840', 'DLT-4402', '7° livello', 'Project manager'],
            ['Andrea', 'Pellegrino', 'PLLNDR84M15L219', 'DLT-4418', '6° livello', 'Ingegnere strutturista'],
            ['Martina', 'Cavallaro', 'CVLMTN96E52C351', 'DLT-4501', '5° livello', 'Disegnatrice CAD'],
            ['Roberto', 'Nicolosi', 'NCLRRT77D30I754', 'DLT-4209', '7° livello', 'Direttore tecnico'],
        ],
        'ostuni' => [
            ['Valentina', 'Palumbo', 'PLMVNT93B47F152', 'OST-8801', '4° livello', 'Receptionist'],
            ['Gianluca', 'Marrone', 'MRRGLC82H12L049', 'OST-8834', '5° livello', 'Capo servizio sala'],
            ['Beatrice', 'Sanna', 'SNNBRC97C58B354', 'OST-8877', '3° livello', 'Addetta ai piani'],
            ['Emanuele', 'Tortora', 'TRTMNL80P25A662', 'OST-8790', '6° livello', 'Governante'],
        ],
    ];

    $aziende = aziende();
    $risultato = [];

    foreach ($persone as $chiaveAzienda => $elenco) {
        $dominio = $aziende[$chiaveAzienda]['dominio'];

        foreach ($elenco as [$nome, $cognome, $cf15, $matricola, $livello, $qualifica]) {
            $risultato[$chiaveAzienda][] = [
                'nome' => $nome,
                'cognome' => $cognome,
                'cf' => codiceFiscaleCompleto($cf15),
                'matricola' => $matricola,
                'livello' => $livello,
                'qualifica' => $qualifica,
                'email' => strtolower(
                    str_replace([' ', "'"], ['', ''], $nome).'.'.str_replace([' ', "'"], ['', ''], $cognome).'@'.$dominio
                ),
            ];
        }
    }

    return $risultato;
}
