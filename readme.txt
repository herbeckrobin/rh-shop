=== RH Shop ===
Contributors: robinherbeck
Tags: shop, ecommerce, stripe, products
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Schlanker Shop für kleine Sortimente: Katalog in WordPress, Zahlung über Stripe. Weniger Ballast als WooCommerce, Pflege in Minuten.

== Description ==

RH Shop ist der leichte Weg, ein kleines Sortiment (Merch, ein paar Produkte) zu verkaufen. Der Katalog lebt als Produkt-CPT in WordPress, gepflegt im gewohnten Editor. Die Zahlung läuft über Stripe. Kein aufgeblähtes Shop-System, nur was ein kleiner Shop wirklich braucht.

Teil der rh-blueprint Kollektion.

== Changelog ==

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
