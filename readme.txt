=== RH Shop ===
Contributors: robinherbeck
Tags: shop, ecommerce, stripe, products
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.7.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Schlanker Shop für kleine Sortimente: Katalog in WordPress, Zahlung über Stripe. Weniger Ballast als WooCommerce, Pflege in Minuten.

== Description ==

RH Shop ist der leichte Weg, ein kleines Sortiment (Merch, ein paar Produkte) zu verkaufen. Der Katalog lebt als Produkt-CPT in WordPress, gepflegt im gewohnten Editor. Die Zahlung läuft über Stripe. Kein aufgeblähtes Shop-System, nur was ein kleiner Shop wirklich braucht.

Teil der rh-blueprint Kollektion.

== Changelog ==

= 0.7.0 =
* Shop-Übersicht ausgebaut: die vier Kacheln zeigen jetzt mehr als die nackte Zahl. Offene Bestellungen listen die am längsten wartenden (was als Nächstes rausgeht), der Umsatz ein Balkendiagramm der umsatzstärksten Produkte, die Bestellungen eine Verlaufsgrafik (Woche/Monat/Jahr umschaltbar, durch den Kalender blätterbar) mit Trend-Pfeil gegen den Zeitraum davor, und die Produkte den Lager-Status mit den knappsten Varianten.
* Im Produkt-Editor zeigt das Bestand-Feld jetzt live einen Status: ausverkauft, knapp, auf Lager oder nicht verfolgt, direkt beim Tippen. So ist auf einen Blick klar, was Sache ist, statt nur der eingetragenen Zahl.
* Fehler behoben: In der Varianten-Tabelle ließen sich keine neuen Varianten hinzufügen, der Knopf tat nichts.
* Fehler behoben: Der "Zur Kasse"-Knopf im Warenkorb-Overlay war unlesbar (dunkle Schrift auf dunklem Grund).

= 0.6.1 =
* Sicherheits-Update (empfohlen). Wichtigste Behebung: In einem Sonderfall (zwei Produkte ohne Varianten in derselben Bestellung) konnte der Überverkaufs-Schutz umgangen werden, das ist behoben.
* Schutz gegen automatisierten Missbrauch: Die Kasse und der Widerruf sind jetzt gegen massenhafte Anfragen gedrosselt (ein einzelner Bot kann so nicht mehr den Bestand blockieren oder über das Widerrufsformular Mails verschicken). Die Widerruf-Eingangsbestätigung geht nur noch an eine zur Bestellung passende E-Mail-Adresse.
* Weitere Härtung: Der Bezahlbetrag wird beim Zahlungseingang gegen den Bestellbetrag geprüft, interne Dateien geben bei direktem Aufruf nichts mehr preis, und die Bestand-Tabellen laufen garantiert auf einer transaktionssicheren Engine.
* WICHTIG beim Update: Eine kleine Datenbank-Anpassung läuft beim ersten Aufruf nach dem Update automatisch, du musst nichts tun.

= 0.6.0 =
* Schutz gegen Überverkauf bei gleichzeitigem Zugriff: Beim Auslösen der Bestellung wird der Bestand reserviert, bevor die Zahlung startet. So können nicht zwei Kunden denselben letzten Artikel kaufen. Reicht der Bestand nicht, bricht die Kasse mit einem klaren Hinweis ab, es wird nichts belastet.
* Die Produktseite zeigt jetzt den wirklich verfügbaren Bestand (abzüglich laufender Reservierungen): ein reservierter letzter Artikel erscheint sofort als vergriffen.
* Bleibt eine Zahlung aus, wird der reservierte Bestand nach einer einstellbaren Frist automatisch wieder frei (Einstellungen → Preise & Steuer, Standard 30 Minuten), und die unbezahlte Bestellung wird storniert.
* WICHTIG beim Update: Der Bestand zieht aus den Produktdaten in eine eigene Tabelle um (Voraussetzung für die zuverlässige Reservierung). Das passiert beim ersten Aufruf nach dem Update automatisch, du musst nichts tun.
* Kasse und Warenkorb behandeln Fehler jetzt einheitlich: Ladeanzeigen an jeder wartenden Aktion, klare Meldung statt stillem Fehlschlag, keine internen Zahlungs-Fehlertexte mehr nach außen.

= 0.5.0 =
* Neuer Block „Warenkorb-Widget" für die Navigation: ein Warenkorb-Symbol (Tasche, Wagen oder Korb) oder ein Wort mit Anzahl-Badge. Ein Klick öffnet ein Overlay (Drawer von rechts) mit dem Warenkorb, Mengen änderbar, direkt zur Kasse.
* Das Overlay öffnet sich automatisch beim In-den-Warenkorb-Legen (abschaltbar) und bleibt mit der Warenkorb-Seite synchron.
* Einstellungen im Block: Anzeige (Symbol/Wort/beides), Symbol, Wort, Anzahl-Badge an/aus, Badge bei 0 verstecken, beim Hinzufügen öffnen.
* Das Overlay ist gegen Theme-Styles isoliert (eigener Namespace, Reset, an den Seitenkörper gehängt), damit es in jedem Theme sauber aussieht. Ohne JavaScript führt der Trigger als normaler Link zur Warenkorb-Seite.

