<?php
/**
 * Grunddaten (Prüfungen M1/M2/M3) und Demodaten (Testprüfer mit Protokollen).
 * Demodaten sind mit is_demo = 1 markiert und lassen sich im Adminbereich
 * unter „System & Wartung“ mit einem Klick wieder entfernen.
 */
require_once __DIR__ . '/pdf.php';

class Demo
{
    /** Legt die drei Standardprüfungen an, falls noch keine Prüfung existiert. */
    public static function seedExams(): void
    {
        if ((int)DB::val('SELECT COUNT(*) FROM exams') > 0) {
            return;
        }
        $exams = [
            ['Physikum (M1)', 'M1', 'm1', 'Erster Abschnitt der Ärztlichen Prüfung – mündlich-praktischer Teil in Anatomie, Biochemie und Physiologie.', '#2563eb', 10],
            ['Zweiter Abschnitt (M2)', 'M2', 'm2', 'Zweiter Abschnitt der Ärztlichen Prüfung – fallbezogene Prüfung in den klinischen Fächern.', '#7c3aed', 20],
            ['Dritter Abschnitt (M3)', 'M3', 'm3', 'Mündlich-praktische Prüfung nach dem Praktischen Jahr mit Prüfung am Patientenbett (Innere Medizin, Chirurgie, Wahlfach u. a.).', '#0d9488', 30],
        ];
        foreach ($exams as [$name, $short, $slug, $desc, $color, $sort]) {
            DB::insert('exams', [
                'name' => $name, 'short_name' => $short, 'slug' => $slug, 'description' => $desc, 'color' => $color,
                'max_examiners' => 4, 'is_active' => 1, 'sort_order' => $sort, 'created_at' => now(),
            ]);
        }
    }

    public static function exists(): bool
    {
        return (int)DB::val('SELECT COUNT(*) FROM examiners WHERE is_demo = 1') > 0
            || (int)DB::val('SELECT COUNT(*) FROM users WHERE is_demo = 1') > 0;
    }

