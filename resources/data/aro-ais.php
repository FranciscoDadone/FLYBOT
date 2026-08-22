<?php

/**
 * Where a flight plan gets presented, by aerodrome.
 *
 * Transcribed from the ARO/AIS directory EANA publishes for flight plan
 * filing. An `offices` entry is a real AIS office at an aerodrome; `centres`
 * are the five area control centres, one per FIR, which is where an aerodrome
 * without an office of its own falls -- and `airports.fir` already stores
 * exactly those five codes, so the fallback needs no table of its own.
 *
 * Two things about this data are worth knowing before trusting it:
 *
 * The authoritative source is the AIP (GEN and AD 2), not this list. Offices
 * move, addresses change, and nothing here refreshes itself. A wrong address
 * is a flight plan that never arrives, so this file is worth re-reading
 * against the AIP rather than assumed current.
 *
 * The centres have no published address. That is not an omission: a plan is
 * filed with an ACC by telephone, which is why they carry one and no email.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | AIS offices
    |--------------------------------------------------------------------------
    */

    'offices' => [
        'SAAC' => ['name' => 'CONCORDIA COMODORO PIERRESTEGUI', 'email' => 'acdia@eana.com.ar', 'telephone' => '(0345)4252319'],
        'SAAJ' => ['name' => 'JUNIN', 'email' => null, 'telephone' => '(0236)2433385'],
        'SAAP' => ['name' => 'PARANA GRAL. URQUIZA', 'email' => 'acpar@eana.com.ar', 'telephone' => '(0343)4261914'],
        'SAAR' => ['name' => 'ROSARIO ISLAS MALVINAS', 'email' => 'acros@eana.com.ar', 'telephone' => '(0341)155038804'],
        'SAAV' => ['name' => 'SANTA FE SAUCE VIEJO', 'email' => 'acsvo@eana.com.ar', 'telephone' => '(0342)4995065'],
        'SABE' => ['name' => 'BUENOS AIRES AEROPARQUE J. NEWBERY', 'email' => 'acaer@eana.com.ar', 'telephone' => '(011)4514-1513'],
        'SACE' => ['name' => 'ESCUELA DE AVIACION MILITAR', 'email' => null, 'telephone' => '(0351)4666057'],
        'SACO' => ['name' => 'CORDOBA ING. AER. A. L. V. TARAVELLA', 'email' => 'accba@eana.com.ar', 'telephone' => '(0351)4751404'],
        'SADF' => ['name' => 'SAN FERNANDO', 'email' => 'acfdo@eana.com.ar', 'telephone' => '(011)4580-0261'],
        'SADJ' => ['name' => null, 'email' => 'aceno@eana.com.ar', 'telephone' => null],
        'SADM' => ['name' => 'MORON', 'email' => 'acmor@eana.com.ar', 'telephone' => '(011)46279402'],
        'SADP' => ['name' => 'EL PALOMAR', 'email' => 'acpal@eana.com.ar', 'telephone' => '(011)60064271'],
        'SAEZ' => ['name' => 'EZEIZA MINISTRO PISTARINI', 'email' => 'aceze@eana.com.ar', 'telephone' => '(011)44802330'],
        'SAFR' => ['name' => 'RAFAELA', 'email' => null, 'telephone' => '(0349)215565217'],
        'SAFS' => ['name' => 'SUNCHALES AEROCLUB', 'email' => null, 'telephone' => '(0349)3426002'],
        'SAME' => ['name' => 'MENDOZA EL PLUMERILLO', 'email' => 'acdoz@eana.com.ar', 'telephone' => '(0261)4487486'],
        'SAMM' => ['name' => 'MALARGUE COMODORO D. RICARDO SALOMON', 'email' => 'acmlg@eana.com.ar', 'telephone' => '(0260)4471265'],
        'SAMR' => ['name' => 'SAN RAFAEL S.A. SANTIAGO GERMANO', 'email' => 'acsra@eana.com.ar', 'telephone' => '(0260)4430703'],
        'SANC' => ['name' => 'CATAMARCA', 'email' => 'accat@eana.com.ar', 'telephone' => '(0383)4453686'],
        'SANE' => ['name' => 'SANTIAGO DEL ESTERO VCOM. ANGEL DE LA PAZ ARAGONES', 'email' => 'acsde@eana.com.ar', 'telephone' => '(0385)4340710'],
        'SANL' => ['name' => 'LA RIOJA CAP. VICENTE A. ALMONACID', 'email' => 'aclar@eana.com.ar', 'telephone' => '(0380)4439211'],
        'SANR' => ['name' => 'TERMAS DE RIO HONDO', 'email' => 'actrh@eana.com.ar', 'telephone' => '(011)28296023'],
        'SANT' => ['name' => 'TUCUMAN TENIENTE BENJAMIN MATIENZO', 'email' => 'actuc@eana.com.ar', 'telephone' => '(0381)4260756'],
        'SANU' => ['name' => 'SAN JUAN DOMINGO FAUSTINO SARMIENTO', 'email' => 'acjua@eana.com.ar', 'telephone' => '(0264)4250400'],
        // La fuente se contradice acá: el enlace dice crcu@eana.com.ar y el texto
        // visible acrcu@eana.com.ar. Se toma el visible, que es el que sigue el patrón
        // de las otras cuarenta y seis. Sin confirmar contra el AIP.
        'SAOC' => ['name' => 'RIO CUARTO AREA DE MATERIAL', 'email' => 'acrcu@eana.com.ar', 'telephone' => '(0358)4970883'],
        'SAOD' => ['name' => 'VILLA DOLORES', 'email' => null, 'telephone' => '(0354)4420151'],
        'SAOR' => ['name' => 'VILLA REYNOLDS', 'email' => 'acryd@eana.com.ar', 'telephone' => '(0265)715643973'],
        'SAOS' => ['name' => 'SANTA ROSA DE CONLARA VALLE DEL CONLARA', 'email' => 'acsrc@eana.com.ar', 'telephone' => '(0351)152921338'],
        'SAOU' => ['name' => 'SAN LUIS BRIG. MAYOR D. CESAR RAUL OJEDA', 'email' => 'acuis@eana.com.ar', 'telephone' => '(0266)4423047'],
        'SARC' => ['name' => 'CORRIENTES', 'email' => 'accrr@eana.com.ar', 'telephone' => '(0379)4458844'],
        'SARE' => ['name' => 'RESISTENCIA', 'email' => 'acsis@eana.com.ar', 'telephone' => '(0362)4487758'],
        'SARF' => ['name' => 'FORMOSA', 'email' => 'acfsa@eana.com.ar', 'telephone' => '(0370)4454448'],
        'SARI' => ['name' => 'CATARATAS DEL  IGUAZU MY. D. CARLOS EDUARDO KRAUSE', 'email' => 'acigu@eana.com.ar', 'telephone' => '(03757)420595'],
        'SARL' => ['name' => 'PASO DE LOS LIBRES', 'email' => 'aclib@eana.com.ar', 'telephone' => '(0377)2425528'],
        // La fuente se contradice acá: el enlace dice cpos@eana.com.ar y el texto
        // visible acpos@eana.com.ar. Se toma el visible, que es el que sigue el patrón
        // de las otras cuarenta y seis. Sin confirmar contra el AIP.
        'SARP' => ['name' => 'POSADAS LIBERTADOR GRAL. D. JOSE DE SAN MARTIN', 'email' => 'acpos@eana.com.ar', 'telephone' => '(0376)4451903'],
        'SASA' => ['name' => 'SALTA GENERAL D. MARTIN MIGUEL DE GUEMES', 'email' => 'acsal@eana.com.ar', 'telephone' => '(0387)155993682'],
        'SASJ' => ['name' => 'JUJUY GOBERNADOR GUZMAN', 'email' => 'acjuj@eana.com.ar', 'telephone' => '(0388)4911102'],
        'SATG' => ['name' => 'GOYA', 'email' => 'acgoy@eana.com.ar', 'telephone' => '(0377)7427112'],
        'SATR' => ['name' => 'RECONQUISTA', 'email' => 'acrta@eana.com.ar', 'telephone' => '(011)60067345'],
        'SAVC' => ['name' => 'COMODORO RIVADAVIA GRAL. E. MOSCONI', 'email' => 'accrv@eana.com.ar', 'telephone' => '(02970)4549439'],
        'SAVE' => ['name' => 'ESQUEL BRIGADIER GENERAL ANTONIO PARODI', 'email' => 'acesq@eana.com.ar', 'telephone' => '(0294)5451354'],
        // La fuente se contradice acá: el enlace dice ctre@eana.com.ar y el texto
        // visible actre@eana.com.ar. Se toma el visible, que es el que sigue el patrón
        // de las otras cuarenta y seis. Sin confirmar contra el AIP.
        'SAVT' => ['name' => 'TRELEW ALMIRANTE ZAR', 'email' => 'actre@eana.com.ar', 'telephone' => '(0280)4421009'],
        'SAVV' => ['name' => 'VIEDMA GOBERNADOR CASTELLO', 'email' => 'acvie@eana.com.ar', 'telephone' => '(0292)0424416'],
        'SAVY' => ['name' => 'PUERTO MADRYN EL TEHUELCHE', 'email' => 'acdry@eana.com.ar', 'telephone' => '(0280)4475938'],
        'SAWC' => ['name' => 'EL CALAFATE', 'email' => 'aceca@eana.com.ar', 'telephone' => '(0290)2493702'],
        // La fuente se contradice acá: el enlace dice cgde@eana.com.ar y el texto
        // visible acgde@eana.com.ar. Se toma el visible, que es el que sigue el patrón
        // de las otras cuarenta y seis. Sin confirmar contra el AIP.
        'SAWE' => ['name' => 'RIO GRANDE', 'email' => 'acgde@eana.com.ar', 'telephone' => '(0296)4431340'],
        'SAWG' => ['name' => 'RIO GALLEGOS PILOTO CIVIL NORBERTO FERNANDEZ', 'email' => 'acgal@eana.com.ar', 'telephone' => '(011)23048723'],
        'SAWH' => ['name' => 'USHUAIA MALVINAS ARGENTINAS', 'email' => 'acusu@eana.com.ar', 'telephone' => '(2901)434390'],
        'SAWT' => ['name' => 'EL TURBIO 28 DE NOVIEMBRE', 'email' => null, 'telephone' => '02966231446'],
        'SAWU' => ['name' => 'SANTA CRUZ', 'email' => null, 'telephone' => '02966323651'],
        'SAZB' => ['name' => 'BAHIA BLANCA COMANDANTE ESPORA', 'email' => 'acbca@eana.com.ar', 'telephone' => '(0291)4860300'],
        'SAZF' => ['name' => 'OLAVARRIA', 'email' => null, 'telephone' => '(0228)4445554'],
        'SAZG' => ['name' => 'GENERAL PICO', 'email' => null, 'telephone' => '(0230)2427501'],
        'SAZM' => ['name' => 'MAR DEL PLATA ASTOR PIAZZOLLA', 'email' => 'acmdp@eana.com.ar', 'telephone' => '(0223)5015359'],
        'SAZN' => ['name' => 'NEUQUEN PRESIDENTE PERON', 'email' => 'acneu@eana.com.ar', 'telephone' => '(0299)4440104'],
        'SAZO' => ['name' => 'NECOCHEA', 'email' => null, 'telephone' => '(0226)215496577'],
        'SAZR' => ['name' => 'SANTA ROSA', 'email' => 'acosa@eana.com.ar', 'telephone' => '(0295)4433814'],
        'SAZS' => ['name' => 'SAN CARLOS DE BARILOCHE', 'email' => 'acbar@eana.com.ar', 'telephone' => '(0294)4405030'],
        'SAZT' => ['name' => 'TANDIL HEROES DE MALVINAS', 'email' => 'acdil@eana.com.ar', 'telephone' => '(0249)4440043'],
        'SAZV' => ['name' => 'VILLA GESELL', 'email' => null, 'telephone' => '(0255)15521295'],
        'SAZY' => ['name' => 'SAN MARTIN DE LOS ANDES AVIADOR C. CAMPOS', 'email' => 'acchp@eana.com.ar', 'telephone' => '(0297)2428398'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Area control centres, one per FIR
    |--------------------------------------------------------------------------
    |
    | The codes are the ones `airports.fir` holds, so an aerodrome with no
    | office of its own is looked up by the FIR it already knows it is in.
    |
    */

    'centres' => [
        'SACF' => ['name' => 'ACC CORDOBA', 'email' => null, 'telephone' => '(0351)4335350'],
        'SAEF' => ['name' => 'ACC EZEIZA', 'email' => null, 'telephone' => '(011)44802344'],
        'SAMF' => ['name' => 'ACC MENDOZA', 'email' => null, 'telephone' => '(0261)4488840'],
        'SARR' => ['name' => 'ACC RESISTENCIA', 'email' => null, 'telephone' => '(0362)4440939'],
        'SAVF' => ['name' => 'ACC COMODORO RIVADAVIA', 'email' => null, 'telephone' => '(0297)5374678'],
    ],
];