= 0.4.0 =
* Bestell-Detailansicht im Admin: ein Klick auf die Bestellnummer öffnet Kunde, Lieferadresse, Positionen mit Summen, den Rechnungslink und die Stripe-Zahlungsreferenz. Der Status lässt sich direkt dort setzen.
* Bestand als ein Mechanismus überall: Kauf-Box und Warenkorb deckeln die Menge am verfügbaren Bestand und melden es („Nur noch X verfügbar, die Menge wurde angepasst").
* Die Varianten-Auswahl zeigt den Bestand pro Option, ausverkaufte Varianten sind nicht mehr wählbar, knappe sind markiert.
* Vor der Auswahl sichtbar: eine Zusammenfassungszeile auf der Produktseite („Einige Varianten nur noch X Stück verfügbar") und ein „Fast ausverkauft"-Badge auf der Produktübersicht.
* Neue Einstellung „Lager-Warnung ab" (Preise & Steuer): ab welchem Restbestand der Hinweis erscheint (Standard 5, 0 schaltet ihn ab).

= 0.3.0 =
* Kasse auf das Stripe Payment Element umgestellt: die Zahlung ist jetzt in die Seite integriert und im Design des Themes (Schrift, Farben), statt einer eingebetteten Stripe-Kasse mit doppelter Übersicht. Adresse und Zahlart als getheme'te Stripe-Elemente, unser Layout und der „Zahlungspflichtig bestellen"-Button bleiben unsere.
* WICHTIG beim Update: Der Webhook hört jetzt auf „payment_intent.succeeded" statt „checkout.session.completed". Nach dem Update einmal unter Einstellungen → Status den Webhook neu einrichten, sonst werden Bestellungen nicht automatisch auf bezahlt gesetzt.
* Kasse und Warenkorb in getrennte Blöcke aufgeteilt (Übersicht/Formular bzw. Positionen/Summe), das Layout bestimmst du frei über Core-Spalten (z.B. zweispaltig).
* Neuer Landing-Screen „Shop → Übersicht": Bestellungen, Umsatz, Produkte und „Zu erledigen" auf einen Blick, plus Schnellaktionen und deine Shop-Seiten.
* Einstellungs-Seite in Tabs geordnet (Status, Zahlung, Preise & Steuer, Versand, E-Mail, Rechtliches) mit Cross-Links und einem Startklar-Status.
* Bestellstatus im Admin setzbar; beim Wechsel auf „versendet" bekommt der Kunde automatisch eine Versandbestätigung (optional mit Sendungsnummer).
* E-Mail-Einstellungen: Absender, Benachrichtigungs-Adresse für neue Bestellungen, optionaler Zusatztext in der Bestätigungsmail.
* Gratisversand ab einem konfigurierbaren Warenwert.
* Editor: Warenkorb, Kasse, Kauf-Box und Widerruf zeigen eine Beispielansicht mit echten Produkten statt leerer Platzhalter.
* Aktivierungs-Hinweis „Shop einrichten", native Hilfe-Register auf den Shop-Seiten, Loading-Anzeigen (Skeleton/Spinner) an den wartenden Stellen.

= 0.2.0 =
* Danke-Seite überarbeitet: zeigt den echten Zahlungsstatus, eine Bestellübersicht mit Positionen und Summen und lädt den Rechnungslink automatisch nach (kein Neuladen). Styling passt sich hellen wie dunklen Themes an.
* Produkte bekommen ein eigenes Einzelseiten-Template mit Kauf-Box-Block und Merkmal-Liste, im Site-Editor frei anpassbar. Kein Blog-Autor mehr unter dem Titel.
* Shop-Blöcke begrenzen sich selbst auf die Inhaltsbreite, auch in Themes mit voller Breite.
* Grundpreis-Einheiten (g, kg, ml, l, cm, m, m²) an einer Stelle gepflegt, konsistent in Editor und Frontend.
* Varianten-Achsen je Produkt frei benennbar (statt fest "Größe"/"Farbe"), die Auswahl im Shop übernimmt die Namen.
* Steuersatz in den Einstellungen wählbar (Standard 19 %), statt fest verdrahtet.
* Unit-Tests für die Preis-, Grundpreis- und Steuer-Rechnung, laufen als Gate bei jedem Release.

= 0.1.0 =
* Produkt-CPT mit Kategorien und Varianten (Größe, Farbe, SKU, Preis, Bestand) als Post-Meta, gepflegt im gewohnten Editor.
* Blocks: Produktraster, Einzelprodukt, Warenkorb, Kasse. Warenkorb als Cookie (kein Login), Preise werden immer serverseitig gerechnet.
* Kasse nach § 312j: Preisaufschlüsselung und Pflicht-Checkboxen vor dem "Zahlungspflichtig bestellen"-Button, danach eingebettete Stripe-Zahlung ohne Weiterleitung.
* Stripe-Webhook (per Klick automatisch einrichtbar) bestätigt die Zahlung serverseitig, bucht den Bestand und verschickt die Bestätigungsmails.
* Rechnung nach der Zahlung über Stripe Invoicing (optional).
* Bestell-Übersicht im Admin.
* PAngV: MwSt-Hinweis (inkl. MwSt. bzw. § 19 bei Kleinunternehmer), verlinkte Versandkosten, Grundpreis je Variante.
* Rechts-Layer: § 356a Widerrufsbutton, AGB-Zustimmung optional, amtliches Muster-Widerrufsformular, Pflichtinfos in der Bestätigungsmail, Go-Live-Check im Admin.
* Steuer wählbar: Regelbesteuerung (19 %) oder Kleinunternehmer (§ 19 UStG).
* Legt bei der Aktivierung die Seiten "Versand" und "Vielen Dank" an, die Danke-Seite zeigt den echten Zahlungsstatus.