    /**
     * Legt Testprüfer mit je zwei Protokollen an (das zweite jeweils mit PDF-Anhang).
     * Optional wird ein Demo-Studierendenkonto mit simuliertem Kauf erzeugt.
     * Rückgabe: ['examiners' => n, 'protocols' => n, 'student' => ?['email', 'password']]
     */
    public static function seed(bool $withStudent = true): array
    {
        self::seedExams();
        $examIds = [];
        foreach (DB::all('SELECT id, short_name FROM exams') as $x) {
            $examIds[strtoupper((string)$x['short_name'])] = (int)$x['id'];
        }
        $countEx = 0;
        $countPr = 0;
        $created = [];
        foreach (self::data() as $short => $examiners) {
            if (!isset($examIds[$short])) {
                continue;
            }
            foreach ($examiners as $ex) {
                $eid = DB::insert('examiners', [
                    'exam_id' => $examIds[$short],
                    'name' => $ex['name'],
                    'name_key' => name_key($ex['name']),
                    'subject' => $ex['subject'],
                    'info' => $ex['info'] ?? null,
                    'admin_note' => 'Demo-Prüfer – wird beim Entfernen der Demodaten gelöscht.',
                    'is_active' => 1,
                    'needs_review' => 0,
                    'is_demo' => 1,
                    'created_at' => now(),
                ]);
                $created[$short][] = $eid;
                $countEx++;
                foreach ($ex['protocols'] as $i => $p) {
                    $withPdf = $i === 1;
                    $pid = Protocols::create([
                        'examiner_id' => $eid,
                        'title' => $p['title'],
                        'exam_date' => $p['date'],
                        'grade' => $p['grade'],
                        'content' => $withPdf
                            ? "Dieses Protokoll liegt als eingescanntes Altprotokoll im PDF-Format vor – siehe Datei unten.\n\n**Themen:** " . $p['topics']
                            : $p['text'],
                        'status' => 'published',
                        'source' => 'import',
                        'created_at' => date('Y-m-d H:i:s', strtotime($p['date'] . ' +3 days')),
                    ]);
                    if ($withPdf) {
                        $pdf = SimplePdf::fromText($p['title'], "Prüfer: " . $ex['name'] . " (" . $ex['subject'] . ")\nDatum: " . fmt_date($p['date']) . "\n\n" . $p['text'], 'Demo-Protokoll · ' . setting('site_name'));
                        Protocols::storeContent($pid, $pdf, 'Gedaechtnisprotokoll_' . $p['date'] . '.pdf');
                    }
                    $countPr++;
                }
            }
        }

        $student = null;
        if ($withStudent && isset($created['M1']) && count($created['M1']) >= 2) {
            $domains = Auth::allowedDomains();
            $domain = $domains ? ltrim($domains[0], '*.') : 'example.org';
            $email = 'demo.student@' . $domain;
            if (!DB::val('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) {
                $password = 'Demo-' . random_int(1000, 9999) . '-' . substr(random_token(3), 0, 4);
                $uid = DB::insert('users', [
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'name' => 'Demo Studentin',
                    'role' => 'student',
                    'is_verified' => 1,
                    'is_demo' => 1,
                    'verified_at' => now(),
                    'created_at' => now(),
                ]);
                $pid = DB::insert('purchases', [
                    'user_id' => $uid,
                    'exam_id' => $examIds['M1'],
                    'status' => 'paid',
                    'provider' => 'simulation',
                    'amount_cents' => Settings::int('price_cents'),
                    'currency' => (string)setting('currency', 'eur'),
                    'admin_note' => 'Demo-Kauf (simuliert)',
                    'created_at' => now(),
                    'paid_at' => now(),
                ]);
                DB::insert('purchase_examiners', ['purchase_id' => $pid, 'examiner_id' => $created['M1'][0]]);
                DB::insert('purchase_examiners', ['purchase_id' => $pid, 'examiner_id' => $created['M1'][2] ?? $created['M1'][1]]);
                Settings::set('demo_student', $email . ' / ' . $password);
                $student = ['email' => $email, 'password' => $password];
            }
        }
        log_activity('demo_seeded', $countEx . ' Prüfer, ' . $countPr . ' Protokolle');
        return ['examiners' => $countEx, 'protocols' => $countPr, 'student' => $student];
    }

    /** Entfernt alle Demodaten (Prüfer, deren Protokolle/Dateien, Demo-Konten mit Käufen). */
    public static function remove(): array
    {
        $examinerIds = array_map('intval', DB::col('SELECT id FROM examiners WHERE is_demo = 1'));
        $userIds = array_map('intval', DB::col('SELECT id FROM users WHERE is_demo = 1'));
        $protocols = 0;
        foreach ($examinerIds as $eid) {
            foreach (DB::col('SELECT id FROM protocols WHERE examiner_id = ?', [$eid]) as $pid) {
                Protocols::delete((int)$pid);
                $protocols++;
            }
        }
        if ($userIds) {
            $in = DB::placeholders($userIds);
            DB::q("UPDATE protocols SET refund_purchase_id = NULL WHERE refund_purchase_id IN (SELECT id FROM purchases WHERE user_id IN ($in))", $userIds);
            DB::q("DELETE FROM purchases WHERE user_id IN ($in)", $userIds);
            DB::q("UPDATE protocols SET user_id = NULL WHERE user_id IN ($in)", $userIds);
            DB::q("UPDATE examiners SET created_by = NULL WHERE created_by IN ($in)", $userIds);
            DB::q("DELETE FROM users WHERE id IN ($in)", $userIds);
        }
        if ($examinerIds) {
            $in = DB::placeholders($examinerIds);
            DB::q("DELETE FROM examiners WHERE id IN ($in)", $examinerIds);
        }
        DB::delete('settings', 'name = ?', ['demo_student']);
        Payments::clearCache();
        log_activity('demo_removed', count($examinerIds) . ' Prüfer, ' . $protocols . ' Protokolle, ' . count($userIds) . ' Konten');
        return ['examiners' => count($examinerIds), 'protocols' => $protocols, 'users' => count($userIds)];
    }

    /** Inhalte der Demo-Protokolle. */
    private static function data(): array
    {
        return [
            'M1' => [
                [
                    'name' => 'Prof. Dr. Anna Beispiel', 'subject' => 'Anatomie',
                    'info' => 'Prüft meist am Präparat und legt viel Wert auf klinische Bezüge.',
                    'protocols' => [
                        [
                            'title' => 'Physikum Frühjahr 2025 – obere Extremität', 'date' => '2025-03-18', 'grade' => '2',
                            'topics' => 'Plexus brachialis, N. radialis, Rotatorenmanschette, Histologie Muskulatur',
                            'text' => "## Ablauf\nVier Prüflinge, Prüfung am Präparat (Arm) und am Mikroskop. Pro Person ca. 30 Minuten. Sehr freundliche Atmosphäre, die Prüferin hilft, wenn man hängt.\n\n## Fragen\n- **Plexus brachialis:** Aufbau aus Wurzeln (C5–Th1), Trunci, Fasciculi und die Endäste der Fasciculi. Sie wollte jeden Nerven am Präparat gezeigt bekommen.\n- **Klinik:** obere Plexuslähmung (Erb, C5/C6, „Kellnerhand“) vs. untere Plexuslähmung (Klumpke, C8/Th1, Krallenhand, ggf. Horner-Syndrom).\n- **N. radialis:** Verlauf im Sulcus nervi radialis, typische Läsion bei Humerusschaftfraktur → Fallhand. Welche Muskeln fallen aus?\n- **Rotatorenmanschette:** Mm. supraspinatus, infraspinatus, teres minor, subscapularis – Innervation und Funktion. Was ist ein Impingement?\n- **Histologie:** Skelett- vs. Herzmuskulatur (randständige vs. zentrale Kerne, Glanzstreifen).\n\n## Tipps\nLateinische Begriffe sicher beherrschen und immer einen klinischen Bezug parat haben. Wer selbst Klinik einbringt, punktet."
                        ],
                        [
                            'title' => 'Physikum Herbst 2024 – Kopf und Hals', 'date' => '2024-09-03', 'grade' => '1',
                            'topics' => 'N. facialis, N. laryngeus recurrens, Schilddrüse, Pharyngealbögen',
                            'text' => "## Ablauf\nPrüfung am Kopf-Hals-Präparat, danach ein Histo-Präparat (Schilddrüse).\n\n## Fragen\n- N. facialis: Verlauf vom Kerngebiet bis zum Austritt am Foramen stylomastoideum, Äste in der Glandula parotidea.\n- Zentrale vs. periphere Fazialisparese: Warum bleibt bei der zentralen Lähmung das Stirnrunzeln erhalten?\n- Kehlkopfmuskulatur und Innervation; N. laryngeus recurrens und seine Bedeutung bei Schilddrüsenoperationen (Heiserkeit, bei beidseitiger Läsion Atemnot).\n- Histologie Schilddrüse: Follikel, Kolloid, C-Zellen (Calcitonin).\n- Embryologie: Derivate der Pharyngealbögen (Muskeln, Nerven, Skelettelemente).\n\n## Tipps\nPharyngealbögen unbedingt tabellarisch lernen, das fragt sie fast immer."
                        ],
                    ],
                ],
                [
                    'name' => 'Dr. David Exempel', 'subject' => 'Anatomie',
                    'info' => 'Eher streng, möchte präzise Antworten. Lässt viel zeichnen.',
                    'protocols' => [
                        [
                            'title' => 'Physikum Herbst 2025 – Situs und Bauchwand', 'date' => '2025-09-09', 'grade' => '3',
                            'topics' => 'Peritonealverhältnisse, Pfortader, Leistenkanal, Histologie Leber',
                            'text' => "## Ablauf\nDrei Prüflinge, Situs-Präparat. Er fragt schnell nach und erwartet genaue Begriffe.\n\n## Fragen\n- Intra-, sekundär retro- und primär retroperitoneale Organe aufzählen.\n- Bursa omentalis: Begrenzungen und Zugang über das Foramen omentale (epiploicum).\n- Pfortaderkreislauf und portokavale Anastomosen (Ösophagusvarizen, Caput medusae, rektaler Venenplexus).\n- Leistenkanal: Wände, Inhalt bei Mann und Frau, Unterschied direkte vs. indirekte Leistenhernie (Lage zu den Vasa epigastrica inferiora).\n- Histologie Leber: klassisches Leberläppchen, Periportalfeld (Glisson-Trias).\n\n## Tipps\nDen Leistenkanal an der Tafel zeichnen können! Bei Unsicherheit lieber ehrlich sagen – Raten mag er nicht."
                        ],
                        [
                            'title' => 'Physikum Frühjahr 2024 – Neuroanatomie', 'date' => '2024-03-12', 'grade' => '2',
                            'topics' => 'Capsula interna, Pyramidenbahn, Basalganglien, Liquorsystem',
                            'text' => "## Fragen\n- Capsula interna: Aufbau (Crus anterius, Genu, Crus posterius) und Blutversorgung (Aa. centrales anterolaterales aus der A. cerebri media).\n- Verlauf der Pyramidenbahn vom Gyrus praecentralis bis zur Decussatio pyramidum.\n- Basalganglien: Bestandteile und direkte/indirekte Schleife, Bezug zum Morbus Parkinson.\n- Liquorzirkulation: Plexus choroideus → Seitenventrikel → Foramen interventriculare → III. Ventrikel → Aquaeductus mesencephali → IV. Ventrikel → Aperturae → Subarachnoidalraum → Granulationes arachnoideae.\n- Klinik: Hydrocephalus occlusus vs. communicans.\n\n## Tipps\nSchnittbilder am Hirnpräparat zuordnen üben."
                        ],
                    ],
                ],
                [
                    'name' => 'Prof. Dr. Bernd Muster', 'subject' => 'Biochemie',
                    'info' => 'Fragt gerne Zusammenhänge zwischen Stoffwechselwegen ab, lässt am Whiteboard skizzieren.',
                    'protocols' => [
                        [
                            'title' => 'Physikum Frühjahr 2025 – Kohlenhydratstoffwechsel', 'date' => '2025-03-19', 'grade' => '1',
                            'topics' => 'Glykolyse, Gluconeogenese, Insulin, diabetische Ketoazidose',
                            'text' => "## Ablauf\nGespräch am Whiteboard, sehr ruhige Atmosphäre.\n\n## Fragen\n- Glykolyse: Schrittmacherenzym Phosphofructokinase-1 und seine Regulation (Aktivierung durch Fructose-2,6-bisphosphat und AMP, Hemmung durch ATP und Citrat).\n- Wie steuern Insulin und Glucagon die Bildung von Fructose-2,6-bisphosphat in der Leber?\n- Gluconeogenese: die vier Umgehungsreaktionen (Pyruvatcarboxylase, PEP-Carboxykinase, Fructose-1,6-bisphosphatase, Glucose-6-phosphatase).\n- Cori-Zyklus.\n- Insulin-Signalweg: Rezeptor-Tyrosinkinase, IRS, PI3-Kinase, Akt, GLUT4-Translokation.\n- Diabetische Ketoazidose: Warum entstehen Ketonkörper? Klinische Zeichen (Kussmaul-Atmung, Azetongeruch).\n\n## Tipps\nNicht nur auswendig lernen, sondern erklären können, warum ein Weg wann aktiv ist."
                        ],
                        [
                            'title' => 'Physikum Herbst 2024 – Stickstoff- und Lipidstoffwechsel', 'date' => '2024-09-04', 'grade' => '2',
                            'topics' => 'Harnstoffzyklus, Häm-Synthese, Porphyrien, Cholesterinbiosynthese',
                            'text' => "## Fragen\n- Harnstoffzyklus: Kompartimentierung (Mitochondrium/Zytosol), Carbamoylphosphat-Synthetase I und Aktivierung durch N-Acetylglutamat.\n- Hyperammonämie: Ursachen und Folgen, häufigster angeborener Defekt (Ornithin-Transcarbamylase-Mangel).\n- Häm-Synthese: Schrittmacherenzym δ-Aminolävulinsäure-Synthase (Pyridoxalphosphat als Coenzym), Hemmung durch Blei.\n- Akute intermittierende Porphyrie: Enzymdefekt und typische Symptome (Bauchschmerzen, neurologische Symptome).\n- Cholesterinbiosynthese: HMG-CoA-Reduktase und Wirkmechanismus der Statine (Hochregulation der LDL-Rezeptoren).\n\n## Tipps\nEr fragt gerne nach Coenzymen und Vitaminen – die sollte man parat haben."
                        ],
                    ],
                ],
                [
                    'name' => 'PD Dr. Clara Probe', 'subject' => 'Physiologie',
                    'protocols' => [
                        [
                            'title' => 'Physikum Herbst 2025 – Herz und Kreislauf', 'date' => '2025-09-10', 'grade' => '2',
                            'topics' => 'Herzzyklus, Frank-Starling, EKG, Blutdruckregulation',
                            'text' => "## Ablauf\nZuerst praktischer Teil: Blutdruckmessung nach Riva-Rocci an einem Mitprüfling (Korotkow-Geräusche erklären). Danach Theorie.\n\n## Fragen\n- Phasen des Herzzyklus im Druck-Volumen-Diagramm einzeichnen.\n- Frank-Starling-Mechanismus: Vor- und Nachlast, was passiert bei erhöhter Vorlast?\n- EKG: Einthoven- (bipolar), Goldberger- und Wilson-Ableitungen; Lagetyp bestimmen.\n- Kurzfristige Blutdruckregulation über den Barorezeptorreflex (Sinus caroticus, Aortenbogen), langfristige Regulation über das RAAS.\n\n## Tipps\nDas Druck-Volumen-Diagramm sicher zeichnen können – darauf baut sie die halbe Prüfung auf."
                        ],
                        [
                            'title' => 'Physikum Frühjahr 2025 – Niere, Säure-Basen, Atmung', 'date' => '2025-03-20', 'grade' => '3',
                            'topics' => 'GFR und Clearance, Gegenstromprinzip, ADH, Säure-Basen-Haushalt, Compliance',
                            'text' => "## Fragen\n- GFR: Normwert, Messung über Inulin-Clearance (Goldstandard) vs. Kreatinin-Clearance (warum leicht überschätzt?).\n- Gegenstromprinzip der Henle-Schleife, Rolle des NKCC2 im dicken aufsteigenden Teil.\n- ADH: Wirkort und Mechanismus (V2-Rezeptor, Aquaporin-2).\n- Säure-Basen: metabolische vs. respiratorische Azidose, Kompensationsmechanismen, Anionenlücke berechnen.\n- Atmung: Compliance, anatomischer und funktioneller Totraum.\n\n## Tipps\nBlutgasanalysen interpretieren üben – sie hatte zwei Beispiel-BGAs dabei."
                        ],
                    ],
                ],
            ],
            'M2' => [
                [
                    'name' => 'Prof. Dr. Eva Testfrau', 'subject' => 'Innere Medizin',
                    'protocols' => [
                        [
                            'title' => 'M2 Frühjahr 2025 – Kardiologie', 'date' => '2025-04-08', 'grade' => '2',
                            'topics' => 'Akutes Koronarsyndrom, Differentialdiagnosen Thoraxschmerz, Herzinsuffizienz',
                            'text' => "## Fall\n62-jähriger Patient mit akutem retrosternalem Druckgefühl seit einer Stunde, Ausstrahlung in den linken Arm.\n\n## Fragen\n- Erstes Vorgehen und Diagnostik: 12-Kanal-EKG, hochsensitives Troponin, Vitalparameter.\n- STEMI vs. NSTEMI: EKG-Kriterien, Zeitfenster und Ziel einer möglichst schnellen Koronarintervention.\n- Medikamentöse Therapie: duale Plättchenhemmung, Antikoagulation, Schmerztherapie.\n- Wichtige Differentialdiagnosen: Lungenembolie, Aortendissektion, Pneumothorax, Perikarditis.\n- Herzinsuffizienz mit reduzierter Ejektionsfraktion: NYHA-Stadien und medikamentöse Basistherapie (ACE-Hemmer/ARNI, Betablocker, MRA, SGLT2-Inhibitor).\n\n## Tipps\nStrukturiert vorgehen (Anamnese → Untersuchung → Diagnostik → Therapie). Leitlinien grob kennen."
                        ],
                        [
                            'title' => 'M2 Herbst 2024 – Endokrinologie und Nephrologie', 'date' => '2024-10-15', 'grade' => '1',
                            'topics' => 'Diabetes mellitus Typ 2, Folgeerkrankungen, chronische Niereninsuffizienz',
                            'text' => "## Fragen\n- Diagnosekriterien des Diabetes mellitus (HbA1c, Nüchternglukose, oraler Glukosetoleranztest).\n- Therapie des Typ-2-Diabetes: Lebensstil, Metformin, wann SGLT2-Inhibitoren oder GLP-1-Rezeptoragonisten?\n- Kontraindikationen von Metformin (u. a. stark eingeschränkte Nierenfunktion).\n- Mikro- und makrovaskuläre Folgeerkrankungen, Screening (Augen, Füße, Urin-Albumin).\n- Chronische Niereninsuffizienz: Stadien nach eGFR, Komplikationen (renale Anämie, Hyperkaliämie, sekundärer Hyperparathyreoidismus).\n\n## Tipps\nSie fragt gerne nach Zahlen und Grenzwerten."
                        ],
                    ],
                ],
                [
                    'name' => 'Prof. Dr. Frank Vorlage', 'subject' => 'Chirurgie',
                    'protocols' => [
                        [
                            'title' => 'M2 Frühjahr 2025 – Akutes Abdomen', 'date' => '2025-04-09', 'grade' => '2',
                            'topics' => 'Appendizitis, Cholezystitis vs. Cholangitis, Ileus',
                            'text' => "## Fall\n24-jährige Patientin mit Schmerzen, die vom Nabel in den rechten Unterbauch gewandert sind, Fieber 38,3 °C.\n\n## Fragen\n- Klinische Zeichen der Appendizitis (McBurney- und Lanz-Punkt, Blumberg-, Rovsing- und Psoas-Zeichen).\n- Diagnostik und Differentialdiagnosen bei jungen Frauen (Extrauteringravidität, Adnexitis, Ovarialtorsion).\n- Cholezystitis vs. Cholangitis: Murphy-Zeichen, Charcot-Trias, Reynolds-Pentade.\n- Ileus: mechanisch vs. paralytisch, Auskultationsbefund, Bildgebung, Therapieprinzipien.\n\n## Tipps\nImmer an den Schwangerschaftstest denken – darauf wartet er."
                        ],
                        [
                            'title' => 'M2 Herbst 2024 – Unfallchirurgie', 'date' => '2024-10-16', 'grade' => '3',
                            'topics' => 'Schenkelhalsfraktur, Kompartmentsyndrom, Polytrauma',
                            'text' => "## Fragen\n- Schenkelhalsfraktur: Klassifikationen nach Garden und Pauwels, Klinik (verkürztes, außenrotiertes Bein).\n- Therapie: hüftkopferhaltende Osteosynthese vs. Endoprothese – wovon hängt die Entscheidung ab?\n- Kompartmentsyndrom: Symptome, Diagnostik (Druckmessung), Therapie (Fasziotomie).\n- Polytrauma: Schockraummanagement nach dem ABCDE-Schema.\n\n## Tipps\nKlassifikationen kurz und sicher erklären können."
                        ],
                    ],
                ],
                [
                    'name' => 'Dr. Greta Platzhalter', 'subject' => 'Pharmakologie',
                    'protocols' => [
                        [
                            'title' => 'M2 Frühjahr 2025 – Gerinnung', 'date' => '2025-04-10', 'grade' => '1',
                            'topics' => 'Heparine, HIT, Vitamin-K-Antagonisten, DOAK und Antidote',
                            'text' => "## Fragen\n- Unfraktioniertes vs. niedermolekulares Heparin: Wirkmechanismus, Monitoring (aPTT bzw. Anti-Xa), Antidot Protamin.\n- Heparininduzierte Thrombozytopenie Typ II: Zeitpunkt, Pathomechanismus, Vorgehen (Heparin sofort absetzen, alternatives Antikoagulans).\n- Vitamin-K-Antagonisten: INR-Zielbereich, wichtige Interaktionen, Antagonisierung (Vitamin K, Prothrombinkomplex).\n- Direkte orale Antikoagulanzien: Thrombin- vs. Faktor-Xa-Hemmer und ihre Antidote (Idarucizumab, Andexanet alfa).\n- Thrombozytenaggregationshemmer: ASS (irreversible COX-Hemmung), P2Y12-Antagonisten.\n\n## Tipps\nSehr angenehme Prüferin, fragt viel nach praktischen Konsequenzen („Was machen Sie vor einer OP?“)."
                        ],
                        [
                            'title' => 'M2 Herbst 2024 – Antiinfektiva', 'date' => '2024-10-17', 'grade' => '2',
                            'topics' => 'Penicilline, Makrolide, Fluorchinolone, Vancomycin, MRSA und ESBL',
                            'text' => "## Fragen\n- Betalaktam-Antibiotika: Wirkmechanismus, Betalaktamase-Inhibitoren, Penicillinallergie.\n- Makrolide: Wirkmechanismus, QT-Verlängerung, CYP3A4-Interaktionen.\n- Fluorchinolone: Indikationen und wichtige Nebenwirkungen (Sehnenschäden, QT-Verlängerung).\n- Vancomycin: Spektrum, Nebenwirkungen, orale Gabe bei Clostridioides-difficile-Infektion.\n- Multiresistente Erreger: MRSA und ESBL – welche Substanzen bleiben?\n\n## Tipps\nNebenwirkungen und Interaktionen sind ihr wichtiger als Strukturformeln."
                        ],
                    ],
                ],
            ],
            'M3' => [
                [
                    'name' => 'Prof. Dr. Hannah Demo', 'subject' => 'Innere Medizin',
                    'info' => 'Vorsitzende der Prüfungskommission. Erster Tag am Patientenbett, zweiter Tag Theorie.',
                    'protocols' => [
                        [
                            'title' => 'M3 Herbst 2025 – Herzinsuffizienz und Pneumonie', 'date' => '2025-11-04', 'grade' => '1',
                            'topics' => 'Patientenvorstellung, dekompensierte Herzinsuffizienz, Pneumonie, COPD, Hyponatriämie',
                            'text' => "## Tag 1 – Patientenbett\nPatient am Vortag zugeteilt, Anamnese und körperliche Untersuchung, Bericht schreiben. In der Prüfung strukturiert vorgestellt: dekompensierte Herzinsuffizienz (Beinödeme, feuchte Rasselgeräusche, Halsvenenstauung). Danach Untersuchung einzelner Befunde vor der Kommission.\n\n## Tag 2 – Theorie\n- Ambulant erworbene Pneumonie: CRB-65/CURB-65, Erregerspektrum, kalkulierte Therapie.\n- COPD: Diagnose (FEV1/FVC < 0,7), GOLD-Einteilung, Exazerbationstherapie.\n- Hyponatriämie: Einteilung nach Volumenstatus und Gefahr bei zu schneller Korrektur (osmotische Demyelinisierung).\n\n## Tipps\nDen Bericht gut vorbereiten – die Vorstellung des Patienten ist die halbe Note."
                        ],
                        [
                            'title' => 'M3 Frühjahr 2025 – Gastroenterologie', 'date' => '2025-05-13', 'grade' => '2',
                            'topics' => 'Leberzirrhose und Komplikationen, obere GI-Blutung, Anämie',
                            'text' => "## Fragen\n- Leberzirrhose: Ursachen, Child-Pugh-Klassifikation.\n- Komplikationen: Aszites (diagnostische Punktion, spontan bakterielle Peritonitis), Ösophagusvarizen, hepatische Enzephalopathie (Lactulose, Rifaximin).\n- Akute Varizenblutung: Kreislaufstabilisierung, Terlipressin, Antibiotikaprophylaxe, endoskopische Ligatur.\n- Anämie-Differenzierung nach MCV (mikrozytär, normozytär, makrozytär).\n\n## Tipps\nKlinisch denken: Sie fragt immer „Und was machen Sie jetzt?“."
                        ],
                    ],
                ],
                [
                    'name' => 'Prof. Dr. Ingo Schablone', 'subject' => 'Chirurgie',
                    'protocols' => [
                        [
                            'title' => 'M3 Herbst 2025 – Viszeralchirurgie', 'date' => '2025-11-05', 'grade' => '2',
                            'topics' => 'Kolonkarzinom, Anastomoseninsuffizienz, Hernien',
                            'text' => "## Patient\nPatient nach Hemikolektomie rechts bei Kolonkarzinom, Vorstellung des Verlaufs.\n\n## Fragen\n- Staging des Kolonkarzinoms (TNM, UICC-Stadien) und Indikation zur adjuvanten Chemotherapie.\n- Nachsorge: CEA, Koloskopie, Bildgebung.\n- Anastomoseninsuffizienz: typischer Zeitpunkt, Symptome, Diagnostik und Therapie.\n- Narben- und Leistenhernien: Operationsverfahren im Überblick.\n- Wundheilungsstörungen: Risikofaktoren.\n\n## Tipps\nOP-Abläufe grob erklären können, Details sind ihm weniger wichtig als Komplikationen."
                        ],
                        [
                            'title' => 'M3 Frühjahr 2025 – Endokrine und Gefäßchirurgie', 'date' => '2025-05-14', 'grade' => '2',
                            'topics' => 'Schilddrüsenchirurgie, Schilddrüsenkarzinome, postoperative Hypokalzämie, pAVK',
                            'text' => "## Fragen\n- Indikationen zur Schilddrüsenoperation; Neuromonitoring des N. laryngeus recurrens.\n- Postoperative Hypokalzämie: Ursache, Symptome (Kribbeln, Chvostek- und Trousseau-Zeichen), Therapie.\n- Schilddrüsenkarzinome: papillär, follikulär, medullär (Calcitonin, MEN 2), anaplastisch – Metastasierungsweg und Prognose.\n- pAVK: Stadien nach Fontaine, Knöchel-Arm-Index, konservative und interventionelle Therapie.\n\n## Tipps\nDie Karzinome tabellarisch gegenüberstellen können."
                        ],
                    ],
                ],
                [
                    'name' => 'PD Dr. Julia Entwurf', 'subject' => 'Pädiatrie (Wahlfach)',
                    'protocols' => [
                        [
                            'title' => 'M3 Herbst 2025 – Fieber im Säuglingsalter', 'date' => '2025-11-04', 'grade' => '1',
                            'topics' => 'Fieber ohne Fokus, Meningitis beim Säugling, Fieberkrampf, Impfungen',
                            'text' => "## Patient\nSäugling mit Fieber, Vorstellung zusammen mit den Eltern.\n\n## Fragen\n- Fieber ohne Fokus bei Säuglingen unter 3 Monaten: Warum stationär? Welche Diagnostik (Blutbild, CRP, Blutkultur, Urin, ggf. Lumbalpunktion)?\n- Meningitiszeichen beim Säugling (gespannte Fontanelle, Trinkschwäche, Irritabilität – Meningismus fehlt oft).\n- Einfacher vs. komplizierter Fieberkrampf.\n- Vorsorgeuntersuchungen (U-Untersuchungen) und Impfungen nach STIKO im ersten Lebensjahr.\n\n## Tipps\nSehr wertschätzende Prüferin. Kommunikation mit den Eltern wird mitbewertet."
                        ],
                        [
                            'title' => 'M3 Frühjahr 2025 – Exantheme und Neugeborene', 'date' => '2025-05-13', 'grade' => '2',
                            'topics' => 'Kinderkrankheiten mit Exanthem, Kawasaki-Syndrom, Neugeborenenikterus',
                            'text' => "## Fragen\n- Exanthematische Kinderkrankheiten unterscheiden: Masern (Koplik-Flecken), Röteln, Ringelröteln (Parvovirus B19), Dreitagefieber (HHV-6, Exanthem nach Entfieberung), Scharlach (Himbeerzunge).\n- Kawasaki-Syndrom: Diagnosekriterien, gefürchtete Komplikation (Koronaraneurysmen), Therapie.\n- Neugeborenenikterus: physiologisch vs. pathologisch (z. B. Ikterus in den ersten 24 Lebensstunden), Phototherapie nach Nomogramm, Gefahr des Kernikterus.\n\n## Tipps\nBilder von Exanthemen anschauen – sie zeigt Fotos."
                        ],
                    ],
                ],
                [
                    'name' => 'Dr. Klaus Modell', 'subject' => 'Allgemeinmedizin',
                    'protocols' => [
                        [
                            'title' => 'M3 Herbst 2025 – Schwindel beim älteren Patienten', 'date' => '2025-11-05', 'grade' => '2',
                            'topics' => 'Schwindel, BPLS, Polypharmazie, Hypertonie',
                            'text' => "## Fall\n78-jähriger Patient mit wiederkehrendem Schwindel, nimmt neun verschiedene Medikamente.\n\n## Fragen\n- Differentialdiagnosen Schwindel (vestibulär, kardiovaskulär, medikamentös, orthostatisch).\n- Benigner paroxysmaler Lagerungsschwindel: Dix-Hallpike-Manöver, Therapie mit Befreiungsmanövern.\n- Polypharmazie im Alter, PRISCUS-Liste, Sturzrisiko.\n- Hypertonie im Alter: Zielwerte und Medikamentenwahl.\n\n## Tipps\nPraxisnah antworten: Was kann man in der Hausarztpraxis selbst klären, wann wird überwiesen?"
                        ],
                        [
                            'title' => 'M3 Frühjahr 2025 – Rückenschmerz und Prävention', 'date' => '2025-05-14', 'grade' => '1',
                            'topics' => 'Red Flags beim Rückenschmerz, Kennmuskeln, Prävention und Früherkennung',
                            'text' => "## Fragen\n- Red Flags beim Rückenschmerz (Trauma, Tumoranamnese, Fieber, Gewichtsverlust, Cauda-equina-Symptome, progrediente Paresen).\n- Bandscheibenvorfall: Kennmuskeln und Reflexe für L4, L5 und S1.\n- Wann Bildgebung, wann konservativ? Arbeitsunfähigkeit und Rückkehr in den Alltag.\n- Prävention: Gesundheits-Check-up, Krebsfrüherkennung, Impfungen im Erwachsenenalter.\n\n## Tipps\nEr mag es, wenn man Leitlinien der DEGAM erwähnt."
                        ],
                    ],
                ],
            ],
        ];
    }
}
